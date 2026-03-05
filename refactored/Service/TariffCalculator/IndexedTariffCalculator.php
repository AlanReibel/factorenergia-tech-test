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
