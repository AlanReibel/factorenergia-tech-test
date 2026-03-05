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
