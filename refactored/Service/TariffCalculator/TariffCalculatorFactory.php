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
        string $month,
        float $pricePerKwh
    ): TariffCalculatorInterface {
        
        // FIX tariff family
        if (strpos($tariffCode, 'FIX') === 0) {
            if ($tariffCode === 'FIX_PROMO') {
                return new FixedPromoTariffCalculator($pricePerKwh);
            }
            return new FixedTariffCalculator($pricePerKwh);
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
}
