<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;
use App\ProductRepository;
use App\ProductTypeRepository;
use App\SalesChannelRepository;
use App\XlsxReader;
use App\XlsxWriter;

Auth::requireAdmin();

$activeSection = 'settings';
$activePage = 'backup';
$csrfToken = Csrf::token();

// ---------------------------------------------------------------------------
// Export (beschikbaar voor iedereen met toegang tot deze sectie)
// ---------------------------------------------------------------------------

if (($_GET['download'] ?? '') === '1') {
    $cards = CardRepository::search([]);
    $channels = SalesChannelRepository::findAll();
    $productTypes = ProductTypeRepository::findAll();

    $writer = new XlsxWriter();

    $cardRows = [[
        'id', 'sku', 'titel', 'formaat', 'type_kaart', 'envelop', 'envelop_kleur',
        'minimale_voorraad', 'huidige_voorraad', 'te_bestellen', 'wholesale_draft', 'verkoopkanalen',
        'comments', 'greetz_sectie', 'submission_date', 'rejected_date', 'psd_filename',
        'datum_creatie',
    ]];
    foreach ($cards as $c) {
        $channelNames = implode(', ', array_map(static fn (array $ch): string => $ch['name'], $c['sales_channels']));
        $cardRows[] = [
            (int) $c['id'],
            $c['sku'],
            $c['title'],
            $c['format'] ?? '',
            $c['card_type'],
            $c['has_envelope'] === null ? '' : ((int) $c['has_envelope'] === 1 ? 'ja' : 'nee'),
            $c['envelope_color'] ?? '',
            (int) $c['min_stock'],
            $c['current_stock'] !== null ? (int) $c['current_stock'] : '',
            (int) $c['to_order'],
            (int) $c['wholesale_draft'] === 1 ? 'ja' : 'nee',
            $channelNames,
            $c['comments'] ?? '',
            $c['greetz_type'] ?? '',
            $c['submission_date'] !== null ? (new DateTime($c['submission_date']))->format('d-m-Y') : '',
            $c['rejected_date'] !== null ? (new DateTime($c['rejected_date']))->format('d-m-Y') : '',
            $c['psd_filename'] ?? '',
            (new DateTime($c['created_at']))->format('d-m-Y H:i'),
        ];
    }
    $writer->addSheet('Kaarten', $cardRows);

    $ptRows = [['id', 'naam', 'comments']];
    foreach ($productTypes as $pt) {
        $ptRows[] = [(int) $pt['id'], $pt['name'], $pt['comments'] ?? ''];
    }
    $writer->addSheet('Producttypes', $ptRows);

    $scRows = [['id', 'naam', 'afkorting', 'kleur', 'volgorde', 'actief', 'producttypes', 'comments']];
    foreach ($channels as $sc) {
        $scRows[] = [
            (int) $sc['id'],
            $sc['name'],
            $sc['abbreviation'],
            $sc['color'],
            (int) $sc['sort_order'],
            (int) $sc['active'] === 1 ? 'ja' : 'nee',
            implode(', ', $sc['product_type_names']),
            $sc['comments'] ?? '',
        ];
    }
    $writer->addSheet('Sale channels', $scRows);

    $productRows = [['id', 'producttype', 'sku', 'titel', 'minimale_voorraad', 'huidige_voorraad', 'te_bestellen', 'wholesale_draft', 'comments', 'datum_creatie']];
    foreach (ProductRepository::findAllWithTypeName() as $p) {
        $productRows[] = [
            (int) $p['id'],
            $p['product_type_name'],
            $p['sku'],
            $p['title'],
            (int) $p['min_stock'],
            $p['current_stock'] !== null ? (int) $p['current_stock'] : '',
            (int) $p['to_order'],
            (int) $p['wholesale_draft'] === 1 ? 'ja' : 'nee',
            $p['comments'] ?? '',
            (new DateTime($p['created_at']))->format('d-m-Y H:i'),
        ];
    }
    $writer->addSheet('Producten', $productRows);

    $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx');
    $writer->save($tmpPath);

    $filename = 'backoffice-export-' . date('Y-m-d_His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($tmpPath));
    readfile($tmpPath);
    unlink($tmpPath);
    exit;
}

// ---------------------------------------------------------------------------
// Import (alleen beheerders) - upsert op sku/naam, verwijdert nooit iets
// ---------------------------------------------------------------------------

$errors = [];
$plan = null;
$committedCounts = null;

/**
 * @param array<int, array<int, string>> $rows
 * @return array<int, array<string, string>>
 */
function xlsxRowsAsAssoc(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $header = array_map(static fn (string $h): string => strtolower(trim($h)), $rows[0]);
    $result = [];

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $hasContent = false;
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                $hasContent = true;
                break;
            }
        }
        if (!$hasContent) {
            continue;
        }

        $assoc = [];
        foreach ($header as $colIndex => $name) {
            $assoc[$name] = trim((string) ($row[$colIndex] ?? ''));
        }
        $result[] = $assoc;
    }

    return $result;
}

function yesNoToInt(string $value): ?int
{
    $value = strtolower(trim($value));

    return match ($value) {
        'ja' => 1,
        'nee' => 0,
        default => null,
    };
}

/**
 * @param array<int, array<int, string>> $rows
 * @return array{new: array, updated: array, unchanged: array, skipped: array}
 */
function buildCardPlan(array $rows): array
{
    $channelIdByName = [];
    foreach (SalesChannelRepository::findAll() as $channel) {
        $channelIdByName[strtolower($channel['name'])] = (int) $channel['id'];
    }

    $plan = ['new' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []];
    $seenSkus = [];

    foreach (xlsxRowsAsAssoc($rows) as $rowNum => $row) {
        $excelRow = $rowNum + 2;
        $sku = $row['sku'] ?? '';
        if ($sku === '') {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => '', 'reason' => 'Geen SKU ingevuld.'];
            continue;
        }

        $skuKey = strtolower($sku);
        if (isset($seenSkus[$skuKey])) {
            $plan['skipped'][] = [
                'row' => $excelRow, 'sku' => $sku,
                'reason' => 'SKU komt al eerder voor in dit bestand (rij ' . $seenSkus[$skuKey] . '); alleen die rij wordt verwerkt.',
            ];
            continue;
        }
        $seenSkus[$skuKey] = $excelRow;

        $cardType = strtolower($row['type_kaart'] ?? '');
        if ($cardType === '') {
            $cardType = 'ansichtkaart';
        }
        if (!in_array($cardType, ['ansichtkaart', 'gevouwen'], true)) {
            $plan['skipped'][] = [
                'row' => $excelRow, 'sku' => $sku,
                'reason' => 'Ongeldig type_kaart ("' . $row['type_kaart'] . '"), moet ansichtkaart of gevouwen zijn.',
            ];
            continue;
        }

        $minStockRaw = $row['minimale_voorraad'] ?? '';
        if ($minStockRaw === '') {
            $minStockRaw = '0';
        }
        if (!ctype_digit($minStockRaw)) {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => $sku, 'reason' => 'Minimale voorraad is geen geheel getal.'];
            continue;
        }

        $currentStockRaw = $row['huidige_voorraad'] ?? '';
        if ($currentStockRaw !== '' && !ctype_digit($currentStockRaw)) {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => $sku, 'reason' => 'Huidige voorraad is geen geheel getal.'];
            continue;
        }

        $toOrderRaw = $row['te_bestellen'] ?? '';
        if ($toOrderRaw === '') {
            $toOrderRaw = '0';
        }
        if (!ctype_digit($toOrderRaw)) {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => $sku, 'reason' => 'Te bestellen is geen geheel getal.'];
            continue;
        }

        $warnings = [];

        $hasEnvelope = $cardType === 'gevouwen' ? yesNoToInt($row['envelop'] ?? '') : null;

        $submissionDate = null;
        if (($row['submission_date'] ?? '') !== '') {
            $submissionDate = parseNlDate($row['submission_date']);
            if ($submissionDate === null) {
                $warnings[] = 'Submission date ("' . $row['submission_date'] . '") is ongeldig (verwacht dd-mm-jjjj), veld genegeerd.';
            }
        }

        $rejectedDate = null;
        if (($row['rejected_date'] ?? '') !== '') {
            $rejectedDate = parseNlDate($row['rejected_date']);
            if ($rejectedDate === null) {
                $warnings[] = 'Rejected date ("' . $row['rejected_date'] . '") is ongeldig (verwacht dd-mm-jjjj), veld genegeerd.';
            }
        }

        $channelIds = [];
        foreach (array_filter(array_map('trim', explode(',', $row['verkoopkanalen'] ?? ''))) as $name) {
            $key = strtolower($name);
            if (isset($channelIdByName[$key])) {
                $channelIds[] = $channelIdByName[$key];
            } else {
                $warnings[] = 'Onbekend verkoopkanaal "' . $name . '" genegeerd.';
            }
        }
        sort($channelIds);

        $greetzType = strtolower($row['greetz_sectie'] ?? '');
        $greetzType = in_array($greetzType, ['briefing', 'ingestuurd', 'nog_in_te_sturen'], true) ? $greetzType : null;

        $wholesaleDraft = yesNoToInt($row['wholesale_draft'] ?? '') ?? 0;

        $data = [
            'sku' => $sku,
            'title' => $row['titel'] !== '' ? $row['titel'] : $sku,
            'format' => $row['formaat'] !== '' ? $row['formaat'] : null,
            'card_type' => $cardType,
            'has_envelope' => $hasEnvelope,
            'envelope_color' => ($cardType === 'gevouwen' && $hasEnvelope === 1 && $row['envelop_kleur'] !== '')
                ? $row['envelop_kleur'] : null,
            'min_stock' => (int) $minStockRaw,
            'current_stock' => $currentStockRaw !== '' ? (int) $currentStockRaw : null,
            'to_order' => (int) $toOrderRaw,
            'wholesale_draft' => $wholesaleDraft,
            'comments' => $row['comments'] !== '' ? $row['comments'] : null,
            'greetz_type' => $greetzType,
            'submission_date' => $submissionDate,
            'rejected_date' => $rejectedDate,
            'psd_filename' => $row['psd_filename'] !== '' ? $row['psd_filename'] : null,
        ];

        $existing = CardRepository::findBySku($sku);

        if ($existing === null) {
            $plan['new'][] = [
                'sku' => $sku,
                'title' => $data['title'],
                'data' => array_merge($data, ['image_path' => null]),
                'channel_ids' => $channelIds,
                'warnings' => $warnings,
            ];
            continue;
        }

        $existingChannelIds = $existing['sales_channel_ids'];
        sort($existingChannelIds);

        $changedFields = [];
        foreach ($data as $field => $value) {
            if ((string) ($existing[$field] ?? '') !== (string) ($value ?? '')) {
                $changedFields[] = $field;
            }
        }
        if ($existingChannelIds !== $channelIds) {
            $changedFields[] = 'verkoopkanalen';
        }

        if ($changedFields === []) {
            $plan['unchanged'][] = ['sku' => $sku, 'title' => $data['title']];
            continue;
        }

        $plan['updated'][] = [
            'id' => (int) $existing['id'],
            'sku' => $sku,
            'title' => $data['title'],
            'data' => array_merge($data, ['image_path' => $existing['image_path']]),
            'channel_ids' => $channelIds,
            'changed_fields' => $changedFields,
            'warnings' => $warnings,
        ];
    }

    return $plan;
}

/**
 * @param array<int, array<int, string>> $rows
 * @return array{new: array, updated: array, unchanged: array, skipped: array}
 */
function buildProductTypePlan(array $rows): array
{
    $plan = ['new' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []];
    $seenNames = [];

    foreach (xlsxRowsAsAssoc($rows) as $rowNum => $row) {
        $excelRow = $rowNum + 2;
        $name = $row['naam'] ?? '';
        if ($name === '') {
            $plan['skipped'][] = ['row' => $excelRow, 'reason' => 'Geen naam ingevuld.'];
            continue;
        }

        $nameKey = strtolower($name);
        if (isset($seenNames[$nameKey])) {
            $plan['skipped'][] = [
                'row' => $excelRow, 'name' => $name,
                'reason' => 'Naam komt al eerder voor in dit bestand (rij ' . $seenNames[$nameKey] . '); alleen die rij wordt verwerkt.',
            ];
            continue;
        }
        $seenNames[$nameKey] = $excelRow;

        $comments = ($row['comments'] ?? '') !== '' ? $row['comments'] : null;
        $existing = ProductTypeRepository::findByName($name);

        if ($existing === null) {
            $plan['new'][] = ['name' => $name, 'comments' => $comments];
            continue;
        }

        if ((string) ($existing['comments'] ?? '') === (string) ($comments ?? '')) {
            $plan['unchanged'][] = ['name' => $name];
            continue;
        }

        $plan['updated'][] = ['id' => (int) $existing['id'], 'name' => $name, 'comments' => $comments];
    }

    return $plan;
}

/**
 * @param array<int, array<int, string>> $rows
 * @return array{new: array, updated: array, unchanged: array, skipped: array}
 */
function buildSalesChannelPlan(array $rows): array
{
    $ptIdByName = [];
    foreach (ProductTypeRepository::findAll() as $pt) {
        $ptIdByName[strtolower($pt['name'])] = (int) $pt['id'];
    }

    $plan = ['new' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []];
    $seenNames = [];

    foreach (xlsxRowsAsAssoc($rows) as $rowNum => $row) {
        $excelRow = $rowNum + 2;
        $name = $row['naam'] ?? '';
        if ($name === '') {
            $plan['skipped'][] = ['row' => $excelRow, 'reason' => 'Geen naam ingevuld.'];
            continue;
        }

        $nameKey = strtolower($name);
        if (isset($seenNames[$nameKey])) {
            $plan['skipped'][] = [
                'row' => $excelRow, 'name' => $name,
                'reason' => 'Naam komt al eerder voor in dit bestand (rij ' . $seenNames[$nameKey] . '); alleen die rij wordt verwerkt.',
            ];
            continue;
        }
        $seenNames[$nameKey] = $excelRow;

        $abbreviation = $row['afkorting'] ?? '';
        if ($abbreviation === '') {
            $plan['skipped'][] = ['row' => $excelRow, 'name' => $name, 'reason' => 'Geen afkorting ingevuld.'];
            continue;
        }

        $sortOrderRaw = $row['volgorde'] ?? '';
        if ($sortOrderRaw === '') {
            $sortOrderRaw = '0';
        }
        if (!ctype_digit($sortOrderRaw)) {
            $plan['skipped'][] = ['row' => $excelRow, 'name' => $name, 'reason' => 'Volgorde is geen geheel getal.'];
            continue;
        }

        $warnings = [];
        $active = yesNoToInt($row['actief'] ?? '') ?? 1;

        $color = trim($row['kleur'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            if ($color !== '') {
                $warnings[] = 'Ongeldige kleur ("' . $color . '"), standaardkleur gebruikt.';
            }
            $color = '#012b55';
        }

        $ptIds = [];
        foreach (array_filter(array_map('trim', explode(',', $row['producttypes'] ?? ''))) as $ptName) {
            $key = strtolower($ptName);
            if (isset($ptIdByName[$key])) {
                $ptIds[] = $ptIdByName[$key];
            } else {
                $warnings[] = 'Onbekend producttype "' . $ptName . '" genegeerd.';
            }
        }
        sort($ptIds);

        $data = [
            'name' => $name,
            'abbreviation' => strtoupper($abbreviation),
            'color' => $color,
            'sort_order' => (int) $sortOrderRaw,
            'active' => $active,
            'comments' => ($row['comments'] ?? '') !== '' ? $row['comments'] : null,
        ];

        $existing = SalesChannelRepository::findByName($name);

        if ($existing === null) {
            $plan['new'][] = [
                'name' => $name,
                'data' => array_merge($data, ['logo_path' => null]),
                'product_type_ids' => $ptIds,
                'warnings' => $warnings,
            ];
            continue;
        }

        $existingPtIds = $existing['product_type_ids'];
        sort($existingPtIds);

        $changedFields = [];
        foreach ($data as $field => $value) {
            if ((string) ($existing[$field] ?? '') !== (string) ($value ?? '')) {
                $changedFields[] = $field;
            }
        }
        if ($existingPtIds !== $ptIds) {
            $changedFields[] = 'producttypes';
        }

        if ($changedFields === []) {
            $plan['unchanged'][] = ['name' => $name];
            continue;
        }

        $plan['updated'][] = [
            'id' => (int) $existing['id'],
            'name' => $name,
            'data' => array_merge($data, ['logo_path' => $existing['logo_path']]),
            'product_type_ids' => $ptIds,
            'changed_fields' => $changedFields,
            'warnings' => $warnings,
        ];
    }

    return $plan;
}

/**
 * Generieke producten (alle producttypes behalve "Kaarten", die hun eigen tabblad/
 * tabel hebben). Matcht op SKU, resolvet het producttype op naam.
 *
 * @param array<int, array<int, string>> $rows
 * @return array{new: array, updated: array, unchanged: array, skipped: array}
 */
function buildProductPlan(array $rows): array
{
    $typeIdByName = [];
    foreach (ProductTypeRepository::findAll() as $pt) {
        if ($pt['name'] !== 'Kaarten') {
            $typeIdByName[strtolower($pt['name'])] = (int) $pt['id'];
        }
    }

    $plan = ['new' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []];
    $seenSkus = [];

    foreach (xlsxRowsAsAssoc($rows) as $rowNum => $row) {
        $excelRow = $rowNum + 2;
        $sku = $row['sku'] ?? '';
        if ($sku === '') {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => '', 'reason' => 'Geen SKU ingevuld.'];
            continue;
        }

        $skuKey = strtolower($sku);
        if (isset($seenSkus[$skuKey])) {
            $plan['skipped'][] = [
                'row' => $excelRow, 'sku' => $sku,
                'reason' => 'SKU komt al eerder voor in dit bestand (rij ' . $seenSkus[$skuKey] . '); alleen die rij wordt verwerkt.',
            ];
            continue;
        }
        $seenSkus[$skuKey] = $excelRow;

        $typeName = $row['producttype'] ?? '';
        $productTypeId = $typeIdByName[strtolower($typeName)] ?? null;
        if ($productTypeId === null) {
            $plan['skipped'][] = [
                'row' => $excelRow, 'sku' => $sku,
                'reason' => 'Onbekend of ontbrekend producttype ("' . $typeName . '"); "Kaarten" hoort op het Kaarten-tabblad.',
            ];
            continue;
        }

        $minStockRaw = $row['minimale_voorraad'] ?? '';
        if ($minStockRaw === '') {
            $minStockRaw = '0';
        }
        if (!ctype_digit($minStockRaw)) {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => $sku, 'reason' => 'Minimale voorraad is geen geheel getal.'];
            continue;
        }

        $currentStockRaw = $row['huidige_voorraad'] ?? '';
        if ($currentStockRaw !== '' && !ctype_digit($currentStockRaw)) {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => $sku, 'reason' => 'Huidige voorraad is geen geheel getal.'];
            continue;
        }

        $toOrderRaw = $row['te_bestellen'] ?? '';
        if ($toOrderRaw === '') {
            $toOrderRaw = '0';
        }
        if (!ctype_digit($toOrderRaw)) {
            $plan['skipped'][] = ['row' => $excelRow, 'sku' => $sku, 'reason' => 'Te bestellen is geen geheel getal.'];
            continue;
        }

        $data = [
            'product_type_id' => $productTypeId,
            'sku' => $sku,
            'title' => $row['titel'] !== '' ? $row['titel'] : $sku,
            'min_stock' => (int) $minStockRaw,
            'current_stock' => $currentStockRaw !== '' ? (int) $currentStockRaw : null,
            'to_order' => (int) $toOrderRaw,
            'wholesale_draft' => yesNoToInt($row['wholesale_draft'] ?? '') ?? 0,
            'comments' => $row['comments'] !== '' ? $row['comments'] : null,
        ];

        $existing = ProductRepository::findBySku($sku);

        if ($existing === null) {
            $plan['new'][] = [
                'sku' => $sku,
                'title' => $data['title'],
                'data' => array_merge($data, ['image_path' => null]),
            ];
            continue;
        }

        $changedFields = [];
        foreach ($data as $field => $value) {
            if ((string) ($existing[$field] ?? '') !== (string) ($value ?? '')) {
                $changedFields[] = $field;
            }
        }

        if ($changedFields === []) {
            $plan['unchanged'][] = ['sku' => $sku, 'title' => $data['title']];
            continue;
        }

        $plan['updated'][] = [
            'id' => (int) $existing['id'],
            'sku' => $sku,
            'title' => $data['title'],
            'data' => array_merge($data, ['image_path' => $existing['image_path']]),
            'changed_fields' => $changedFields,
        ];
    }

    return $plan;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload') {
        if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
            $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
        } elseif (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload een geldig .xlsx-bestand (bijv. de export die je eerder hebt gedownload).';
        } else {
            try {
                $reader = new XlsxReader($_FILES['file']['tmp_name']);
                $plan = [
                    'cards' => buildCardPlan($reader->sheet('Kaarten')),
                    'product_types' => buildProductTypePlan($reader->sheet('Producttypes')),
                    'sales_channels' => buildSalesChannelPlan($reader->sheet('Sale channels')),
                    'products' => buildProductPlan($reader->sheet('Producten')),
                ];
                $_SESSION['import_plan'] = $plan;
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'commit') {
        if (!Csrf::verify((string) ($_POST['csrf_token'] ?? '')) || !isset($_SESSION['import_plan'])) {
            $errors[] = 'De import-preview is verlopen. Upload het bestand opnieuw.';
        } else {
            $storedPlan = $_SESSION['import_plan'];
            $newCount = 0;
            $updatedCount = 0;

            foreach ($storedPlan['cards']['new'] as $item) {
                CardRepository::create($item['data'], $item['channel_ids']);
                $newCount++;
            }
            foreach ($storedPlan['cards']['updated'] as $item) {
                CardRepository::update($item['id'], $item['data'], $item['channel_ids']);
                $updatedCount++;
            }
            foreach ($storedPlan['product_types']['new'] as $item) {
                ProductTypeRepository::create($item['name'], $item['comments']);
                $newCount++;
            }
            foreach ($storedPlan['product_types']['updated'] as $item) {
                ProductTypeRepository::update($item['id'], $item['name'], $item['comments']);
                $updatedCount++;
            }
            foreach ($storedPlan['sales_channels']['new'] as $item) {
                SalesChannelRepository::create($item['data'], $item['product_type_ids']);
                $newCount++;
            }
            foreach ($storedPlan['sales_channels']['updated'] as $item) {
                SalesChannelRepository::update($item['id'], $item['data'], $item['product_type_ids']);
                $updatedCount++;
            }
            foreach ($storedPlan['products']['new'] as $item) {
                ProductRepository::create($item['data']);
                $newCount++;
            }
            foreach ($storedPlan['products']['updated'] as $item) {
                ProductRepository::update($item['id'], $item['data']);
                $updatedCount++;
            }

            unset($_SESSION['import_plan']);
            header('Location: backup.php?imported=1&new=' . $newCount . '&updated=' . $updatedCount);
            exit;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'cancel') {
        unset($_SESSION['import_plan']);
        header('Location: backup.php');
        exit;
    } elseif (isset($_GET['imported'])) {
        $committedCounts = ['new' => (int) ($_GET['new'] ?? 0), 'updated' => (int) ($_GET['updated'] ?? 0)];
} elseif (isset($_SESSION['import_plan'])) {
    $plan = $_SESSION['import_plan'];
}

/**
 * @param array{new: array, updated: array, unchanged: array, skipped: array} $sheetPlan
 */
function renderPlanSummary(array $sheetPlan): string
{
    return sprintf(
        '%d nieuw, %d bijgewerkt, %d ongewijzigd, %d overgeslagen',
        count($sheetPlan['new']),
        count($sheetPlan['updated']),
        count($sheetPlan['unchanged']),
        count($sheetPlan['skipped'])
    );
}

$pageTitle = 'Backup';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1>🗄️ Backup</h1>
</div>

<div class="card" style="margin-bottom: 20px;">
    <h3 style="margin-top: 0;">📤 Exporteren</h3>
    <p>Download een Excel-bestand (.xlsx) met alle gegevens uit deze sectie, verdeeld over vier tabbladen:</p>
    <ul>
        <li><strong>Kaarten</strong> - alle velden inclusief verkoopkanalen (als namen) en Greetz-tracking.</li>
        <li><strong>Producttypes</strong></li>
        <li><strong>Sale channels</strong> - inclusief gekoppelde producttypes (als namen).</li>
        <li><strong>Producten</strong> - alle overige producttypes (boekenleggers, notitieblokken, ...), inclusief welk producttype (als naam).</li>
    </ul>
    <p class="hint">
        Bewaar dit bestand lokaal als backup. Afbeeldingen/logo's staan er niet in (dat zijn losse
        bestanden), maar alle overige gegevens zijn direct leesbaar, ook zonder de backoffice.
    </p>
    <a href="backup.php?download=1" class="btn" style="width: auto;">⬇️ Exporteren naar Excel</a>
</div>

<?php if (Auth::isAdmin()): ?>
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin-top: 0;">📥 Importeren</h3>

        <?php if ($committedCounts !== null): ?>
            <div class="alert alert-success">
                Import voltooid: <?= (int) $committedCounts['new'] ?> nieuw toegevoegd,
                <?= (int) $committedCounts['updated'] ?> bijgewerkt.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($plan === null): ?>
            <p>Upload een eerder geëxporteerd (en eventueel bewerkt) Excel-bestand. Bestaande rijen worden
                herkend op <strong>SKU</strong> (kaarten) of <strong>naam</strong> (producttypes/sale
                channels) en bijgewerkt; nieuwe rijen worden toegevoegd. Er wordt <strong>nooit automatisch
                iets verwijderd</strong>, ook niet als een rij in het bestand ontbreekt - je krijgt eerst
                een overzicht te zien voordat er iets wordt opgeslagen.</p>

            <form method="post" action="backup.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="step" value="upload">
                <div class="field">
                    <label for="file">Excel-bestand (.xlsx)</label>
                    <input type="file" id="file" name="file" accept=".xlsx" required>
                </div>
                <button type="submit" class="btn" style="width: auto;">Bestand controleren</button>
            </form>
        <?php else: ?>
            <?php
            $sections = [
                'cards' => ['label' => 'Kaarten', 'key' => 'sku'],
                'product_types' => ['label' => 'Producttypes', 'key' => 'name'],
                'sales_channels' => ['label' => 'Sale channels', 'key' => 'name'],
                'products' => ['label' => 'Producten', 'key' => 'sku'],
            ];
            ?>
            <?php foreach ($sections as $sectionKey => $meta): ?>
                <?php $sheetPlan = $plan[$sectionKey]; ?>
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 6px;"><?= h($meta['label']) ?></h4>
                    <p class="hint"><?= h(renderPlanSummary($sheetPlan)) ?></p>

                    <?php if ($sheetPlan['new'] !== [] || $sheetPlan['updated'] !== []): ?>
                        <div class="table-wrapper">
                            <table class="orders">
                                <thead>
                                <tr>
                                    <th style="width: 90px;">Actie</th>
                                    <th style="width: 20%;">Item</th>
                                    <th>Details</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($sheetPlan['new'] as $item): ?>
                                    <tr>
                                        <td><span class="badge badge-paid">Nieuw</span></td>
                                        <td><?= h($item[$meta['key']] ?? $item['title'] ?? $item['name'] ?? '') ?></td>
                                        <td>
                                            <?php foreach ($item['warnings'] ?? [] as $warning): ?>
                                                <div class="hint">⚠️ <?= h($warning) ?></div>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($sheetPlan['updated'] as $item): ?>
                                    <tr>
                                        <td><span class="badge badge-open">Bijgewerkt</span></td>
                                        <td><?= h($item[$meta['key']] ?? $item['title'] ?? $item['name'] ?? '') ?></td>
                                        <td>
                                            Gewijzigd: <?= h(implode(', ', $item['changed_fields'])) ?>
                                            <?php foreach ($item['warnings'] ?? [] as $warning): ?>
                                                <div class="hint">⚠️ <?= h($warning) ?></div>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($sheetPlan['skipped'] !== []): ?>
                        <div class="alert alert-error" style="margin-top: 14px;">
                            <strong>Overgeslagen rijen:</strong>
                            <ul>
                                <?php foreach ($sheetPlan['skipped'] as $skip): ?>
                                    <li>Rij <?= (int) $skip['row'] ?><?= isset($skip['sku']) && $skip['sku'] !== '' ? ' (' . h($skip['sku']) . ')' : (isset($skip['name']) ? ' (' . h($skip['name']) . ')' : '') ?>: <?= h($skip['reason']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <p>Controleer bovenstaand overzicht. Bevestigen voert alle "Nieuw" en "Bijgewerkt"-rijen door.</p>
            <form method="post" action="backup.php" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="step" value="commit">
                <button type="submit" class="btn" style="width: auto;">✅ Bevestigen en importeren</button>
            </form>
            <form method="post" action="backup.php" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="step" value="cancel">
                <button type="submit" class="btn btn-secondary" style="width: auto;">Annuleren</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
