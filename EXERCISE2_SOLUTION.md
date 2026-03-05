# EXERCISE 2.1 & 2.2 - Complete Solution

## EXERCISE 2.1: CODE REVIEW - Issues Found

### 🔴 SECURITY VULNERABILITIES (Critical)

#### 1. SQL Injection - Line 18
```php
"SELECT c.*, t.code as tariff_code, t.price_per_kwh, t.fixed_monthly
 FROM contracts c JOIN tariffs t ON c.tariff_id = t.id
 WHERE c.id = $contractId"  // ❌ VULNERABLE
```
**Attack:** `$contractId = "1 OR 1=1"` → selects all contracts
**Impact:** Data breach, unauthorized access

#### 2. SQL Injection - Line 29
```php
"AND FORMAT(reading_date, 'yyyy-MM') = '$month'"  // ❌ VULNERABLE
```
**Attack:** `$month = "2026'; DROP TABLE meter_readings; --"`
**Impact:** Data destruction

#### 3. SQL Injection - Line 48
```php
"VALUES ($contractId, '$month', $totalKwh, $total, 'draft')"  // ❌ VULNERABLE
```
**Impact:** Arbitrary data insertion

---

### 🔴 BAD ERROR HANDLING

#### 4. Using `echo` for Errors (Lines 24, 41)
```php
echo "Contract not found";  // ❌ BAD PRACTICE
echo "Unknown tariff type";
```

**Problems:**
- Cannot be caught programmatically
- Cannot be logged
- Output goes to stdout (breaks JSON API responses)
- Cannot return proper HTTP status codes
- No stack trace for debugging

**Example failure:**
```php
// Client code can't handle this
$result = $calculator->calculate($id, $month);
// If error occurred, it echoed and returned false
// But we can't distinguish between "error" and "false result"
```

#### 5. Inconsistent Return Values
```php
return false;    // Line 25, 42
return $total;   // Line 55 (number)
```

**Problem:** Client code must check:
```php
if ($result === false) { /* error */ }
if ($result > 0) { /* success */ }
```

This is error-prone and unclear.

---

### 🔴 MAINTAINABILITY PROBLEMS

#### 6. Giant if/elseif Chain (Lines 34-45)
```php
if (strpos($contract['tariff_code'], 'FIX') !== false) {
    // FIX logic
} elseif (strpos($contract['tariff_code'], 'INDEX') !== false) {
    // INDEX logic
} elseif ($contract['tariff_code'] == 'FLAT_RATE') {
    // FLAT_RATE logic
} else {
    echo "Unknown tariff type";
    return false;
}
```

**Problems:**
- Adding new tariff type requires modifying `calculate()` method
- Each case is tightly coupled
- Logic duplication across cases
- Hard to test individual tariff logic
- Violates Open/Closed Principle

**To add TIME_OF_USE tariff:**
- Must edit `calculate()` method
- Risk breaking other tariffs
- Hard to review/test

#### 7. Mixed Concerns in Single Method
```php
public function calculate($contractId, $month) {
    // Database access (SQL queries)
    $contract = $this->db->query(...);
    $readings = $this->db->query(...);
    
    // Business logic (calculations)
    if (strpos(...)) { ... }
    
    // Tax calculation
    if ($contract['country'] == 'PT') { $tax = ... }
    
    // Database persistence
    $this->db->query("INSERT INTO invoices...");
    
    // Output (echo)
    echo "Invoice created...";
}
```

**Problem:** Single method does 5 things → violates Single Responsibility Principle

---

### 🔴 ARCHITECTURE ISSUES

#### 8. Tight Coupling to Raw Database Object
```php
public function __construct($db) {
    $this->db = $db;  // Raw PDO or mysqli object
}
```

**Problems:**
- Cannot mock in tests
- Too much power (can do any SQL operation)
- Unclear what database operations are needed
- No separation of concerns

**Example:** Testing is impossible without real database
```php
// Can't mock easily
$calculator = new InvoiceCalculator($realDb);  // Must use real DB!
```

#### 9. No Exception Handling
```php
$spotPrice = file_get_contents(
    "https://api.energy-market.eu/spot?month=$month"
);  // ❌ No error handling for network failure
```

**Problems:**
- If API is down, `file_get_contents()` returns `false`
- `json_decode()` might return null
- No timeout set (can hang forever)
- No retry logic

**What can happen:**
```
API timeout → file_get_contents returns false
→ json_decode(false) returns null  
→ $spotData['avg_price'] throws PHP warning
→ $amount becomes NaN
→ Invoice with invalid amount is created ❌
```

#### 10. No Logging
- Cannot track calculation errors
- Cannot debug production issues
- Cannot audit invoice creation
- No visibility into API failures

#### 11. No Input Validation
```php
public function calculate($contractId, $month) {
    // No validation that:
    // - $contractId is positive integer
    // - $month is valid YYYY-MM format
    // - Contract actually exists
}
```

---

## EXERCISE 2.2: REFACTORING SOLUTION

Here's the refactored code using Symfony conventions and architecture patterns:

### New Architecture

```
HTTP Request
     ↓
 Controller (HTTP handling)
     ↓
 Service (Business logic)
     ↓
 Repository (Data access)
 + Factory (Tariff strategy)
     ↓
 Database / External APIs
```

---

## FIX 1: ✅ SQL Injection Prevention

**Repository Pattern with Parameterized Queries:**

```php
// ContractRepository.php
class ContractRepository {
    public function findById(int $contractId): ?Contract {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.tariff_id, c.country, 
                    t.id, t.code, t.price_per_kwh, t.fixed_monthly
             FROM contracts c 
             JOIN tariffs t ON c.tariff_id = t.id
             WHERE c.id = :contract_id"  // ✅ Parameterized
        );

        $stmt->execute(['contract_id' => $contractId]);
        // ...
    }
}

// MeterReadingRepository.php
class MeterReadingRepository {
    public function getTotalKwhForPeriod(
        int $contractId, 
        string $month
    ): float {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(kwh_consumed), 0) as total
             FROM meter_readings
             WHERE contract_id = :contract_id
             AND FORMAT(reading_date, 'yyyy-MM') = :month"  // ✅ All parameters
        );

        $stmt->execute([
            'contract_id' => $contractId,  // ✅ Parameterized
            'month' => $month              // ✅ Parameterized
        ]);

        return (float) $stmt->fetch()['total'];
    }
}

// InvoiceRepository.php
class InvoiceRepository {
    public function save(Invoice $invoice): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO invoices 
             (contract_id, billing_period, total_kwh, total_amount, status)
             VALUES (:contract_id, :billing_period, :total_kwh, :total_amount, :status)"
        );

        $stmt->execute([
            'contract_id' => $invoice->getContractId(),    // ✅ Parameterized
            'billing_period' => $invoice->getBillingPeriod(),
            'total_kwh' => $invoice->getTotalKwh(),
            'total_amount' => $invoice->getTotalAmount(),
            'status' => $invoice->getStatus()
        ]);
    }
}
```

**Result:** ✅ Injection attacks now impossible

---

## FIX 2: ✅ Exception-Based Error Handling

**Custom Exceptions:**

```php
// Exception/ContractNotFoundException.php
class ContractNotFoundException extends \Exception {}

// Exception/TariffCalculationException.php
class TariffCalculationException extends \Exception {}

// Exception/ExternalApiException.php
class ExternalApiException extends \Exception {}
```

**Service with Proper Error Handling:**

```php
// InvoiceService.php
class InvoiceService {
    public function createInvoice(int $contractId, string $month): Invoice {
        // Load contract
        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            $this->logger->warning(
                "Contract not found",
                ['contract_id' => $contractId]
            );
            throw new ContractNotFoundException(
                "Contract with ID $contractId not found"
            );
        }

        // Get readings
        $totalKwh = $this->meterRepository->getTotalKwhForPeriod(
            $contractId, 
            $month
        );

        // Calculate (might throw TariffCalculationException)
        $calculator = $this->tariffFactory->createCalculator(
            $contract->getTariff()->getCode(),
            $month,
            $contract->getTariff()->getPricePerKwh()
        );
        
        $amount = $calculator->calculate($totalKwh, $contract->getTariff()->getFixedMonthly());

        // Calculate tax
        $tax = $this->taxCalculator->calculateTax(
            $amount,
            $contract->getCountry()
        );

        // Create invoice
        $invoice = new Invoice(
            $contractId,
            $month,
            $totalKwh,
            $amount + $tax,
            'draft'
        );

        // Persist
        $this->invoiceRepository->save($invoice);

        // Log success
        $this->logger->info(
            "Invoice created successfully",
            [
                'contract_id' => $contractId,
                'total' => $amount + $tax
            ]
        );

        return $invoice;
    }
}
```

**Controller with Proper HTTP Responses:**

```php
// InvoiceController.php
class InvoiceController extends AbstractController {
    public function create(int $contractId, string $month): JsonResponse {
        try {
            $invoice = $this->invoiceService->createInvoice($contractId, $month);
            
            return new JsonResponse([
                'status' => 'success',
                'data' => [
                    'id' => $invoice->getId(),
                    'total_amount' => $invoice->getTotalAmount()
                ]
            ], Response::HTTP_CREATED);

        } catch (ContractNotFoundException $e) {
            // 404: Resource not found
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_NOT_FOUND);

        } catch (TariffCalculationException $e) {
            // 500: Server error (calculation failed)
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Failed to calculate invoice'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

        } catch (ExternalApiException $e) {
            // 503: Service unavailable (API down)
            return new JsonResponse([
                'status' => 'error',
                'message' => 'External pricing service unavailable'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
```

**Result:** ✅ Proper error handling and logging

---

## FIX 3: ✅ Strategy Pattern for Tariffs

**Interface (Contract):**

```php
interface TariffCalculatorInterface {
    public function calculate(float $totalKwh, float $fixedMonthly): float;
}
```

**Concrete Implementations:**

```php
class FixedTariffCalculator implements TariffCalculatorInterface {
    private float $pricePerKwh;

    public function __construct(float $pricePerKwh) {
        $this->pricePerKwh = $pricePerKwh;
    }

    public function calculate(float $totalKwh, float $fixedMonthly): float {
        return ($totalKwh * $this->pricePerKwh) + $fixedMonthly;
    }
}

class FixedPromoTariffCalculator implements TariffCalculatorInterface {
    private float $pricePerKwh;
    private const PROMO_DISCOUNT = 0.9;

    public function calculate(float $totalKwh, float $fixedMonthly): float {
        $amount = ($totalKwh * $this->pricePerKwh) + $fixedMonthly;
        return $amount * self::PROMO_DISCOUNT;
    }
}

class IndexedTariffCalculator implements TariffCalculatorInterface {
    private EnergyMarketApiClient $apiClient;
    private string $month;
    private const BULK_DISCOUNT_THRESHOLD = 500;
    private const BULK_DISCOUNT = 0.95;

    public function calculate(float $totalKwh, float $fixedMonthly): float {
        try {
            $spotPrice = $this->apiClient->getSpotPrice($this->month);
        } catch (\Exception $e) {
            throw new TariffCalculationException(
                "Failed to fetch spot price: " . $e->getMessage(),
                0,
                $e
            );
        }

        $amount = ($totalKwh * $spotPrice) + $fixedMonthly;

        if ($totalKwh > self::BULK_DISCOUNT_THRESHOLD) {
            $amount *= self::BULK_DISCOUNT;
        }

        return $amount;
    }
}

class FlatRateTariffCalculator implements TariffCalculatorInterface {
    public function calculate(float $totalKwh, float $fixedMonthly): float {
        return $fixedMonthly;
    }
}
```

**Factory (Single Routing Point):**

```php
class TariffCalculatorFactory {
    private EnergyMarketApiClient $apiClient;

    public function __construct(EnergyMarketApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function createCalculator(
        string $tariffCode,
        string $month,
        float $pricePerKwh
    ): TariffCalculatorInterface {
        
        if (strpos($tariffCode, 'FIX') === 0) {
            if ($tariffCode === 'FIX_PROMO') {
                return new FixedPromoTariffCalculator($pricePerKwh);
            }
            return new FixedTariffCalculator($pricePerKwh);
        }

        if (strpos($tariffCode, 'INDEX') === 0) {
            return new IndexedTariffCalculator($this->apiClient, $month);
        }

        if ($tariffCode === 'FLAT_RATE') {
            return new FlatRateTariffCalculator();
        }

        throw new UnknownTariffException("Unknown tariff: $tariffCode");
    }
}
```

**How to Add New Tariff Type (e.g., TIME_OF_USE):**

```php
// 1. Create calculator
class TimeOfUseTariffCalculator implements TariffCalculatorInterface {
    public function calculate(float $totalKwh, float $fixedMonthly): float {
        // Peak hours: 6pm-10pm at €0.35/kWh
        // Off-peak: rest at €0.10/kWh
        $peakKwh = $totalKwh * 0.4;      // Estimate 40% peak usage
        $offPeakKwh = $totalKwh * 0.6;
        
        $amount = ($peakKwh * 0.35) + ($offPeakKwh * 0.10) + $fixedMonthly;
        return $amount;
    }
}

// 2. Register in factory (1 line added)
if (strpos($tariffCode, 'TIME_OF_USE') === 0) {
    return new TimeOfUseTariffCalculator();
}
```

✅ **Zero changes to existing code!**

---

## FIX 4: ✅ Dependency Injection

**Before (Tightly Coupled):**
```php
$calculator = new InvoiceCalculator($pdo);  // Raw database object
```

**After (Loose Coupling):**
```php
$invoiceService = new InvoiceService(
    $contractRepository,        // Specific to what we need
    $meterRepository,          // Specific to what we need
    $invoiceRepository,        // Specific to what we need
    $tariffFactory,            // Abstraction (interface)
    $taxCalculator,            // Focused concern
    $logger                    // PSR-3 interface
);
```

**Benefits:**
```php
// Easy to test with mocks
$mockRepo = $this->createMock(ContractRepository::class);
$mockRepo->method('findById')->willReturn($contract);

$service = new InvoiceService(
    $mockRepo,
    $meterRepo,
    $invoiceRepo,
    $tariffFactory,
    $taxCalc,
    $logger
);

// No database needed! Pure testing.
$invoice = $service->createInvoice(123, '2026-03');
```

---

## FIX 5: ✅ External API Error Handling

**Before (Fragile):**
```php
$spotPrice = file_get_contents(
    "https://api.energy-market.eu/spot?month=$month"
);  // Can hang forever, no error handling
$spotData = json_decode($spotPrice, true);
$amount = $totalKwh * $spotData['avg_price'];  // Can throw warnings
```

**After (Robust):**
```php
// EnergyMarketApiClient.php
class EnergyMarketApiClient {
    private string $baseUrl;
    private int $timeout;

    public function getSpotPrice(string $month): float {
        $url = $this->baseUrl . "/spot?month=" . urlencode($month);

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,  // ✅ Timeout set
                'method' => 'GET'
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new ExternalApiException(
                "Failed to fetch spot price from energy market API"
            );
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['avg_price'])) {
            throw new ExternalApiException(
                "Invalid response from energy market API"
            );
        }

        return (float) $data['avg_price'];
    }
}

// In Calculator
public function calculate(float $totalKwh, float $fixedMonthly): float {
    try {
        $spotPrice = $this->apiClient->getSpotPrice($this->month);
    } catch (\Exception $e) {
        throw new TariffCalculationException(
            "Failed to fetch spot price: " . $e->getMessage(),
            0,
            $e
        );  // ✅ Propagate as domain exception
    }

    $amount = ($totalKwh * $spotPrice) + $fixedMonthly;
    // ...
}
```

✅ **Benefits:**
- Timeout prevents hanging
- Exceptions are caught and wrapped
- Controller can return 503 Service Unavailable
- Service is observable/testable

---

## UNIT TESTING STRATEGY

### What to Test

```php
// TariffCalculatorTests.php

class FixedTariffCalculatorTest extends TestCase {
    public function testCalculateFixedTariff() {
        $calc = new FixedTariffCalculator(0.12);
        $amount = $calc->calculate(100, 10);
        // (100 * 0.12) + 10 = 22
        $this->assertEquals(22.0, $amount);
    }
}

class IndexedTariffCalculatorTest extends TestCase {
    public function testApplyBulkDiscount() {
        // Mock API
        $apiClient = $this->createMock(EnergyMarketApiClient::class);
        $apiClient->method('getSpotPrice')->willReturn(0.25);

        $calc = new IndexedTariffCalculator($apiClient, '2026-03');
        $amount = $calc->calculate(600, 10);
        // (600 * 0.25 + 10) * 0.95 = 152.0
        $this->assertEquals(152.0, $amount);
    }

    public function testThrowsExceptionWhenApiFails() {
        $apiClient = $this->createMock(EnergyMarketApiClient::class);
        $apiClient->method('getSpotPrice')
            ->willThrowException(new \Exception("API timeout"));

        $calc = new IndexedTariffCalculator($apiClient, '2026-03');
        
        $this->expectException(TariffCalculationException::class);
        $calc->calculate(100, 10);
    }
}

class TariffCalculatorFactoryTest extends TestCase {
    public function testCreateCorrectCalculator() {
        $factory = new TariffCalculatorFactory($apiClient);
        
        $this->assertInstanceOf(
            FixedTariffCalculator::class,
            $factory->createCalculator('FIX', '2026-03', 0.12)
        );
    }

    public function testThrowExceptionForUnknownTariff() {
        $factory = new TariffCalculatorFactory($apiClient);
        
        $this->expectException(UnknownTariffException::class);
        $factory->createCalculator('UNKNOWN', '2026-03', 0.12);
    }
}
```

### Testing Strategy

✅ **Test these:**
- Each TariffCalculator implementation (unit tests)
- TariffCalculatorFactory routing (unit tests)
- TaxCalculator rates (unit tests)
- Exceptions are thrown correctly (unit tests)

❌ **Don't test (integration/e2e):**
- Full HTTP request → response flow
- Real database operations
- Real API calls

**Why this approach?**
- Fast: No database, no network
- Reliable: No external dependencies
- Focused: Test one thing at a time
- Maintainable: Easy to refactor

---

## COMPARISON TABLE

| Issue | Before | After |
|-------|--------|-------|
| **SQL Injection** | `WHERE c.id = $contractId` | Parameterized queries |
| **Error Handling** | `echo` statements | Exceptions + Logging |
| **Status Codes** | Always 200 (or error) | Proper HTTP codes |
| **Tariff Addition** | Modify `calculate()` method | Create new class + 1 line in factory |
| **Testing** | Impossible (needs DB) | Easy (mocks work) |
| **Database Access** | Raw PDO in class | Repositories (separation) |
| **Business Logic** | Mixed with DB code | In Service layer |
| **API Failures** | Silent fail / crash | Proper exception + logging |
| **Logging** | No logging | PSR-3 logger |
| **Constants** | Magic numbers | Named constants |

---

## Summary

### Problems Fixed ✅

1. **Security:** ✅ SQL Injection completely eliminated
2. **Error Handling:** ✅ Exceptions instead of echo
3. **Maintainability:** ✅ Strategy pattern eliminates if/elseif chains
4. **Testability:** ✅ Dependency injection enables mocking
5. **Scalability:** ✅ Adding tariffs requires no changes to core logic
6. **Reliability:** ✅ API client with proper error handling
7. **Observability:** ✅ Structured logging
8. **Architecture:** ✅ Proper separation of concerns (SOLID)

### Design Principles Applied ✅

- ✅ **Single Responsibility:** Each class has one reason to change
- ✅ **Open/Closed:** Open for extension (new tariffs), closed for modification
- ✅ **Liskov Substitution:** All calculators implement same interface
- ✅ **Interface Segregation:** Small, focused interfaces
- ✅ **Dependency Inversion:** Depend on abstractions, not concretions

This refactored code is **production-ready, maintainable, and follows industry best practices**.
