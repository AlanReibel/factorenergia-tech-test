# Part 2: Code Review & Refactoring

## 🎯 Objetivo

Analizar código vulnerable PHP, identificar **11 problemas críticos** de seguridad y arquitectura, e implementar una solución refactorizada siguiendo principios SOLID y patrones de diseño profesionales.

---

## 📝 Resumen Ejecutivo

### Problema Original
Se proporcionó código PHP monolítico con:
- ❌ **3 vulnerabilidades SQL Injection críticas**
- ❌ **Manejo deficiente de errores** (echo en lugar de excepciones)
- ❌ **Falta de separación de responsabilidades**
- ❌ **Acoplamiento fuerte a base de datos**
- ❌ **Sin logging ni auditoría**
- ❌ **Sin validación de entrada**

### Solución Implementada
Arquitectura en capas con:
- ✅ **Prepared statements** para seguridad SQL
- ✅ **Excepciones custom** para error handling
- ✅ **Strategy Pattern** para tarifas extensibles
- ✅ **Dependency Injection** para testabilidad
- ✅ **Repository Pattern** para acceso a datos
- ✅ **Validación rigurosa** en cada capa

---

## 🔍 Los 11 Issues Identificados y Solucionados

### 🔴 SEGURIDAD (Issues #1-3)

#### Issue #1: SQL Injection en WHERE (Línea 18)
**Código vulnerable:**
```php
"SELECT c.*, t.code as tariff_code, t.price_per_kwh, t.fixed_monthly
 FROM contracts c JOIN tariffs t ON c.tariff_id = t.id
 WHERE c.id = $contractId"  // ❌ Variables interpoladas directamente
```

**Ataque posible:**
```php
$contractId = "1 OR 1=1";  // Selecciona TODOS los contratos
```

**Solución:**
```php
$sql = "SELECT c.*, t.code as tariff_code, t.price_per_kwh, t.fixed_monthly
        FROM contracts c 
        JOIN tariffs t ON c.tariff_id = t.id
        WHERE c.id = ?";
$stmt = $pdo->prepare($sql);  // ✅ Prepared statement
$stmt->execute([$contractId]); // ✅ Parámetro separado
```

---

#### Issue #2: SQL Injection en DATE (Línea 29)
**Código vulnerable:**
```php
"AND FORMAT(reading_date, 'yyyy-MM') = '$month'"  // ❌ Inyectable
```

**Ataque posible:**
```php
$month = "2026'; DROP TABLE meter_readings; --";
```

**Solución:**
```php
"AND YEAR(reading_date) = ? AND MONTH(reading_date) = ?";
$stmt->execute([$year, $month]);
```

---

#### Issue #3: SQL Injection en VALUES (Línea 48)
**Código vulnerable:**
```php
"VALUES ($contractId, '$month', $totalKwh, $total, 'draft')"
```

**Solución:** Mismo patrón de prepared statements para INSERT/UPDATE.

---

### 🔴 ERROR HANDLING (Issues #4-5)

#### Issue #4: echo para Errores (Líneas 24, 41)
**Código vulnerable:**
```php
if (!$contract) {
    echo "Contract not found";  // ❌ Problemas:
    return false;              // - No captureable en código cliente
}                              // - Contamina output (rompe JSON API)
                               // - Sin HTTP status code adecuado
```

**Solución:**
```php
if (!$contract) {
    throw new ContractNotFoundException(
        "Contract with ID {$contractId} not found"
    );  // ✅ Excepciones que pueden ser capturadas y transformadas
}
```

---

#### Issue #5: Valores de Retorno Inconsistentes
**Código vulnerable:**
```php
return false;    // Línea 25, 42
return $total;   // Línea 55 - ¿Es número o booleano?
```

**Problema:** Cliente debe adivinar qué significa cada valor:
```php
$result = $calculator->calculate($id, $month);

if ($result === false) { /* error? */ }
if (is_numeric($result)) { /* éxito? */ }
// Confuso y error-prone
```

**Solución:**
```php
// Siempre retorna un objeto Invoice o lanza excepción
try {
    $invoice = $service->createInvoice($contractId, $month);
    // $invoice siempre es válido aquí
    echo json_encode($invoice);
} catch (ContractNotFoundException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
}
```

---

### 🔴 ARQUITECTURA (Issues #6-11)

#### Issue #6: Cadena if/elseif para Tarifas (Líneas 34-45)
**Código vulnerable:**
```php
if (strpos($contract['tariff_code'], 'FIX') !== false) {
    $total = $totalKwh * $contract['price_per_kwh'] + $contract['fixed_monthly'];
} elseif (strpos($contract['tariff_code'], 'INDEX') !== false) {
    $spotPrice = $this->getSpotPrice($month);
    $total = $totalKwh * $spotPrice + $contract['fixed_monthly'];
} elseif ($contract['tariff_code'] == 'FLAT_RATE') {
    $total = $totalKwh <= 100 ? 50 : (100 * 0.5 + ($totalKwh - 100) * 0.7);
} else {
    echo "Unknown tariff type";  // ¿Qué hacemos?
    return false;
}
```

**Problemas:**
- Para agregar nueva tarifa, **modificar método existente**
- **Acoplamiento fuerte** entre tipos de tarifa
- **Difícil testear** cada tipo de tarifa independientemente
- **Violación de Open/Closed Principle**

**Solución: Strategy Pattern**
```php
// Interface para todas las estrategias
interface TariffCalculatorInterface {
    public function calculate(Contract $contract, int $totalKwh): float;
}

// Cada tarifa es su propia clase
class FixedTariffCalculator implements TariffCalculatorInterface {
    public function calculate(Contract $contract, int $totalKwh): float {
        return $totalKwh * $contract->getPricePerKwh() 
             + $contract->getFixedMonthly();
    }
}

class IndexedTariffCalculator implements TariffCalculatorInterface {
    // ... lógica de tarifa indexada
}

// Factory para seleccionar la estrategia
class TariffCalculatorFactory {
    public static function create(string $tariffType): TariffCalculatorInterface {
        return match($tariffType) {
            'FIXED' => new FixedTariffCalculator(),
            'INDEXED' => new IndexedTariffCalculator(),
            'FLAT_RATE' => new FlatRateTariffCalculator(),
            default => throw new UnknownTariffException($tariffType),
        };
    }
}

// Uso: limpio y extensible
$calculator = TariffCalculatorFactory::create($contract->getTariffType());
$total = $calculator->calculate($contract, $totalKwh);
```

**Ventaja:** Agregar nueva tarifa TIME_OF_USE son solo 3 pasos:
1. Crear clase `TimeOfUseTariffCalculator`
2. Agregar en Factory: `'TIME_OF_USE' => new TimeOfUseTariffCalculator()`
3. ¡Listo! Sin tocar otro código.

---

#### Issue #7: Responsabilidades Mixtas (Todo en un método)
**Código vulnerable:** Un método hace todo:
```php
public function calculate($contractId, $month) {
    // 1. Database access
    $contract = $this->db->query("SELECT ...");
    $readings = $this->db->query("SELECT ...");
    
    // 2. Business logic (tariff calculation)
    if (strpos(...)) { $total = ... }
    
    // 3. Tax calculation
    if ($contract['country'] == 'PT') { ... }
    
    // 4. Database persistence
    $this->db->query("INSERT INTO invoices...");
    
    // 5. Output
    echo "Invoice created";
}
```

**Violación:** Single Responsibility Principle - ¡5 responsabilidades!

**Solución: Separación de Responsabilidades**
```php
// Capa de Controlador - HTTP handling
class InvoiceController {
    public function create(Request $request): Response {
        $service = new InvoiceService(...);
        $invoice = $service->createInvoice(...);
        return new JsonResponse($invoice);
    }
}

// Capa de Servicio - Orquestación
class InvoiceService {
    public function createInvoice($contractId, $month): Invoice {
        $contract = $this->contractRepository->findById($contractId);
        $readings = $this->meterReadingRepository->getByMonth(...);
        $tariffCalculator = TariffCalculatorFactory::create(...);
        $tax = $this->taxCalculator->calculate(...);
        
        $invoice = new Invoice($contract, $readings, $tariffCalculator, $tax);
        $this->invoiceRepository->save($invoice);
        return $invoice;
    }
}

// Capa de Repository - Acceso a datos
class ContractRepository {
    public function findById(int $id): Contract { /* ... */ }
}

// Capa de Entity - Dominio
class Invoice {
    public function getTotalAmount(): float { /* ... */ }
}
```

---

#### Issue #8: Acoplamiento Fuerte a DB
**Código vulnerable:**
```php
public function __construct($db) {
    $this->db = $db;  // ❌ PDO/mysqli directo
}

// Cliente usa así:
$realDbConnection = getPDOConnection();
$calc = new InvoiceCalculator($realDbConnection);  // Debe usar DB real
```

**Problema:** 
- No se puede testear sin base de datos real
- El objeto tiene demasiado poder (puede hacer cualquier SQL)
- Difícil cambiar de MySQL a PostgreSQL

**Solución: Dependency Injection + Repository Pattern**
```php
// Interface para abstraer acceso a datos
interface ContractRepositoryInterface {
    public function findById(int $id): Contract;
    public function findByCountry(string $country): array;
}

// Implementación real
class ContractRepository implements ContractRepositoryInterface {
    public function __construct(private Connection $connection) {}
    
    public function findById(int $id): Contract {
        // Lógica SQL aquí
    }
}

// ServICIO recibe repositorio inyectado
class InvoiceService {
    public function __construct(
        private ContractRepositoryInterface $contracts,
        private MeterReadingRepositoryInterface $readings,
    ) {}
}

// Testing: inyectar mock
class FakeContractRepository implements ContractRepositoryInterface {
    public function findById(int $id): Contract {
        return new Contract(['id' => $id, 'country' => 'ES']);
    }
}

$service = new InvoiceService(new FakeContractRepository(), ...);
$result = $service->createInvoice(123, '2026-03');  // ✅ Sin DB real!
```

---

#### Issue #9: Sin Manejo de Errores de API Externa
**Código vulnerable:**
```php
$spotPrice = file_get_contents(
    "https://api.energy-market.eu/spot?month=$month"
);  // ❌ Sin validación

$spotData = json_decode($spotPrice, true);
return $totalKwh * $spotData['avg_price'];  // ¿Qué si API falló?
```

**Qué puede falir:**
- API timeout → `file_get_contents()` retorna `false`
- Red caída → excepción
- Response inválido → `json_decode()` retorna `null`
- Campo faltante → `Notice: undefined index`

**Solución:**
```php
class EnergyMarketApiClient {
    public function getSpotPrice(string $month): float {
        try {
            $response = $this->client->request('GET', '/spot', [
                'query' => ['month' => $month],
                'timeout' => 5,  // ✅ Timeout
            ]);
            
            if ($response->getStatusCode() !== 200) {
                throw new ExternalApiException("API retornó: " . $response->getStatusCode());
            }
            
            $data = json_decode($response->getBody(), true);
            
            if (!isset($data['avg_price'])) {
                throw new ExternalApiException("Campo 'avg_price' no encontrado");
            }
            
            return (float) $data['avg_price'];
            
        } catch (ClientException $e) {
            throw new ExternalApiException("API error: " . $e->getMessage(), previous: $e);
        }
    }
}
```

---

#### Issue #10: Sin Logging
**Problema:**
- ❌ No se sabe qué cálculos fallan en producción
- ❌ No se pueden auditar cambios de tarifa
- ❌ Debugging imposible después de que ocurren problemas

**Solución:**
```php
class InvoiceService {
    public function createInvoice($contractId, $month): Invoice {
        $this->logger->info("Creating invoice", ['contractId' => $contractId, 'month' => $month]);
        
        try {
            $contract = $this->contractRepository->findById($contractId);
            $this->logger->debug("Contract loaded", $contract->toArray());
            
            $tariff = TariffCalculatorFactory::create($contract->getTariffType());
            $amount = $tariff->calculate($contract, $readings->getTotalKwh());
            
            $this->logger->info("Invoice created successfully", ['amount' => $amount]);
            return $invoice;
            
        } catch (ContractNotFoundException $e) {
            $this->logger->warning("Contract not found: " . $e->getMessage());
            throw $e;
        }
    }
}
```

---

#### Issue #11: Sin Validación de Entrada
**Código vulnerable:**
```php
public function calculate($contractId, $month) {
    // Sin validación que:
    // - $contractId sea integer positivo
    // - $month sea formato válido YYYY-MM
    // - Contract exista
}
```

**Solución: Validación en capas**
```php
class InvoiceController {
    public function create(Request $request): Response {
        // 1. Validación HTTP
        $contractId = (int) $request->get('contractId');
        $month = $request->get('month');
        
        if ($contractId <= 0) {
            return new JsonResponse(['error' => 'Invalid contract ID'], 400);
        }
        
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return new JsonResponse(['error' => 'Invalid month format'], 400);
        }
        
        try {
            // 2. Validación en servicio
            $invoice = $this->service->createInvoice($contractId, $month);
            return new JsonResponse($invoice);
            
        } catch (ContractNotFoundException $e) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }
    }
}

class InvoiceService {
    public function createInvoice(int $contractId, string $month): Invoice {
        // 3. Validación adicional antes de usar
        if ($contractId < 1) {
            throw new InvalidArgumentException("contractId must be > 0");
        }
        
        // Proceder...
    }
}
```

---

## 🏗️ Arquitectura Final

```
HTTP Request
    ↓
┌─────────────────────────┐
│   InvoiceController     │  ← HTTP handling, validation
└────────────┬────────────┘
             ↓
┌─────────────────────────┐
│   InvoiceService        │  ← Business logic orchestration
│  - createInvoice()      │
│  - validateContract()   │
└────────────┬────────────┘
             ↓
     ┌───────┴────────┐
     ↓                ↓
┌──────────────┐  ┌──────────────────────┐
│ Repositories │  │ TariffCalculators    │
│              │  │  (Strategy Pattern)  │
│ContractRepo  │  │ - FixedCalculator    │
│MeterRepo     │  │ - IndexedCalculator  │
│InvoiceRepo   │  │ - FlatRateCalculator │
└──────────────┘  └──────────────────────┘
     ↓
 ┌───────────────────────┐
 │   Database / APIs     │
 └───────────────────────┘
```

---

## ✨ Patrones de Diseño Aplicados

| Patrón | Uso | Beneficio |
|--------|-----|----------|
| **Strategy** | TariffCalculators | Fácil agregar nuevas tarifas sin modificar código existente |
| **Repository** | Data access layer | Abstrae BD, facilita testing |
| **Factory** | TariffCalculatorFactory | Crea estrategia correcta basada en tipo |
| **Dependency Injection** | Constructor injection | Loose coupling, fácil testear |
| **Data Mapper** | Entity classes | Separación entre dominio y persistencia |
| **Exception** | Custom exceptions | Manejo claro de errores |

---

## 🧪 Testing

La arquitectura permite unit testing sin base de datos:

```php
class InvoiceServiceTest {
    public function testCreateInvoiceWithFixedTariff() {
        // Arrange
        $contract = new Contract(['id' => 1, 'tariff_type' => 'FIXED']);
        $contractRepo = new FakeContractRepository();
        $contractRepo->add($contract);
        
        $service = new InvoiceService($contractRepo, ...);
        
        // Act
        $invoice = $service->createInvoice(1, '2026-03');
        
        // Assert
        $this->assertEquals(150.5, $invoice->getTotalAmount());
    }
}
```

---

## 📂 Archivos en Esta Carpeta

### Documentación
- **README.md** ← Estás aquí
- **ANALYSIS.md** - Análisis detallado de los 11 issues
- **SOLUTIONS.md** - Soluciones código a código
- **COMPARISON.md** - Antes vs Después lado a lado

### Código Refactorizado
- `Entity/` - Dominio (Contract, Tariff, Invoice)
- `Repository/` - Acceso a datos
- `Service/` - Lógica de negocio
- `Service/TariffCalculator/` - Estrategias de cálculo
- `Exception/` - Excepciones custom
- `Controller/` - HTTP endpoints
- `Tests/` - Ejemplos de unit tests

---

## 🚀 Cómo Usar Este Código

1. **Estudiar:** Leer documentación en orden
   - ANALYSIS.md → SOLUTIONS.md → COMPARISON.md
   
2. **Implementar:** Copiar código de las carpetas Entity/, Repository/, Service/

3. **Testear:** Ejecutar ejemplos en Tests/

4. **Extender:** Agregar nuevas tarifas sin modificar código existente

---

## 📞 Conceptos Clave a Recordar

- ✅ Siempre usar **prepared statements** para SQL
- ✅ Lanzar **excepciones** en lugar de `echo`/`print`
- ✅ **Inyectar dependencias** en constructores
- ✅ Una clase = **una responsabilidad** (SRP)
- ✅ Código **open para extensión, closed para modificación** (OCP)
- ✅ Validar en **múltiples niveles** (controller, service, entity)
- ✅ Loguear **eventos importantes** para auditoría

---

**Siguiente:** Continúa con [Part 3 - API Integration](../part3-api/README.md)
