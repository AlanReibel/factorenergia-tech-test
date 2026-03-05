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

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) $result['total'];
    }
}
