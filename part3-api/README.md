# Part 3: API Integration - ERSE Synchronization

## 🎯 Objetivo

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

📄 Ver: [Controller/ContractSyncController.php](Controller/ContractSyncController.php)

**Función:** Expone el endpoint HTTP `POST /api/contracts/sync` que:
- Valida que `contract_id` esté presente en el request body
- Delega la sincronización al servicio `ErseSyncService`
- Maneja excepciones y retorna códigos HTTP apropiados:
  - `201 Created` → sincronización exitosa
  - `400 Bad Request` → errores de validación
  - `404 Not Found` → contrato no existe
  - `500 Internal Server Error` → errores inesperados

---

### 2. **Service** - Lógica de Sincronización

📄 Ver: [Service/ErseSyncService.php](Service/ErseSyncService.php)

**Función:** Orquesta el proceso completo de sincronización:
1. Crea un registro inicial `ContractSync` con status `pending` para auditoría
2. Carga el contrato desde BD y valida que exista
3. Verifica que sea un contrato portugués (`country='PT'`)
4. Construye el payload en formato ERSE (NIF, CUPS, dirección, tarifa, etc)
5. Llama a la API externa de ERSE
6. Procesa la respuesta y actualiza el registro de auditoría con el resultado
7. Maneja excepciones:
   - `ContractNotFoundException` → registra como "failed" y relanza
   - `ExternalApiException` → registra como "failed" para retry posterior
8. Registra todo con logging para trazabilidad

---

### 3. **API Client** - Integración Externa

📄 Ver: [Service/ErseSyncService.php](Service/ErseSyncService.php) (método `syncContract`)

**Función:** Realiza los siguientes pasos de la integración con ERSE:
- Autentica las requests con Bearer token (de variables de entorno)
- Construye y envía el payload JSON a `POST {ERSE_URL}/contracts` con timeout de 10 segundos
- Maneja diferentes códigos de respuesta HTTP:
  - `201 Created` → Éxito, extrae ID externo de ERSE
  - `400 Bad Request` → Error de validación de datos
  - `409 Conflict` → Contrato ya registrado (duplicado)
  - Otros → Errores desconocidos
- Captura excepciones de transporte (errores de red) y las mapea a excepciones custom
- Registra toda la respuesta (success/error) para auditoría en la BD

---

### 4. **Repository** - Auditoría

📄 Ver: [Repository/ContractSyncRepository.php](Repository/ContractSyncRepository.php)

**Función:** Persiste en la BD los registros de auditoría de cada intento de sincronización:
- Guarda/actualiza registros `ContractSync` con:
  - ID del contrato sincronizado
  - Status (pending/success/failed)
  - ID externo asignado por ERSE (si fue exitoso)
  - Response JSON completa de ERSE (para debugging)
  - Mensaje de error (si falló)
  - Timestamps de creación/actualización
- Usa `INSERT ... ON DUPLICATE KEY UPDATE` para evitar duplicados
- Proporciona método para buscar sincronizaciones pendientes por contrato

---

## 🛡️ Manejo de Casos Edge

### Caso 1: Contrato Duplicado
**Escenario:** Se intenta sincronizar un contrato ya registrado en ERSE

**Solución:**
- La BD tiene un constraint `UNIQUE (contract_id, status='pending')` que previene múltiples sincronizaciones pendientes del mismo contrato
- Si ERSE retorna `409 Conflict`, significa que el contrato ya está registrado
- El servicio captura la excepción `DuplicateContractException`, marca el registro como `failed`, y NO intenta retry automático
- Se registra el error en el campo `error_message` para auditoría

---

### Caso 2: Outage de ERSE
**Escenario:** La API de ERSE no disponible o timeout

**Solución:**
- Si ocurre un `TransportException` (error de red), el servicio captura la excepción
- Marca el registro como `failed` y guarda el mensaje de error
- En el Controller, se puede envolver en un try-catch para retornar `HTTP 202 Accepted` (status queued)
- Un comando de background job (ejecutado cada 5 minutos) puede leer registros con status `failed` y reintentar la sincronización de forma asincrónica

---

### Caso 3: Validación de Contrato Portugués
**Escenario:** Intento de sincronizar contrato de otro país (ej: España)

**Solución:**
- El servicio valida que `contract->getCountry() === 'PT'`
- Si el país no es Portugal, lanza una excepción `ValidationException`
- Marca el registro como `failed` con mensaje "Only Portuguese contracts can be synced to ERSE"
- El Controller retorna `HTTP 400 Bad Request` - no es error del sistema, es validación legítima

---

## 🧪 Testing

### Unit Test - Service

📄 Ver: [Tests/](Tests/) (si existen tests en esta carpeta)

**Estrategia de Testing:**
- **Mocks de dependencias externas:** Usar fakes para `ContractRepository` y cliente HTTP a ERSE
- **Casos a validar:**
  - ✅ Sincronización exitosa de contrato portugués
  - ❌ Fallo cuando el contrato no existe
  - ❌ Fallo cuando el contrato NO es portugués (country ≠ 'PT')
  - ❌ Fallo cuando ERSE retorna 409 (duplicado)
  - ❌ Manejo de errores de red (TransportException)
- **Assertions:** Verificar que el status del registro `ContractSync` sea el correcto (success/failed), que se haya guardado en BD, y que el error message esté presente si falló
- **Logging:** Validar que se registren correctamente los intentos para auditoría

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

Debes definir estas variables en tu archivo `.env` o `.env.production` para que [Service/ErseSyncService.php](Service/ErseSyncService.php) pueda conectar con la API de ERSE:

```bash
# URL base de la API de ERSE
ERSE_URL=https://api.erse.pt/v1

# Token de autenticación Bearer OAuth
ERSE_TOKEN=Bearer eyJ0eXAiOiJKV1QiLCJhbGc...

# Timeout máximo para requests a ERSE (en segundos)
ERSE_TIMEOUT=10
```

**Importante:**
- ⚠️ Nunca commits estos valores en el repositorio
- ⚠️ Las credenciales NUNCA deben estar hardcodeadas en el código
- ⚠️ Usa gestión de secretos (GitHub Secrets, AWS Secrets Manager, etc) en production

### Inyección de Dependencias

El servicio [Service/ErseSyncService.php](Service/ErseSyncService.php) recibe estos valores mediante inyección de dependencias en Symfony. Los repositorios y el cliente HTTP también son inyectados automáticamente por el contenedor de servicios de Symfony.

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

### 1. **Sincronizar un contrato**

Envía una solicitud POST al endpoint que expone [Controller/ContractSyncController.php](Controller/ContractSyncController.php):

```bash
curl -X POST http://localhost:8000/api/contracts/sync \
     -H "Content-Type: application/json" \
     -d '{"contract_id": 123}'
```

**Respuestas esperadas:**
- `201 Created` - Sincronización exitosa, incluye `erse_id`
- `400 Bad Request` - Validación falló (contrato no es portugués, no existe campos requeridos)
- `404 Not Found` - El contrato no existe en la BD
- `500 Internal Server Error` - Error inesperado en el servidor

---

### 2. **Verificar estado de sincronización**

Consulta la tabla de auditoría que gestiona [Repository/ContractSyncRepository.php](Repository/ContractSyncRepository.php):

```sql
SELECT id, contract_id, status, erse_external_id, error_message, created_at 
FROM contract_sync 
WHERE contract_id = 123
ORDER BY created_at DESC;
```

Campos importantes:
- `status`: `pending` | `success` | `failed`
- `erse_external_id`: ID asignado por ERSE (solo si status='success')
- `error_message`: Descripción del error (solo si status='failed')

---

### 3. **Retry de sincronizaciones fallidas**

Los registros con `status='failed'` pueden reintenarse con un comando de background job:

```bash
symfony console app:retry-failed-syncs
```

Este comando (si se configura en el proyecto):
- Busca todos los registros `failed` en la tabla `contract_sync`
- Reintenta sincronizar cada uno hasta un máximo de reintentos
- Actualiza el estado según el resultado del reintento

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
