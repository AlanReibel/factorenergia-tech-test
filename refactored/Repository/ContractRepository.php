<?php

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Tariff;
use PDO;

class ContractRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $contractId): ?Contract
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.tariff_id, c.country, 
                    c.nif, c.cups, c.street_address, c.city, c.postal_code,
                    c.start_date, c.estimated_annual_kwh,
                    t.id as tariff_id, t.code, t.price_per_kwh, t.fixed_monthly
             FROM contracts c 
             JOIN tariffs t ON c.tariff_id = t.id
             WHERE c.id = :contract_id"
        );

        $stmt->execute(['contract_id' => $contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $tariff = new Tariff(
            (int) $row['tariff_id'],
            $row['code'],
            (float) $row['price_per_kwh'],
            (float) $row['fixed_monthly']
        );

        $startDate = new \DateTimeImmutable($row['start_date']);

        return new Contract(
            (int) $row['id'],
            (int) $row['tariff_id'],
            $row['country'],
            $tariff,
            $row['nif'],
            $row['cups'],
            $row['street_address'],
            $row['city'],
            $row['postal_code'],
            $startDate,
            (float) $row['estimated_annual_kwh']
        );
    }
}
