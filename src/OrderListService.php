<?php

declare(strict_types=1);

namespace App;

/**
 * Eén samengevoegde, gefilterde bestellijst voor de backend (pagina + Excel-export):
 * bestellingen uit het nieuwe specials-systeem plus de (alleen-lezen) bestellingen uit het
 * oude advent-systeem. Pagina, tellers en export gebruiken dezelfde lijst, zodat ze altijd
 * overeenkomen.
 */
final class OrderListService
{
    public const LEGACY_TYPE_LABELS = [
        'advent' => 'Adventkalender (oud)',
        'kalender2027' => 'Kalender 2027 (oud)',
    ];

    /**
     * @param array{q?: string, status?: string, special_id?: string} $filters special_id is een
     *        id van een special, of "legacy:{product_type}" voor het oude advent-systeem.
     * @return array<int, array<string, mixed>> Nieuwste eerst. Oude orders hebben `is_legacy = true`.
     */
    public static function fetch(array $filters): array
    {
        $specialFilter = trim((string) ($filters['special_id'] ?? ''));
        $legacyOnly = str_starts_with($specialFilter, 'legacy:');
        $orderRepository = new OrderRepository();

        $orders = [];
        if (!$legacyOnly) {
            $hasFilters = (bool) array_filter($filters, static fn ($v): bool => trim((string) $v) !== '');
            $orders = $hasFilters ? $orderRepository->search($filters) : $orderRepository->findAll();
        }

        $legacy = [];
        if ($legacyOnly || $specialFilter === '') {
            $legacy = self::filterLegacy(
                (new AdventOrderRepository())->findAll(),
                trim((string) ($filters['q'] ?? '')),
                trim((string) ($filters['status'] ?? '')),
                $legacyOnly ? substr($specialFilter, 7) : ''
            );
        }

        $all = array_merge($orders, $legacy);
        usort($all, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $all;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function filterLegacy(array $rows, string $q, string $status, string $productType): array
    {
        $result = [];

        foreach ($rows as $row) {
            if ($status !== '' && $row['status'] !== $status) {
                continue;
            }
            if ($productType !== '' && $row['product_type'] !== $productType) {
                continue;
            }
            if ($q !== '') {
                $haystack = implode(' ', [$row['order_reference'], $row['first_name'], $row['last_name'], $row['email'], $row['city']]);
                if (mb_stripos($haystack, $q) === false) {
                    continue;
                }
            }

            $row['is_legacy'] = true;
            $row['special_title'] = self::LEGACY_TYPE_LABELS[$row['product_type']] ?? ucfirst((string) $row['product_type']);
            $row['variant_label'] = null;
            $row['is_digital'] = 0;
            $row['download_count'] = 0;
            $row['last_downloaded_at'] = null;
            $result[] = $row;
        }

        return $result;
    }
}
