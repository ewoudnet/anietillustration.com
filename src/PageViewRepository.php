<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Logt paginaweergaves van de publieke specials-pagina's en levert bron-statistieken,
 * overgenomen van adventskaarten-bestellen/src/PageViewRepository.php met special_id
 * i.p.v. product_type.
 */
final class PageViewRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = SpecialsDatabase::connection();
    }

    /**
     * @param array{source:string, sessionId:string, utmSource:?string, utmMedium:?string, utmCampaign:?string, referrer:?string} $resolved
     */
    public function log(string $path, array $resolved, ?int $specialId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO page_views (path, special_id, referrer, utm_source, utm_medium, utm_campaign, source, session_id)
             VALUES (:path, :special_id, :referrer, :utm_source, :utm_medium, :utm_campaign, :source, :session_id)'
        );

        $stmt->execute([
            'path' => $path,
            'special_id' => $specialId,
            'referrer' => $resolved['referrer'],
            'utm_source' => $resolved['utmSource'],
            'utm_medium' => $resolved['utmMedium'],
            'utm_campaign' => $resolved['utmCampaign'],
            'source' => $resolved['source'],
            'session_id' => $resolved['sessionId'],
        ]);
    }

    /**
     * Bron-overzicht: bezoeken en unieke bezoekers per bron, binnen een periode.
     *
     * @return array<int, array<string,mixed>>
     */
    public function statsBySource(?string $since = null, ?int $specialId = null): array
    {
        $params = [];
        $dateFilter = '';
        if ($since !== null) {
            $dateFilter = ' AND visited_at >= :since';
            $params['since'] = $since;
        }

        $specialFilter = '';
        if ($specialId !== null) {
            $specialFilter = ' AND special_id = :special_id';
            $params['special_id'] = $specialId;
        }

        $stmt = $this->db->prepare(
            "SELECT source,
                    COUNT(*) AS views,
                    COUNT(DISTINCT session_id) AS visitors
             FROM page_views
             WHERE 1=1{$dateFilter}{$specialFilter}
             GROUP BY source
             ORDER BY views DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function totalViews(?string $since = null, ?int $specialId = null): int
    {
        [$where, $params] = $this->whereClause($since, $specialId);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM page_views{$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function totalVisitors(?string $since = null, ?int $specialId = null): int
    {
        [$where, $params] = $this->whereClause($since, $specialId);

        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT session_id) FROM page_views{$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string,int|string>}
     */
    private function whereClause(?string $since, ?int $specialId): array
    {
        $conditions = [];
        $params = [];

        if ($since !== null) {
            $conditions[] = 'visited_at >= :since';
            $params['since'] = $since;
        }

        if ($specialId !== null) {
            $conditions[] = 'special_id = :special_id';
            $params['special_id'] = $specialId;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }
}
