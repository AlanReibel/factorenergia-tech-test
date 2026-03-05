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
