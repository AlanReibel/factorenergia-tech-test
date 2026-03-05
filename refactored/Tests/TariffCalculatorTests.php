<?php

namespace App\Tests\Service\TariffCalculator;

use PHPUnit\Framework\TestCase;
use App\Service\TariffCalculator\FixedTariffCalculator;
use App\Service\TariffCalculator\FixedPromoTariffCalculator;
use App\Service\TariffCalculator\IndexedTariffCalculator;
use App\Service\TariffCalculator\FlatRateTariffCalculator;
use App\Service\TariffCalculator\TariffCalculatorFactory;
use App\Service\EnergyMarketApiClient;
use App\Exception\TariffCalculationException;
use App\Exception\UnknownTariffException;

/**
 * Unit Tests for Tariff Calculators
 * 
 * Why test these?
 * - Each calculator has different logic
 * - Easy to isolate (no databases, no HTTP)
 * - Good coverage of business logic
 */
class FixedTariffCalculatorTest extends TestCase
{
    public function testCalculateFixedTariff(): void
    {
        $calculator = new FixedTariffCalculator(0.12); // €0.12/kWh
        
        $amount = $calculator->calculate(
            totalKwh: 100,
            fixedMonthly: 10
        );
        
        // (100 * 0.12) + 10 = 12 + 10 = 22
        $this->assertEquals(22.0, $amount);
    }

    public function testCalculateFixedTariffWithZeroKwh(): void
    {
        $calculator = new FixedTariffCalculator(0.12);
        
        $amount = $calculator->calculate(0, 15);
        
        // Only fixed charge
        $this->assertEquals(15.0, $amount);
    }
}

class FixedPromoTariffCalculatorTest extends TestCase
{
    public function testCalculateWithPromoDiscount(): void
    {
        $calculator = new FixedPromoTariffCalculator(0.12);
        
        $amount = $calculator->calculate(
            totalKwh: 100,
            fixedMonthly: 10
        );
        
        // (100 * 0.12 + 10) * 0.9 = 22 * 0.9 = 19.8
        $this->assertEquals(19.8, $amount);
    }
}

class IndexedTariffCalculatorTest extends TestCase
{
    public function testCalculateWithSpotPrice(): void
    {
        // Mock the API client
        $apiClient = $this->createMock(EnergyMarketApiClient::class);
        $apiClient
            ->method('getSpotPrice')
            ->with('2026-03')
            ->willReturn(0.25); // €0.25/kWh spot price

        $calculator = new IndexedTariffCalculator($apiClient, '2026-03');
        
        $amount = $calculator->calculate(
            totalKwh: 100,
            fixedMonthly: 10
        );
        
        // (100 * 0.25) + 10 = 25 + 10 = 35
        $this->assertEquals(35.0, $amount);
    }

    public function testApplyBulkDiscountWhenOver500Kwh(): void
    {
        $apiClient = $this->createMock(EnergyMarketApiClient::class);
        $apiClient->method('getSpotPrice')->willReturn(0.25);

        $calculator = new IndexedTariffCalculator($apiClient, '2026-03');
        
        $amount = $calculator->calculate(
            totalKwh: 600, // Over 500 threshold
            fixedMonthly: 10
        );
        
        // (600 * 0.25 + 10) * 0.95 = 160 * 0.95 = 152.0
        $this->assertEquals(152.0, $amount);
    }

    public function testThrowExceptionWhenApiFailsTest(): void
    {
        $apiClient = $this->createMock(EnergyMarketApiClient::class);
        $apiClient
            ->method('getSpotPrice')
            ->willThrowException(
                new \Exception("API timeout")
            );

        $calculator = new IndexedTariffCalculator($apiClient, '2026-03');
        
        $this->expectException(TariffCalculationException::class);
        $calculator->calculate(100, 10);
    }
}

class FlatRateTariffCalculatorTest extends TestCase
{
    public function testFlatRateIgnoresKwh(): void
    {
        $calculator = new FlatRateTariffCalculator();
        
        // Regardless of kWh, only return fixed monthly
        $this->assertEquals(50.0, $calculator->calculate(0, 50));
        $this->assertEquals(50.0, $calculator->calculate(1000, 50));
        $this->assertEquals(50.0, $calculator->calculate(100, 50));
    }
}

class TariffCalculatorFactoryTest extends TestCase
{
    private TariffCalculatorFactory $factory;

    protected function setUp(): void
    {
        $apiClient = $this->createMock(EnergyMarketApiClient::class);
        $this->factory = new TariffCalculatorFactory($apiClient);
    }

    public function testCreateFixedTariffCalculator(): void
    {
        $calculator = $this->factory->createCalculator('FIX', '2026-03', 0.12);
        $this->assertInstanceOf(FixedTariffCalculator::class, $calculator);
    }

    public function testCreateFixedPromoTariffCalculator(): void
    {
        $calculator = $this->factory->createCalculator('FIX_PROMO', '2026-03', 0.12);
        $this->assertInstanceOf(FixedPromoTariffCalculator::class, $calculator);
    }

    public function testCreateIndexedTariffCalculator(): void
    {
        $calculator = $this->factory->createCalculator('INDEX', '2026-03', 0.12);
        $this->assertInstanceOf(IndexedTariffCalculator::class, $calculator);
    }

    public function testCreateFlatRateTariffCalculator(): void
    {
        $calculator = $this->factory->createCalculator('FLAT_RATE', '2026-03', 0.12);
        $this->assertInstanceOf(FlatRateTariffCalculator::class, $calculator);
    }

    public function testThrowExceptionForUnknownTariff(): void
    {
        $this->expectException(UnknownTariffException::class);
        $this->factory->createCalculator('UNKNOWN_TYPE', '2026-03', 0.12);
    }
}
