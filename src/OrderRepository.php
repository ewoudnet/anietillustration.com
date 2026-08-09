<?php

declare(strict_types=1);

namespace App;

use PDO;
use Random\RandomException;

final class OrderRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = SpecialsDatabase::connection();
    }

    /**
     * @param array{firstName:string,lastName:string,street:string,houseNumber:string,postalCode:string,city:string,countryCode:string,email:string,quantity:int,unitPriceCents:int} $input
     * @param array{id:int,label:string} $variant
     */
    public function create(int $specialId, array $input, array $variant): array
    {
        $unitPriceCents = $input['unitPriceCents'];
        $totalCents = $unitPriceCents * $input['quantity'];

        $id = $this->insertRow([
            'order_reference' => $this->generateUniqueReference(),
            'order_type' => 'special',
            'special_id' => $specialId,
            'price_variant_id' => $variant['id'],
            'variant_label' => $variant['label'],
            'first_name' => $input['firstName'],
            'last_name' => $input['lastName'],
            'street' => $input['street'],
            'house_number' => $input['houseNumber'],
            'postal_code' => $input['postalCode'],
            'city' => $input['city'],
            'country_code' => strtoupper($input['countryCode']),
            'email' => $input['email'],
            'quantity' => $input['quantity'],
            'unit_price_cents' => $unitPriceCents,
            'total_amount_cents' => $totalCents,
            'currency' => 'EUR',
            'status' => 'open',
            'source' => 'online',
            'traffic_source' => TrafficSource::currentSource(),
            'notes' => null,
        ]);

        return $this->findById($id);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT o.*, s.title AS special_title FROM orders o
             LEFT JOIN specials s ON s.id = o.special_id
             WHERE o.deleted_at IS NULL
             ORDER BY o.created_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * @param array{q?: string, status?: string, special_id?: string} $filters
     * @return array<int, array<string,mixed>>
     */
    public function search(array $filters): array
    {
        $where = ['o.deleted_at IS NULL'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $searchColumns = ['o.order_reference', 'o.first_name', 'o.last_name', 'o.email', 'o.city'];
            $searchClauses = [];
            foreach ($searchColumns as $i => $column) {
                $searchClauses[] = "{$column} LIKE :q{$i}";
                $params["q{$i}"] = '%' . $q . '%';
            }
            $where[] = '(' . implode(' OR ', $searchClauses) . ')';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $status;
        }

        $specialId = trim((string) ($filters['special_id'] ?? ''));
        if ($specialId !== '') {
            $where[] = 'o.special_id = :special_id';
            $params['special_id'] = $specialId;
        }

        $sql = 'SELECT o.*, s.title AS special_title FROM orders o LEFT JOIN specials s ON s.id = o.special_id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, s.title AS special_title FROM orders o LEFT JOIN specials s ON s.id = o.special_id WHERE o.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, s.title AS special_title FROM orders o LEFT JOIN specials s ON s.id = o.special_id WHERE o.order_reference = :ref'
        );
        $stmt->execute(['ref' => $reference]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByMolliePaymentId(string $paymentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, s.title AS special_title FROM orders o LEFT JOIN specials s ON s.id = o.special_id WHERE o.mollie_payment_id = :pid'
        );
        $stmt->execute(['pid' => $paymentId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function attachMolliePayment(int $orderId, string $paymentId): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET mollie_payment_id = :pid WHERE id = :id');
        $stmt->execute(['pid' => $paymentId, 'id' => $orderId]);
    }

    public function updateStatus(int $orderId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $orderId]);
    }

    public function markConfirmationEmailSent(int $orderId): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET confirmation_email_sent_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
    }

    /**
     * @param array{specialId:?int,variantLabel:?string,firstName:string,lastName:string,street:string,houseNumber:string,postalCode:string,city:string,countryCode:string,email:string,quantity:int,unitPriceCents:int,status:string,notes:?string} $input
     */
    public function update(int $orderId, array $input): void
    {
        $totalCents = $input['unitPriceCents'] * $input['quantity'];

        $stmt = $this->db->prepare(
            'UPDATE orders SET
                special_id = :special_id,
                variant_label = :variant_label,
                first_name = :first_name,
                last_name = :last_name,
                street = :street,
                house_number = :house_number,
                postal_code = :postal_code,
                city = :city,
                country_code = :country_code,
                email = :email,
                quantity = :quantity,
                unit_price_cents = :unit_price_cents,
                total_amount_cents = :total_amount_cents,
                status = :status,
                notes = :notes
             WHERE id = :id'
        );

        $stmt->execute([
            'special_id' => $input['specialId'],
            'variant_label' => $input['variantLabel'],
            'first_name' => $input['firstName'],
            'last_name' => $input['lastName'],
            'street' => $input['street'],
            'house_number' => $input['houseNumber'],
            'postal_code' => $input['postalCode'],
            'city' => $input['city'],
            'country_code' => strtoupper($input['countryCode']),
            'email' => $input['email'],
            'quantity' => $input['quantity'],
            'unit_price_cents' => $input['unitPriceCents'],
            'total_amount_cents' => $totalCents,
            'status' => $input['status'],
            'notes' => $input['notes'],
            'id' => $orderId,
        ]);
    }

    /**
     * Soft delete - de order blijft in de database staan (o.a. voor de boekhouding/Mollie-
     * historie) maar verdwijnt uit alle overzichten en statistieken.
     */
    public function delete(int $orderId): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET deleted_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
    }

    /**
     * Bron-overzicht: bestellingen, betaalde bestellingen en omzet per bron, binnen een periode.
     *
     * @return array<int, array<string,mixed>>
     */
    public function statsBySource(?string $since = null, ?int $specialId = null): array
    {
        $params = ['source' => 'online'];
        $dateFilter = '';
        if ($since !== null) {
            $dateFilter = ' AND created_at >= :since';
            $params['since'] = $since;
        }

        $specialFilter = '';
        if ($specialId !== null) {
            $specialFilter = ' AND special_id = :special_id';
            $params['special_id'] = $specialId;
        }

        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(traffic_source, 'Onbekend') AS traffic_source,
                COUNT(*) AS orders,
                SUM(status = 'paid') AS paid_orders,
                SUM(CASE WHEN status = 'paid' THEN total_amount_cents ELSE 0 END) AS paid_revenue_cents
             FROM orders
             WHERE source = :source AND deleted_at IS NULL{$dateFilter}{$specialFilter}
             GROUP BY traffic_source
             ORDER BY orders DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function insertRow(array $row): int
    {
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $c) => ':' . $c, $columns);

        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO orders (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        ));
        $stmt->execute($row);

        return (int) $this->db->lastInsertId();
    }

    private function generateUniqueReference(): string
    {
        do {
            $reference = 'SP-' . date('Y') . '-' . $this->randomCode(6);
        } while ($this->findByReference($reference) !== null);

        return $reference;
    }

    private function randomCode(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        try {
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } catch (RandomException) {
            $code = strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
        }

        return $code;
    }
}
