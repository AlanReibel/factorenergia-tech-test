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
     * Get spot price for a given month
     * 
     * @param string $month Month in format: YYYY-MM
     * @return float Average spot price per kWh
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
