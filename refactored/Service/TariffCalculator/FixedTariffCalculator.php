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
