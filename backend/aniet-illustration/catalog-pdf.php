<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CatalogPdfBuilder;

Auth::requireSection('aniet-illustration');

$includeDraft = ($_GET['include_draft'] ?? '') === '1';
$typeValues = array_map('strval', (array) ($_GET['types'] ?? []));

$pdf = CatalogPdfBuilder::build($typeValues, $includeDraft);
if ($pdf === null) {
    header('Location: catalog.php?empty=1');
    exit;
}

$filename = 'catalogus-' . date('Y-m-d') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
