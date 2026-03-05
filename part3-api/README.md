# Part 3: API Integration - ERSE Synchronization

## 🌍 Objetivo

Crear un módulo de integración que sincronice contratos de electricidad con la API de ERSE (Entidade Reguladora dos Serviços Energéticos - regulador português). FactorEnergia está expandiendo a Portugal y debe registrar automáticamente cada contrato con la autoridad reguladora.

---

## 📋 Contexto del Problema

### Escenario
- FactorEnergia tiene contratos en múltiples países (España, Portugal, etc)
- **Solo contratos portugueses** deben sincronizarse con ERSE
- ERSE expone una API REST que valida y registra contratos
- El sistema debe auditar cada intento de sincronización
- Necesita manejar errores de red, duplicados, validaciones fallidas, etc

### Requerimientos Funcionales
1. ✅ Aceptar solicitud HTTP: `POST /api/contracts/sync`
2. ✅ Cargar datos del contrato desde base de datos
3. ✅ Transformar formato interno → formato ERSE
4. ✅ Llamar API externa con autenticación
5. ✅ Registrar resultado (éxito/fallo) para auditoría
6. ✅ Solo permitir sincronización de contratos portugueses
7. ✅ Manejar errores gracefully

---

## 🏗️ Arquitectura de la Solución

```
┌─────────────────────────────────┐
│   HTTP POST /api/contracts/sync │
└────────────────┬────────────────┘
                 ↓
        ┌────────────────────┐
        │ ContractSyncCtrl   │  ← Validación HTTP, binding
        └────────────┬───────┘
                     ↓
        ┌────────────────────────────┐
        │    ErseSyncService         │  ← Orquestación principal
        │  - createSync()            │
        │  - validateContract()      │
        │  - buildErsPayload()       │
        │  - callErsApi()            │
        │  - updateSyncStatus()      │
        └────────────┬───────────────┘
                     ↓
    ┌────────────────┴─────────────────┐
    ↓                                   ↓
┌──────────────────┐      ┌────────────────────────┐
│  Repositories    │      │  External Integrations │
│                  │      │                        │
│ContractRepository│      │ EnergyMarketApiClient  │
│ContractSyncRepo  │      │ (Symfony HttpClient)   │
└──────────────────┘      └────────────────────────┘
    ↓
 ┌──────────────────┐
 │   Database       │
 │ - Contracts      │
 │ - ContractSync   │
 └──────────────────┘
```

---

## 🗄️ Modelo de Datos

### Tabla: `contracts` (existente, extendida)
```sql
CREATE TABLE contracts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_name VARCHAR(255),
    address VARCHAR(255),
    supply_point_code VARCHAR(50),  -- CUPS (Código de Punto de Suministro)
    tariff_id INT FOREIGN KEY,
    start_date DATE,
    country VARCHAR(2),  -- ES, PT, etc
    -- Nuevos campos para ERSE
    nif VARCHAR(20),  -- Número de Identificación Fiscal
    estimated_kwh INT,
    status VARCHAR(50),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Tabla Nueva: `contract_sync` (Auditoría)
```sql
CREATE TABLE contract_sync (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contract_id INT NOT NULL FOREIGN KEY,
    status ENUM('pending', 'success', 'failed') DEFAULT 'pending',
    erse_external_id VARCHAR(100),  -- ID asignado por ERSE
    erse_response JSON,  -- Response completo de ERSE (para debugging)
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pending (contract_id, status)  -- Evita duplicados
);
```

### Entity: `ContractSync`
```php
class ContractSync {
    private int $id;
    private int $contractId;
    private string $status;  // pending | success | failed
    private ?string $erseExternalId;
    private ?array $erseResponse;
    private ?string $errorMessage;
    private DateTime $createdAt;
    private DateTime $updatedAt;
    
    // Getters/Setters...
}
```

---

## 🔌 Componentes Principales

### 1. **Controller** - Exposición HTTP
```php
// Ruta: POST /api/contracts/sync
class ContractSyncController {
    public function sync(Request $request): Response {
        // Validar entrada
        $contractId = (int) $request->get('contract_id');
        
        if ($contractId <= 0) {
            return new JsonResponse(['error' => 'Invalid contract_id'], 400);
        }
        
        try {
            // Delegar a servicio
            $result = $this->service->syncContract($contractId);
            
            return new JsonResponse([
                'status' => 'success',
                'sync_id' => $result->getId(),
                'erse_id' => $result->getErseExternalId(),
            ], 201);
            
        } catch (ContractNotFoundException $e) {
            return new JsonResponse(['error' => 'Contract not found'], 404);
        } catch (ValidationException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            return new JsonResponse(['error' => 'Sync failed'], 500);
        }
    }
}
```

---

### 2. **Service** - Lógica de Sincronización
```php
class ErseSyncService {
    public function syncContract(int $contractId): ContractSync {
        // 1. Crear registro de auditoría inicial
        $sync = new ContractSync();
        $sync->setContractId($contractId);
        $sync->setStatus('pending');
        $this->syncRepository->save($sync);
        
        try {
            // 2. Validar y cargar contrato
            $contract = $this->contractRepository->findById($contractId);
            
            if (!$contract) {
                throw new ContractNotFoundException("Contract $contractId not found");
            }
            
            // 3. Validar que sea portugués
            if ($contract->getCountry() !== 'PT') {
                throw new ValidationException(
                    "Contract must be from Portugal (country='PT')"
                );
            }
            
            // 4. Construir payload ERSE
            $payload = $this->buildErsePayload($contract);
            
            // 5. Llamar API externa
            $response = $this->apiClient->registerContract($payload);
            
            // 6. Procesar respuesta
            $erseId = $response['contract_id'] ?? null;
            $sync->setStatus('success');
            $sync->setErseExternalId($erseId);
            $sync->setErseResponse($response);
            
            // 7. Guardar en BD
            $this->syncRepository->save($sync);
            
            $this->logger->info(
                "Contract synced successfully",
                ['contract_id' => $contractId, 'erse_id' => $erseId]
            );
            
            return $sync;
            
        } catch (ValidationException | ContractNotFoundException $e) {
            // Error esperado - registrar como fallo
            $sync->setStatus('failed');
            $sync->setErrorMessage($e->getMessage());
            $this->syncRepository->save($sync);
            
            $this->logger->warning("Sync validation error: " . $e->getMessage());
            throw $e;
            
        } catch (ExternalApiException $e) {
            // Error de API - retry más tarde
            $sync->setStatus('failed');
            $sync->setErrorMessage($e->getMessage());
            $this->syncRepository->save($sync);
            
            $this->logger->error("ERSE API error: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function buildErsePayload(Contract $contract): array {
        return [
            'nif' => $contract->getNif(),
            'cups' => $contract->getSupplyPointCode(),
            'customer_name' => $contract->getCustomerName(),
            'address' => $contract->getAddress(),
            'start_date' => $contract->getStartDate()->format('Y-m-d'),
            'estimated_annual_kwh' => $contract->getEstimatedKwh(),
            'tariff_code' => $contract->getTariff()->getCode(),
        ];
    }
}
```

---

### 3. **API Client** - Integración Externa
```php
class EnergyMarketApiClient {
    private HttpClientInterface $client;
    private string $erseUrl;
    private string $erseToken;
    
    public function __construct(HttpClientInterface $client) {
        $this->client = $client;
        $this->erseUrl = $_ENV['ERSE_URL'];  // https://api.erse.pt/v1
        $this->erseToken = $_ENV['ERSE_TOKEN'];  // Bearer token
    }
    
    /**
     * Registra un contrato en ERSE
     * 
     * @throws ExternalApiException
     */
    public function registerContract(array $payload): array {
        try {
            $response = $this->client->request('POST', 
                $this->erseUrl . '/contracts/register',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->erseToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => 10,  // Timeout para evitar bloqueos
                ]
            );
            
            $statusCode = $response->getStatusCode();
            $content = $response->toArray();
            
            // Manejar diferentes códigos HTTP
            if ($statusCode === 201) {
                // ✅ Éxito
                return $content;
                
            } elseif ($statusCode === 400) {
                // ❌ Validación falló
                throw new ValidationException(
                    "ERSE validation failed: " . $content['message'] ?? unknokn
                );
                
            } elseif ($statusCode === 409) {
                // ❌ Contrato duplicado
                throw new DuplicateContractException(
                    "Contract already registered with ERSE"
                );
                
            } else {
                // ❌ Error desconocido
                throw new ExternalApiException(
                    "ERSE API error: " . $statusCode
                );
            }
            
        } catch (TransportExceptionInterface $e) {
            // ❌ Error de red
            throw new ExternalApiException(
                "Network error calling ERSE: " . $e->getMessage(),
                previous: $e
            );
        }
    }
}
```

---

### 4. **Repository** - Auditoría
```php
class ContractSyncRepository {
    public function save(ContractSync $sync): void {
        $sql = <<<SQL
            INSERT INTO contract_sync 
                (contract_id, status, erse_external_id, erse_response, error_message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                status = ?, 
                erse_external_id = ?,
                erse_response = ?,
                error_message = ?,
                updated_at = NOW()
        SQL;
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            $sync->getContractId(),
            $sync->getStatus(),
            $sync->getErseExternalId(),
            json_encode($sync->getErseResponse()),
            $sync->getErrorMessage(),
            // Valores para UPDATE
            $sync->getStatus(),
            $sync->getErseExternalId(),
            json_encode($sync->getErseResponse()),
            $sync->getErrorMessage(),
        ]);
    }
    
    public function findPendingByContractId(int $contractId): ?ContractSync {
        $sql = "SELECT * FROM contract_sync 
                WHERE contract_id = ? AND status = 'pending'
                ORDER BY created_at DESC LIMIT 1";
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$contractId]);
        
        $row = $stmt->fetch();
        return $row ? $this->mapToEntity($row) : null;
    }
}
```

---

## 🛡️ Manejo de Casos Edge

### Caso 1: Contrato Duplicado
**Escenario:** Se intenta sincronizar un contrato ya registrado en ERSE

**Solución:**
```php
// BD tiene constraint UNIQUE (contract_id, status='pending')
// Esto previene que se creen múltiples "pending" para el mismo contrato

// En API, si retorna 409 Conflict:
catch (DuplicateContractException $e) {
    $sync->setStatus('failed');
    $sync->setErrorMessage('Already registered with ERSE');
    // No intentar retry automático
}
```

---

### Caso 2: Outage de ERSE
**Escenario:** API de ERSE no disponible

**Solución con Background Job:**
```php
// En controller
try {
    $sync = $this->service->syncContract($contractId);
} catch (ExternalApiException $e) {
    // API no disponible - queue para retry posterior
    $this->queue->publish(new SyncContractMessage($contractId));
    return new JsonResponse(['status' => 'queued'], 202);
}

// Background worker (ejecuta cada 5 minutos)
class RetryFailedSyncsCommand extends Command {
    public function execute(): int {
        $pendingSyncs = $this->syncRepository->findAllPending();
        
        foreach ($pendingSyncs as $sync) {
            try {
                $this->service->retrySync($sync->getContractId());
            } catch (Exception $e) {
                $this->logger->error("Retry failed: " . $e->getMessage());
            }
        }
        
        return 0;
    }
}
```

---

### Caso 3: Validación de Contrato Portuguesa
**Escenario:** Intentan sincronizar contrato de España

**Solución:**
```php
if ($contract->getCountry() !== 'PT') {
    throw new ValidationException(
        "Only Portuguese contracts can be synced to ERSE. " .
        "This contract is from: " . $contract->getCountry()
    );
}
```

Retorna HTTP 400 (Bad Request) - no es error del sistema, es validación.

---

## 🧪 Testing

### Unit Test - Service
```php
class ErseSyncServiceTest extends TestCase {
    public function testSyncPortugueseContract(): void {
        // Arrange
        $contract = new Contract(['country' => 'PT', 'nif' => '123456789']);
        $contractRepo = new FakeContractRepository();
        $contractRepo->add($contract);
        
        $apiClient = new FakeErseSyncApiClient();
        $apiClient->setResponse(['contract_id' => 'ERSE-001']);
        
        $service = new ErseSyncService($contractRepo, $apiClient, ...);
        
        // Act
        $sync = $service->syncContract($contract->getId());
        
        // Assert
        $this->assertEquals('success', $sync->getStatus());
        $this->assertEquals('ERSE-001', $sync->getErseExternalId());
    }
    
    public function testFailsForNonPortugueseContract(): void {
        // Arrange
        $contract = new Contract(['country' => 'ES']);  // España, no Portugal
        
        // Act & Assert
        $this->expectException(ValidationException::class);
        $service->syncContract($contract->getId());
    }
}
```

---

## 📂 Estructura de Archivos

```
part3-api/
├── README.md                          ← Estás aquí
├── Controller/
│   └── ContractSyncController.php    (HTTP endpoint)
├── Entity/
│   └── ContractSync.php              (Modelo de auditoría)
├── Repository/
│   └── ContractSyncRepository.php    (Persistencia)
├── Service/
│   ├── ErseSyncService.php           (Orquestación)
│   └── EnergyMarketApiClient.php    (API client)
└── Tests/
    └── ErseSyncServiceTest.php       (Unit tests)
```

---

## 🔧 Configuración

### Variables de Entorno
```bash
# .env.production
ERSE_URL=https://api.erse.pt/v1
ERSE_TOKEN=Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
ERSE_TIMEOUT=10
```

### Parámetros Symfony
```yaml
# services.yaml
services:
  App\Service\EnergyMarketApiClient:
    arguments:
      $erseUrl: '%env(ERSE_URL)%'
      $erseToken: '%env(ERSE_TOKEN)%'
```

---

## 📊 Flujo Completo de Sincronización

```
1. POST /api/contracts/sync { contract_id: 123 }
   ↓
2. ContractSyncController valida entrada
   ↓
3. ErseSyncService:
   - Crea registro ContractSync (status=pending)
   - Valida contrato existe y es portugués
   - Construye payload ERSE
   ↓
4. EnergyMarketApiClient:
   - Autentica con bearer token
   - Llama POST https://api.erse.pt/v1/contracts/register
   - Maneja respuesta HTTP
   ↓
5. ErseSyncService:
   - Si 201 → status=success, guarda erse_external_id
   - Si 400 → status=failed, error validation
   - Si 409 → status=failed, error duplicate
   - Si timeout → status=failed, queue retry
   ↓
6. Retorna JSON response al cliente
```

---

## ✨ Características de la Solución

| Aspecto | Implementación |
|---------|---|
| **Autenticación** | Bearer token en env vars |
| **Validación** | País, estructura del contrato |
| **Auditoría** | Cada intento guardado en BD |
| **Retry** | Background job para failed syncs |
| **Timeout** | 10 segundos para prevenir bloqueos |
| **Errors** | Excepciones custom y logging |
| **Testing** | Fakes para API externe y repositorio |

---

## 🚀 Cómo Usar

1. **Sincronizar contrato:**
   ```bash
   curl -X POST http://localhost:8000/api/contracts/sync \
        -H "Content-Type: application/json" \
        -d '{"contract_id": 123}'
   ```

2. **Ver estado de sincronización:**
   ```sql
   SELECT * FROM contract_sync WHERE contract_id = 123;
   ```

3. **Retry de sincronizaciones fallidas:**
   ```bash
   symfony console app:retry-failed-syncs
   ```

---

## 📞 Preguntas Frecuentes

**P: ¿Qué pasa si ERSE no responde?**
R: Se guarda status=failed y se queue para retry posterior automático.

**P: ¿Se pueden sincronizar contratos no portugueses?**
R: No. Se valida country='PT' y se lanza ValidationException.

**P: ¿Dónde se almacenan las credenciales de ERSE?**
R: En variables de entorno (`.env.production`), nunca hardcoded en código.

**P: ¿Se puede sincronizar el mismo contrato dos veces?**
R: Solo una vez. El constraint UNIQUE en BD lo previene.
