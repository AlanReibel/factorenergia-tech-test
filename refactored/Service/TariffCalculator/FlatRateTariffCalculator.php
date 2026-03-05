<?php

namespace App\Service\TariffCalculator;

class FlatRateTariffCalculator implements TariffCalculatorInterface
{
    public function calculate(float $totalKwh, float $fixedMonthly): float
    {
        return $fixedMonthly;
    }
}
