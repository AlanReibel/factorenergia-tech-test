# Before & After Comparison

## Side-by-Side Code Improvements

### 1. SQL INJECTION VULNERABILITY

#### ❌ BEFORE (Vulnerable)
```php
public function calculate($contractId, $month)
{
    $contract = $this->db->query(
        "SELECT c.*, t.code as tariff_code, t.price_per_kwh, t.fixed_monthly
         FROM contracts c JOIN tariffs t ON c.tariff_id = t.id
         WHERE c.id = $contractId"  // ⚠️ Direct concatenation
    )->fetch();

    $readings = $this->db->query(
        "SELECT SUM(kwh_consumed) as total
         FROM meter_readings
         WHERE contract_id = $contractId
         AND FORMAT(reading_date, 'yyyy-MM') = '$month'"  // ⚠️ Both vulnerable
    )->fetch();

    // ...

    $this->db->query(
        "INSERT INTO invoices (contract_id, billing_period, total_kwh, total_amount, status)
         VALUES ($contractId, '$month', $totalKwh, $total, 'draft')"  // ⚠️ 3 of 4 values vulnerable
    );
}
```

#### ✅ AFTER (Secure)
```php
// ContractRepository.php
$stmt = $this->pdo->prepare(
    "SELECT c.id, c.tariff_id, c.country, 
            t.id, t.code, t.price_per_kwh, t.fixed_monthly
     FROM contracts c 
     JOIN tariffs t ON c.tariff_id = t.id
     WHERE c.id = :contract_id"  // ✅ Parameterized
);
$stmt->execute(['contract_id' => $contractId]);

// MeterReadingRepository.php
$stmt = $this->pdo->prepare(
    "SELECT COALESCE(SUM(kwh_consumed), 0) as total
     FROM meter_readings
     WHERE contract_id = :contract_id
     AND FORMAT(reading_date, 'yyyy-MM') = :month"  // ✅ Both parameterized
);
$stmt->execute([
    'contract_id' => $contractId,
    'month' => $month
]);

// InvoiceRepository.php
$stmt = $this->pdo->prepare(
    "INSERT INTO invoices 
     (contract_id, billing_period, total_kwh, total_amount, status)
     VALUES (:contract_id, :billing_period, :total_kwh, :total_amount, :status)"  // ✅ All safe
);
$stmt->execute([
    'contract_id' => $invoice->getContractId(),
    'billing_period' => $invoice->getBillingPeriod(),
    'total_kwh' => $invoice->getTotalKwh(),
    'total_amount' => $invoice->getTotalAmount(),
    'status' => $invoice->getStatus()
]);
```

---

### 2. ERROR HANDLING

#### ❌ BEFORE (Bad Practices)
```php
public function calculate($contractId, $month) {
    $contract = $this->db->query(...)->fetch();

    if (!$contract) {
        echo "Contract not found";  // ⚠️ Echo to output
        return false;                // ⚠️ What if legitimate calculation returns false?
    }

    // ... logic ...

    if ($contract['tariff_code'] == 'FLAT_RATE') {
        $amount = $contract['fixed_monthly'];
    } else {
        echo "Unknown tariff type";  // ⚠️ Can't catch, log, or handle
        return false;
    }

    // ...
    echo "Invoice created: $total EUR";  // ⚠️ Output breaks JSON API
    return $total;
}
```

**Problem in client code:**
```php
$total = $calculator->calculate(123, '2026-03');
if ($total === false) {
    // What kind of error? Contract not found? Tariff unknown? SQL error?
}
```

#### ✅ AFTER (Proper Exception Handling)
```php
// InvoiceService.php
public function createInvoice(int $contractId, string $month): Invoice {
    $contract = $this->contractRepository->findById($contractId);
    if (!$contract) {
        $this->logger->warning(
            "Contract not found",
            ['contract_id' => $contractId]
        );
        throw new ContractNotFoundException(  // ✅ Specific exception
            "Contract with ID $contractId not found"
        );
    }

    // ...

    try {
        $calculator = $this->tariffFactory->createCalculator(
            $tariff->getCode(),
            $month,
            $tariff->getPricePerKwh()
        );
    } catch (UnknownTariffException $e) {
        $this->logger->error(
            "Unknown tariff type",
            ['tariff_code' => $tariff->getCode()]
        );
        throw $e;  // ✅ Propagate to controller
    }

    // ...
    
    $this->invoiceRepository->save($invoice);
    
    $this->logger->info(  // ✅ Structured logging
        "Invoice created successfully",
        [
            'contract_id' => $contractId,
            'total_amount' => $total,
            'tariff_code' => $tariff->getCode()
        ]
    );

    return $invoice;  // ✅ Return object, not string
}

// InvoiceController.php
public function create(int $contractId, string $month): JsonResponse {
    try {
        $invoice = $this->invoiceService->createInvoice($contractId, $month);
        
        return new JsonResponse([
            'status' => 'success',
            'data' => ['total' => $invoice->getTotalAmount()]
        ], Response::HTTP_CREATED);  // ✅ Proper status code

    } catch (ContractNotFoundException $e) {
        return new JsonResponse(
            ['status' => 'error', 'message' => $e->getMessage()],
            Response::HTTP_NOT_FOUND  // ✅ 404
        );

    } catch (TariffCalculationException $e) {
        return new JsonResponse(
            ['status' => 'error', 'message' => 'Calculation failed'],
            Response::HTTP_INTERNAL_SERVER_ERROR  // ✅ 500
        );

    } catch (ExternalApiException $e) {
        return new JsonResponse(
            ['status' => 'error', 'message' => 'API unavailable'],
            Response::HTTP_SERVICE_UNAVAILABLE  // ✅ 503
        );
    }
}
```

---

### 3. TARIFF CALCULATION - if/elseif Chain

#### ❌ BEFORE (Monolithic)
```php
public function calculate($contractId, $month) {
    // ... setup code ...

    if (strpos($contract['tariff_code'], 'FIX') !== false) {
        $amount = $totalKwh * $contract['price_per_kwh'];
        $amount += $contract['fixed_monthly'];
        if ($contract['tariff_code'] == 'FIX_PROMO') {
            $amount = $amount * 0.9;  // ⚠️ Hardcoded discount
        }
    } elseif (strpos($contract['tariff_code'], 'INDEX') !== false) {
        $spotPrice = file_get_contents(  // ⚠️ No error handling
            "https://api.energy-market.eu/spot?month=$month"
        );
        $spotData = json_decode($spotPrice, true);  // ⚠️ Might be null
        $amount = $totalKwh * $spotData['avg_price'];  // ⚠️ Might be undefined
        $amount += $contract['fixed_monthly'];
        if ($totalKwh > 500) {  // ⚠️ Hardcoded threshold
            $amount = $amount * 0.95;  // ⚠️ Hardcoded discount
        }
    } elseif ($contract['tariff_code'] == 'FLAT_RATE') {
        $amount = $contract['fixed_monthly'];
    } else {
        echo "Unknown tariff type";  // ⚠️ Not catchable
        return false;
    }

    // To add TIME_OF_USE: must modify this method, risk breaking other parts
}
```

#### ✅ AFTER (Strategy Pattern)
```php
// Each calculator is independent
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
    private const PROMO_DISCOUNT = 0.9;  // ✅ Named constant

    public function __construct(float $pricePerKwh) {
        $this->pricePerKwh = $pricePerKwh;
    }

    public function calculate(float $totalKwh, float $fixedMonthly): float {
        $amount = ($totalKwh * $this->pricePerKwh) + $fixedMonthly;
        return $amount * self::PROMO_DISCOUNT;  // ✅ Clear calculation
    }
}

class IndexedTariffCalculator implements TariffCalculatorInterface {
    private EnergyMarketApiClient $apiClient;
    private string $month;
    private const BULK_DISCOUNT_THRESHOLD = 500;  // ✅ Named constant
    private const BULK_DISCOUNT = 0.95;           // ✅ Named constant

    public function __construct(
        EnergyMarketApiClient $apiClient,
        string $month
    ) {
        $this->apiClient = $apiClient;
        $this->month = $month;
    }

    public function calculate(float $totalKwh, float $fixedMonthly): float {
        try {
            $spotPrice = $this->apiClient->getSpotPrice($this->month);  // ✅ Error handled
        } catch (\Exception $e) {
            throw new TariffCalculationException(
                "Failed to fetch spot price: " . $e->getMessage(),
                0,
                $e
            );
        }

        $amount = ($totalKwh * $spotPrice) + $fixedMonthly;

        if ($totalKwh > self::BULK_DISCOUNT_THRESHOLD) {  // ✅ Named constant
            $amount *= self::BULK_DISCOUNT;  // ✅ Named constant
        }

        return $amount;
    }
}

class FlatRateTariffCalculator implements TariffCalculatorInterface {
    public function calculate(float $totalKwh, float $fixedMonthly): float {
        return $fixedMonthly;  // ✅ Simple, clear
    }
}

// Factory acts as router
class TariffCalculatorFactory {
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

// Using it
$calculator = $this->tariffFactory->createCalculator(
    $tariff->getCode(),
    $month,
    $tariff->getPricePerKwh()
);
$amount = $calculator->calculate($totalKwh, $fixedMonthly);  // ✅ Polymorphic!
```

**To add TIME_OF_USE tariff:**

```php
// Just create new class
class TimeOfUseTariffCalculator implements TariffCalculatorInterface {
    private const PEAK_PRICE = 0.35;
    private const OFFPEAK_PRICE = 0.10;
    private const PEAK_PERCENTAGE = 0.4;

    public function calculate(float $totalKwh, float $fixedMonthly): float {
        $peakKwh = $totalKwh * self::PEAK_PERCENTAGE;
        $offPeakKwh = $totalKwh * (1 - self::PEAK_PERCENTAGE);
        
        $amount = ($peakKwh * self::PEAK_PRICE) 
                + ($offPeakKwh * self::OFFPEAK_PRICE) 
                + $fixedMonthly;
        
        return $amount;
    }
}

// Just add 2 lines to factory
if (strpos($tariffCode, 'TIME_OF_USE') === 0) {
    return new TimeOfUseTariffCalculator();
}

// ✅ NO changes to existing classes!
// ✅ Easy to test in isolation
// ✅ Easy to review
```

---

### 4. DEPENDENCY INJECTION & SEPARATION OF CONCERNS

#### ❌ BEFORE (Tightly Coupled)
```php
class InvoiceCalculator {
    private $db;  // ⚠️ Raw database object

    public function __construct($db) {
        $this->db = $db;  // ⚠️ Can do ANY SQL
    }

    public function calculate($contractId, $month) {
        // Everything mixed together
        
        // DB access
        $contract = $this->db->query(...)->fetch();
        $readings = $this->db->query(...)->fetch();
        
        // Business logic
        if (strpos(...)) { ... }
        
        // Tax calculation (should be separate)
        if ($contract['country'] == 'PT') { 
            $tax = $amount * 0.23; 
        }
        
        // More DB access
        $this->db->query("INSERT INTO invoices ...");
        
        // Output (should not be in business logic)
        echo "Invoice created: $total EUR";
        
        return $total;  // ⚠️ Inconsistent return type
    }
}

// Testing is impossible
$calculator = new InvoiceCalculator($realDb);  // ⚠️ Must use real database
$total = $calculator->calculate(123, '2026-03');  // ⚠️ Creates real data
```

#### ✅ AFTER (Loosely Coupled & Separated)
```php
// Each concern is handled by specific class
class InvoiceService {
    // All dependencies injected
    private ContractRepository $contractRepository;
    private MeterReadingRepository $meterRepository;
    private InvoiceRepository $invoiceRepository;
    private TariffCalculatorFactory $tariffFactory;
    private TaxCalculator $taxCalculator;
    private LoggerInterface $logger;

    public function __construct(
        ContractRepository $contractRepository,
        MeterReadingRepository $meterRepository,
        InvoiceRepository $invoiceRepository,
        TariffCalculatorFactory $tariffFactory,
        TaxCalculator $taxCalculator,
        LoggerInterface $logger
    ) {
        // ✅ Clear what's needed
        // ✅ Can be mocked
        // ✅ Each has single responsibility
        $this->contractRepository = $contractRepository;
        $this->meterRepository = $meterRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->tariffFactory = $tariffFactory;
        $this->taxCalculator = $taxCalculator;
        $this->logger = $logger;
    }

    public function createInvoice(
        int $contractId, 
        string $month
    ): Invoice {
        // Load contract
        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            throw new ContractNotFoundException(...);
        }

        // Load readings
        $totalKwh = $this->meterRepository->getTotalKwhForPeriod(
            $contractId, 
            $month
        );

        // Calculate amount (delegated to calculator)
        $calculator = $this->tariffFactory->createCalculator(
            $contract->getTariff()->getCode(),
            $month,
            $contract->getTariff()->getPricePerKwh()
        );
        $amount = $calculator->calculate(
            $totalKwh,
            $contract->getTariff()->getFixedMonthly()
        );

        // Calculate tax (delegated to tax calculator)
        $tax = $this->taxCalculator->calculateTax(
            $amount,
            $contract->getCountry()
        );

        // Create invoice object
        $invoice = new Invoice(
            $contractId,
            $month,
            $totalKwh,
            $amount + $tax,
            'draft'
        );

        // Persist (delegated to repository)
        $this->invoiceRepository->save($invoice);

        // Log (delegated to logger)
        $this->logger->info("Invoice created", [...]);

        // Return domain object (not string or false)
        return $invoice;
    }
}

// ✅ Testing is easy with mocks
$mockContractRepo = $this->createMock(ContractRepository::class);
$mockContractRepo
    ->method('findById')
    ->with(123)
    ->willReturn(new Contract(...));

$mockMeterRepo = $this->createMock(MeterReadingRepository::class);
$mockMeterRepo
    ->method('getTotalKwhForPeriod')
    ->with(123, '2026-03')
    ->willReturn(250.0);

$service = new InvoiceService(
    $mockContractRepo,      // Mock
    $mockMeterRepo,         // Mock
    $mockInvoiceRepo,       // Mock
    $tariffFactory,         // Real (no DB)
    $taxCalculator,         // Real (no DB)
    $logger                 // Mock
);

// ✅ Test without database!
$invoice = $service->createInvoice(123, '2026-03');
$this->assertEquals(150.50, $invoice->getTotalAmount());
```

---

### 5. EXTERNAL API HANDLING

#### ❌ BEFORE (Fragile)
```php
elseif (strpos($contract['tariff_code'], 'INDEX') !== false) {
    $spotPrice = file_get_contents(
        "https://api.energy-market.eu/spot?month=$month"  // ⚠️ No timeout
    );
    $spotData = json_decode($spotPrice, true);  // ⚠️ Might be null
    $amount = $totalKwh * $spotData['avg_price'];  // ⚠️ Might throw notice
    $amount += $contract['fixed_monthly'];
    if ($totalKwh > 500) {
        $amount = $amount * 0.95;
    }
}
```

**What can go wrong:**
- API is down → hangs forever (no timeout)
- API returns invalid JSON → `json_decode()` returns null
- `$spotData['avg_price']` doesn't exist → PHP warning/error
- No way to log or retry
- Everything continues as if price is undefined

#### ✅ AFTER (Robust)
```php
class EnergyMarketApiClient {
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        string $baseUrl = "https://api.energy-market.eu",
        int $timeout = 10  // ✅ Timeout configured
    ) {
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
    }

    public function getSpotPrice(string $month): float {
        $url = $this->baseUrl . "/spot?month=" . urlencode($month);

        // ✅ Set timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'method' => 'GET'
            ]
        ]);

        // ✅ Suppress warning, check result
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {  // ✅ Explicit error check
            throw new ExternalApiException(
                "Failed to fetch spot price from energy market API"
            );
        }

        // ✅ Validate JSON
        $data = json_decode($response, true);

        if (!$data || !isset($data['avg_price'])) {  // ✅ Explicit validation
            throw new ExternalApiException(
                "Invalid response from energy market API"
            );
        }

        return (float) $data['avg_price'];  // ✅ Explicit cast
    }
}

// In calculator
class IndexedTariffCalculator implements TariffCalculatorInterface {
    public function calculate(float $totalKwh, float $fixedMonthly): float {
        try {
            $spotPrice = $this->apiClient->getSpotPrice($this->month);  // ✅ Might throw
        } catch (\Exception $e) {
            throw new TariffCalculationException(  // ✅ Wrapped as domain exception
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

// In controller
try {
    $invoice = $this->invoiceService->createInvoice(123, '2026-03');
} catch (ExternalApiException | TariffCalculationException $e) {
    return new JsonResponse(
        ['status' => 'error', 'message' => 'Unable to calculate pricing'],
        Response::HTTP_SERVICE_UNAVAILABLE  // ✅ 503 - tell client API is down
    );
}
```

---

## Summary of Improvements

| Aspect | Before | After |
|--------|--------|-------|
| **SQL Security** | String concatenation | Parameterized queries |
| **Error Handling** | echo + return false | Typed exceptions |
| **Error Visibility** | No logging | PSR-3 logger integration |
| **HTTP Responses** | Always 200 | Proper status codes (404, 500, 503) |
| **Tariff Addition** | Edit calculate() method | New class, no existing code changes |
| **Testing** | Impossible without DB | Easy with mocks |
| **API Handling** | No timeout, no validation | Timeout, validation, wrapping |
| **Magic Numbers** | Hardcoded (0.9, 0.95, 500) | Named constants |
| **Separation** | Everything mixed | Clear layers |
| **Maintainability** | ⭐ Poor | ⭐⭐⭐⭐⭐ Excellent |
