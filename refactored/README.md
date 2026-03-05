# Refactored Invoice Calculator - Solution Summary

## Overview

This refactored code implements a clean, maintainable architecture following SOLID principles and Symfony conventions.

## Architecture Layers

```
┌─────────────────────┐
│  InvoiceController  │ (HTTP Entry Point)
└──────────┬──────────┘
           │
┌──────────▼──────────────────┐
│   InvoiceService            │ (Business Logic)
│  - createInvoice()          │
└──────────┬──────────────────┘
           │
    ┌──────┴──────────┐
    │                 │
┌───▼────────────┐  ┌─▼──────────────────┐
│ Repositories   │  │ TariffCalculators  │
│ - Contract     │  │ (Strategy Pattern) │
│ - Meter        │  │ - Fixed, Promo     │
│ - Invoice      │  │ - Indexed, Flat    │
└────────────────┘  └────────────────────┘
          │
      ┌───▼──────────────┐
      │  Entities        │
      │ - Contract       │
      │ - Tariff         │
      │ - Invoice        │
      └──────────────────┘
```

## Key Improvements

### 1. ✅ Security: SQL Injection Fixed

**Before (VULNERABLE):**
```php
"WHERE c.id = $contractId"  // Direct concatenation
```

**After (SAFE):**
```php
$stmt = $pdo->prepare(
    "SELECT * FROM contracts WHERE c.id = :contract_id"
);
$stmt->execute(['contract_id' => $contractId]);
```

All queries now use parameterized statements.

---

### 2. ✅ Error Handling: Exceptions Instead of Echo

**Before (BAD):**
```php
echo "Contract not found";  // Can't be caught or logged
return false;
```

**After (GOOD):**
```php
throw new ContractNotFoundException(
    "Contract with ID $contractId not found"
);
```

Benefits:
- Exceptions propagate to controller
- Can be caught and logged
- Different HTTP status codes
- Consistent error handling

---

### 3. ✅ Dependency Injection: No Raw DB Objects

**Before (TIGHTLY COUPLED):**
```php
class InvoiceCalculator {
    public function __construct($db) {
        $this->db = $db;  // Raw PDO object
    }
}
```

**After (LOOSELY COUPLED):**
```php
class InvoiceService {
    public function __construct(
        ContractRepository $contractRepository,
        MeterReadingRepository $meterRepository,
        InvoiceRepository $invoiceRepository,
        TariffCalculatorFactory $tariffFactory,
        TaxCalculator $taxCalculator,
        LoggerInterface $logger
    ) {}
}
```

Benefits:
- Easy to mock in tests
- Each dependency is specific
- Clear what the service needs
- Easy to swap implementations

---

### 4. ✅ Maintenance: Strategy Pattern for Tariffs

**Problem solved:** How to add new tariff types without modifying existing classes?

**Solution:** Strategy Pattern + Factory

```
TariffCalculatorInterface (Contract)
        ▲
        │
    ┌───┴────┬──────────┬──────┐
    │        │          │      │
  Fixed   FixedPromo  Indexed  Flat
```

**To add a new tariff (TIME_OF_USE):**

1. Create new class:
```php
class TimeOfUseTariffCalculator implements TariffCalculatorInterface {
    public function calculate(float $totalKwh, float $fixedMonthly): float {
        // Peak/off-peak logic
    }
}
```

2. Register in factory (1 line):
```php
if (strpos($tariffCode, 'TIME_OF_USE') === 0) {
    return new TimeOfUseTariffCalculator(...);
}
```

✅ **No changes to existing classes** - Open/Closed Principle

---

### 5. ✅ Separation of Concerns

Each class has a single responsibility:

| Class | Responsibility |
|-------|---|
| **Repository** | Database access |
| **Entity** | Data model |
| **Service** | Business logic orchestration |
| **Calculator** | Specific tariff calculation |
| **ApiClient** | External HTTP calls |
| **Controller** | HTTP request/response |

---

## Testing Strategy

### Unit Tests (What & Why)

✅ **Test these:**
- Tariff Calculators (isolated logic, no DB)
- TariffCalculatorFactory (routing to correct calculator)
- TaxCalculator (tax rate logic)
- Repository queries (verify parameterized)

❌ **Skip integration tests:**
- Full HTTP flow (controller to database)
- Real API calls (mock them)
- Database operations (use test fixtures)

### Example Test

```php
class FixedTariffCalculatorTest extends TestCase {
    public function testCalculateFixedTariff(): void {
        $calculator = new FixedTariffCalculator(0.12);
        
        $amount = $calculator->calculate(100, 10);
        // (100 * 0.12) + 10 = 22
        
        $this->assertEquals(22.0, $amount);
    }
}
```

See `Tests/TariffCalculatorTests.php` for complete examples.

---

## File Structure

```
refactored/
├── Controller/
│   └── InvoiceController.php         # HTTP endpoint
├── Service/
│   ├── InvoiceService.php            # Orchestrates calculation
│   ├── TaxCalculator.php             # Tax logic
│   ├── EnergyMarketApiClient.php     # External API
│   └── TariffCalculator/
│       ├── TariffCalculatorInterface.php
│       ├── FixedTariffCalculator.php
│       ├── FixedPromoTariffCalculator.php
│       ├── IndexedTariffCalculator.php
│       ├── FlatRateTariffCalculator.php
│       └── TariffCalculatorFactory.php
├── Repository/
│   ├── ContractRepository.php        # DB: load contracts
│   ├── MeterReadingRepository.php    # DB: load meter data
│   └── InvoiceRepository.php         # DB: save invoices
├── Entity/
│   ├── Contract.php
│   ├── Tariff.php
│   └── Invoice.php
├── Exception/
│   ├── ContractNotFoundException.php
│   ├── UnknownTariffException.php
│   ├── TariffCalculationException.php
│   └── ExternalApiException.php
└── Tests/
    └── TariffCalculatorTests.php     # Unit test examples
```

---

## Example Usage

```php
// Setup (Symfony DI container would do this)
$pdo = new PDO('sqlsrv:Server=...;Database=...');
$logger = new Logger(...);

$contractRepo = new ContractRepository($pdo);
$meterRepo = new MeterReadingRepository($pdo);
$invoiceRepo = new InvoiceRepository($pdo);
$apiClient = new EnergyMarketApiClient();
$tariffFactory = new TariffCalculatorFactory($apiClient);
$taxCalc = new TaxCalculator();

$invoiceService = new InvoiceService(
    $contractRepo,
    $meterRepo,
    $invoiceRepo,
    $tariffFactory,
    $taxCalc,
    $logger
);

// Use
try {
    $invoice = $invoiceService->createInvoice(
        contractId: 123,
        month: '2026-03'
    );
    
    echo "Invoice created: €" . $invoice->getTotalAmount();
} catch (ContractNotFoundException $e) {
    // Handle contract not found
} catch (TariffCalculationException $e) {
    // Handle calculation error
}
```

---

## SOLID Principles Applied

| Principle | How | Example |
|-----------|-----|---------|
| **S** - Single Resp. | Each class does one thing | `FixedTariffCalculator` only calculates fixed tariffs |
| **O** - Open/Closed | Add behavior without modifying | New tariff = new class, no changes to factory signature |
| **L** - Liskov Subst. | All calculators implement interface | `TariffCalculatorInterface` allows polymorphism |
| **I** - Interface Seg. | Small, focused interfaces | Calculator doesn't need logger, api, etc. |
| **D** - Dependency Inv. | Depend on abstractions | `InvoiceService` depends on interfaces, not implementations |

---

## Migration Notes

To migrate from old code:

1. **Create repositories** for each DB resource
2. **Extract calculators** into separate classes
3. **Move business logic** to service
4. **Replace echo** with exceptions
5. **Add logging** via PSR-3 logger interface
6. **Update controller** to catch exceptions and return proper HTTP status codes

---

## Symfony Configuration Example

```yaml
# services.yaml
App\Service\InvoiceService:
    arguments:
        $contractRepository: '@App\Repository\ContractRepository'
        $meterRepository: '@App\Repository\MeterReadingRepository'
        $invoiceRepository: '@App\Repository\InvoiceRepository'
        $tariffFactory: '@App\Service\TariffCalculator\TariffCalculatorFactory'
        $taxCalculator: '@App\Service\TaxCalculator'
        $logger: '@logger'

App\Service\TariffCalculator\TariffCalculatorFactory:
    arguments:
        $apiClient: '@App\Service\EnergyMarketApiClient'
```

---

## Conclusion

This refactored architecture provides:
- ✅ Security: Parameterized queries
- ✅ Maintainability: Strategy pattern for tariffs
- ✅ Testability: Loose coupling, interfaces
- ✅ Scalability: Easy to add features
- ✅ Professional: Follows industry standards
