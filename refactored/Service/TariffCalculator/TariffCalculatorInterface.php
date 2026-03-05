<?php

namespace App\Service\TariffCalculator;

interface TariffCalculatorInterface
{
    /**
     * Calculate invoice amount for a tariff
     * 
     * @param float $totalKwh Total kWh consumed
     * @param float $fixedMonthly Fixed monthly charge
     * @return float Calculated amount (before tax)
     * @throws TariffCalculationException
     */
    public function calculate(float $totalKwh, float $fixedMonthly): float;
}
