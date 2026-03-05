# EXERCISE 2.2 - Refactored Code

## Arquitectura - Flujo en capas

```
InvoiceController (HTTP)
         ↓
  InvoiceService (Lógica)
    ↙         ↘
Repository   TariffCalculator
   ↓              (Strategy)
Entity        
```

---

## 1. Entities

### Contract.php
```php
<?php

namespace App\Entity;

class Contract
{
    private int $id;
    private int $tariffId;
    private string $country;
    private Tariff $tariff;

    public function __construct(
        int $id,
        int $tariffId,
        string $country,
        Tariff $tariff
    ) {
        $this->id = $id;
        $this->tariffId = $tariffId;
        $this->country = $country;
        $this->tariff = $tariff;
    }

    public function getId(): int { return $this->id; }
    public function getTariffId(): int { return $this->tariffId; }
    public function getCountry(): string { return $this->country; }
    public function getTariff(): Tariff { return $this->tariff; }
}
```

### Tariff.php
```php
<?php

namespace App\Entity;

class Tariff
{
    private int $id;
    private string $code;
    private float $pricePerKwh;
    private float $fixedMonthly;

    public function __construct(
        int $id,
        string $code,
        float $pricePerKwh,
        float $fixedMonthly
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->pricePerKwh = $pricePerKwh;
        $this->fixedMonthly = $fixedMonthly;
    }

    public function getId(): int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getPricePerKwh(): float { return $this->pricePerKwh; }
    public function getFixedMonthly(): float { return $this->fixedMonthly; }
}
```

### Invoice.php
```php
<?php

namespace App\Entity;

class Invoice
{
    private int $id;
    private int $contractId;
    private string $billingPeriod;
    private float $totalKwh;
    private float $totalAmount;
    private string $status;

    public function __construct(
        int $contractId,
        string $billingPeriod,
        float $totalKwh,
        float $totalAmount,
        string $status = 'draft'
    ) {
        $this->contractId = $contractId;
        $this->billingPeriod = $billingPeriod;
        $this->totalKwh = $totalKwh;
        $this->totalAmount = $totalAmount;
        $this->status = $status;
    }

    public function getId(): int { return $this->id; }
    public function getContractId(): int { return $this->contractId; }
    public function getBillingPeriod(): string { return $this->billingPeriod; }
    public function getTotalKwh(): float { return $this->totalKwh; }
    public function getTotalAmount(): float { return $this->totalAmount; }
    public function getStatus(): string { return $this->status; }
}
```

---

## 2. Repositories (DB Access)

### ContractRepository.php
```php
<?php

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Tariff;
use PDO;

class ContractRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $contractId): ?Contract
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.tariff_id, c.country, 
                    t.id as tariff_id, t.code, t.price_per_kwh, t.fixed_monthly
             FROM contracts c 
             JOIN tariffs t ON c.tariff_id = t.id
             WHERE c.id = :contract_id"
        );

        $stmt->execute(['contract_id' => $contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $tariff = new Tariff(
            $row['tariff_id'],
            $row['code'],
            $row['price_per_kwh'],
            $row['fixed_monthly']
        );

        return new Contract(
            $row['id'],
            $row['tariff_id'],
            $row['country'],
            $tariff
        );
    }
}
```

### MeterReadingRepository.php
```php
<?php

namespace App\Repository;

use PDO;

class MeterReadingRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getTotalKwhForPeriod(int $contractId, string $month): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(kwh_consumed), 0) as total
             FROM meter_readings
             WHERE contract_id = :contract_id
             AND FORMAT(reading_date, 'yyyy-MM') = :month"
        );

        $stmt->execute([
            'contract_id' => $contractId,
            'month' => $month
        ]);

        return (float) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }
}
```

### InvoiceRepository.php
```php
<?php

namespace App\Repository;

use App\Entity\Invoice;
use PDO;

class InvoiceRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(Invoice $invoice): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO invoices 
             (contract_id, billing_period, total_kwh, total_amount, status)
             VALUES (:contract_id, :billing_period, :total_kwh, :total_amount, :status)"
        );

        $stmt->execute([
            'contract_id' => $invoice->getContractId(),
            'billing_period' => $invoice->getBillingPeriod(),
            'total_kwh' => $invoice->getTotalKwh(),
            'total_amount' => $invoice->getTotalAmount(),
            'status' => $invoice->getStatus()
        ]);
    }
}
```

---

## 3. Strategy Pattern - Tariff Calculators

### TariffCalculatorInterface.php
```php
<?php

namespace App\Service\TariffCalculator;

interface TariffCalculatorInterface
{
    /**
     * Calculate invoice amount for a tariff
     * @throws TariffCalculationException
     */
    public function calculate(float $totalKwh, float $fixedMonthly): float;
}
```

### FixedTariffCalculator.php
```php
<?php

namespace App\Service\TariffCalculator;

class FixedTariffCalculator implements TariffCalculatorInterface
{
    private float $pricePerKwh;

    public function __construct(float $pricePerKwh)
    {
        $this->pricePerKwh = $pricePerKwh;
    }

    public function calculate(float $totalKwh, float $fixedMonthly): float
    {
        return ($totalKwh * $this->pricePerKwh) + $fixedMonthly;
    }
}
```

### FixedPromoTariffCalculator.php
```php
<?php

namespace App\Service\TariffCalculator;

class FixedPromoTariffCalculator implements TariffCalculatorInterface
{
    private float $pricePerKwh;
    private const PROMO_DISCOUNT = 0.9; // 10% discount

    public function __construct(float $pricePerKwh)
    {
        $this->pricePerKwh = $pricePerKwh;
    }

    public function calculate(float $totalKwh, float $fixedMonthly): float
    {
        $amount = ($totalKwh * $this->pricePerKwh) + $fixedMonthly;
        return $amount * self::PROMO_DISCOUNT;
    }
}
```

### IndexedTariffCalculator.php
```php
<?php

namespace App\Service\TariffCalculator;

use App\Service\EnergyMarketApiClient;
use App\Exception\TariffCalculationException;

class IndexedTariffCalculator implements TariffCalculatorInterface
{
    private EnergyMarketApiClient $apiClient;
    private string $month;
    private const BULK_DISCOUNT_THRESHOLD = 500;
    private const BULK_DISCOUNT = 0.95; // 5% discount

    public function __construct(
        EnergyMarketApiClient $apiClient,
        string $month
    ) {
        $this->apiClient = $apiClient;
        $this->month = $month;
    }

    public function calculate(float $totalKwh, float $fixedMonthly): float
    {
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

        // Apply bulk discount if applicable
        if ($totalKwh > self::BULK_DISCOUNT_THRESHOLD) {
            $amount *= self::BULK_DISCOUNT;
        }

        return $amount;
    }
}
```

### FlatRateTariffCalculator.php
```php
<?php

namespace App\Service\TariffCalculator;

class FlatRateTariffCalculator implements TariffCalculatorInterface
{
    public function calculate(float $totalKwh, float $fixedMonthly): float
    {
        return $fixedMonthly;
    }
}
```

### TariffCalculatorFactory.php
```php
<?php

namespace App\Service\TariffCalculator;

use App\Service\EnergyMarketApiClient;
use App\Exception\UnknownTariffException;

class TariffCalculatorFactory
{
    private EnergyMarketApiClient $apiClient;

    public function __construct(EnergyMarketApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    public function createCalculator(
        string $tariffCode,
        string $month
    ): TariffCalculatorInterface {
        
        // FIX tariff family
        if (strpos($tariffCode, 'FIX') === 0) {
            if ($tariffCode === 'FIX_PROMO') {
                return new FixedPromoTariffCalculator($this->getPricePerKwh($tariffCode));
            }
            return new FixedTariffCalculator($this->getPricePerKwh($tariffCode));
        }

        // INDEX tariff family
        if (strpos($tariffCode, 'INDEX') === 0) {
            return new IndexedTariffCalculator($this->apiClient, $month);
        }

        // FLAT_RATE tariff
        if ($tariffCode === 'FLAT_RATE') {
            return new FlatRateTariffCalculator();
        }

        throw new UnknownTariffException(
            "Unknown tariff type: $tariffCode"
        );
    }

    private function getPricePerKwh(string $tariffCode): float
    {
        // This would come from the Tariff entity in real implementation
        // Placeholder for now
        return 0.12;
    }
}
```

---

## 4. API Client (External Dependencies)

### EnergyMarketApiClient.php
```php
<?php

namespace App\Service;

use App\Exception\ExternalApiException;

class EnergyMarketApiClient
{
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        string $baseUrl = "https://api.energy-market.eu",
        int $timeout = 10
    ) {
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
    }

    /**
     * @throws ExternalApiException
     */
    public function getSpotPrice(string $month): float
    {
        $url = $this->baseUrl . "/spot?month=" . urlencode($month);

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
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
```

---

## 5. Main Service (Business Logic)

### InvoiceService.php
```php
<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Repository\ContractRepository;
use App\Repository\InvoiceRepository;
use App\Repository\MeterReadingRepository;
use App\Service\TariffCalculator\TariffCalculatorFactory;
use App\Exception\ContractNotFoundException;
use App\Exception\TariffCalculationException;
use Psr\Log\LoggerInterface;

class InvoiceService
{
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
        $this->contractRepository = $contractRepository;
        $this->meterRepository = $meterRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->tariffFactory = $tariffFactory;
        $this->taxCalculator = $taxCalculator;
        $this->logger = $logger;
    }

    /**
     * Calculate and create an invoice
     * 
     * @throws ContractNotFoundException
     * @throws TariffCalculationException
     * @throws ExternalApiException
     */
    public function createInvoice(int $contractId, string $month): Invoice
    {
        // Load contract
        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            $this->logger->warning("Contract not found", ['contract_id' => $contractId]);
            throw new ContractNotFoundException(
                "Contract with ID $contractId not found"
            );
        }

        // Get meter readings
        $totalKwh = $this->meterRepository->getTotalKwhForPeriod($contractId, $month);

        // Calculate amount using strategy pattern
        $tariff = $contract->getTariff();
        $calculator = $this->tariffFactory->createCalculator(
            $tariff->getCode(),
            $month
        );

        $amount = $calculator->calculate(
            $totalKwh,
            $tariff->getFixedMonthly()
        );

        // Calculate tax
        $tax = $this->taxCalculator->calculateTax(
            $amount,
            $contract->getCountry()
        );

        $totalAmount = $amount + $tax;

        // Create and persist invoice
        $invoice = new Invoice(
            $contractId,
            $month,
            $totalKwh,
            $totalAmount,
            'draft'
        );

        $this->invoiceRepository->save($invoice);

        $this->logger->info(
            "Invoice created",
            [
                'contract_id' => $contractId,
                'month' => $month,
                'total' => $totalAmount,
                'kwh' => $totalKwh
            ]
        );

        return $invoice;
    }
}
```

### TaxCalculator.php
```php
<?php

namespace App\Service;

class TaxCalculator
{
    // Tax rates by country
    private const TAX_RATES = [
        'PT' => 0.23,  // Portugal
        'ES' => 0.21,  // Spain (default for others too)
    ];

    public function calculateTax(float $amount, string $country): float
    {
        $rate = self::TAX_RATES[$country] ?? 0.21; // Default to 21%
        return $amount * $rate;
    }
}
```

---

## 6. Exceptions

### ContractNotFoundException.php
```php
<?php

namespace App\Exception;

class ContractNotFoundException extends \Exception {}
```

### UnknownTariffException.php
```php
<?php

namespace App\Exception;

class UnknownTariffException extends \Exception {}
```

### TariffCalculationException.php
```php
<?php

namespace App\Exception;

class TariffCalculationException extends \Exception {}
```

### ExternalApiException.php
```php
<?php

namespace App\Exception;

class ExternalApiException extends \Exception {}
```

---

## 7. Controller Example

### InvoiceController.php
```php
<?php

namespace App\Controller;

use App\Service\InvoiceService;
use App\Exception\ContractNotFoundException;
use App\Exception\TariffCalculationException;
use App\Exception\ExternalApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class InvoiceController extends AbstractController
{
    private InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    public function createAction(int $contractId, string $month): JsonResponse
    {
        try {
            $invoice = $this->invoiceService->createInvoice($contractId, $month);

            return new JsonResponse([
                'status' => 'success',
                'data' => [
                    'id' => $invoice->getId(),
                    'total' => $invoice->getTotalAmount(),
                    'kwh' => $invoice->getTotalKwh(),
                ]
            ]);

        } catch (ContractNotFoundException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Contract not found'
            ], 404);

        } catch (TariffCalculationException | ExternalApiException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Failed to calculate invoice'
            ], 500);
        }
    }
}
```

---

## 8. Unit Testing Strategy

### What to test:

1. **TariffCalculators** (cada calculadora):
   ```php
   // Test FixedTariffCalculator
   - calculate(100, 10) = (100 * price) + 10
   
   // Test FixedPromoTariffCalculator
   - Apply 10% discount correctly
   
   // Test IndexedTariffCalculator
   - Use API spot price
   - Apply bulk discount correctly when kwh > 500
   - Handle API failures with exceptions
   
   // Test FlatRateTariffCalculator
   - Always return fixedMonthly
   ```

2. **TariffCalculatorFactory**:
   ```php
   - Create correct calculator for each tariff code
   - Throw UnknownTariffException for unknown codes
   ```

3. **InvoiceService**:
   ```php
   - Throw ContractNotFoundException when contract not found
   - Calculate correct total (amount + tax)
   - Persist invoice correctly
   - Log appropriately
   ```

4. **TaxCalculator**:
   ```php
   - Calculate 23% tax for Portugal
   - Calculate 21% tax for others
   ```

5. **Repositories**:
   ```php
   - Return correct entities from queries
   - Handle missing data correctly
   - Use parameterized queries (verify SQL safety)
   ```

### NOT needed (integration/e2e):
- Full HTTP flow
- Actual database operations
- Real API calls (mock them)

---

## Explicación de Diseño - Cómo agregar nuevas tarifas

### Problema original:
```php
if (strpos(...) === 0) { ... }
elseif (strpos(...) === 0) { ... }
elseif ($code == '...') { ... }
else { throw }
```
- Cada tarifa nueva requiere modificar InvoiceCalculator
- Violación Open/Closed Principle

### Solución: Strategy Pattern + Factory

**Para agregar una nueva tarifa "TIME_OF_USE":**

1. **Crear la clase calculadora:**
```php
class TimeOfUseTariffCalculator implements TariffCalculatorInterface
{
    // Lógica específica de peak/off-peak
    public function calculate(float $totalKwh, float $fixedMonthly): float
    {
        // Peak hours: 0.25€/kwh
        // Off-peak: 0.10€/kwh
        return $peakAmount + $offPeakAmount + $fixedMonthly;
    }
}
```

2. **Registrar en Factory (1 línea):**
```php
if (strpos($tariffCode, 'TIME_OF_USE') === 0) {
    return new TimeOfUseTariffCalculator(...);
}
```

**Ventajas:**
- ✅ Cada tarifa es una clase independiente
- ✅ Fácil testear cada una por separado
- ✅ Nueva tarifa = nueva clase, sin modificar existentes
- ✅ Factory es el único lugar a modificar
- ✅ Sigue Single Responsibility Principle
- ✅ Código duplicado eliminado

