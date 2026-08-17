<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;
use App\ImageUpload;
use App\SalesChannelRepository;

Auth::requireSection('aniet-illustration');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cards.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if (!Csrf::verify((string) ($_POST['csrf_token'] ?? '')) || $id <= 0) {
    header('Location: cards.php');
    exit;
}

$original = CardRepository::find($id);
if ($original === null) {
    header('Location: cards.php');
    exit;
}

$newSku = CardRepository::suggestNextSku($original['sku']);

$data = [
    'sku' => $newSku,
    'title' => $original['title'],
    'image_path' => ImageUpload::copy($original['image_path'], BO_ASSETS_PATH),
    'format' => $original['format'],
    'card_type' => $original['card_type'],
    'has_envelope' => $original['has_envelope'],
    'envelope_color' => $original['envelope_color'],
    'min_stock' => $original['min_stock'],
    'current_stock' => null,
    'to_order' => 0,
    'wholesale_draft' => $original['wholesale_draft'],
    'comments' => $original['comments'],
    // Greetz-tracking hoort bij het submission-traject van het ORIGINEEL; de kopie
    // start hierin blanco, ook als hij later (opnieuw) naar Greetz gaat.
    'greetz_type' => null,
    'submission_date' => null,
    'rejected_date' => null,
    'psd_filename' => $original['psd_filename'],
];

$wholesaleChannelId = null;
foreach (SalesChannelRepository::findAll() as $channel) {
    if ($channel['name'] === 'Wholesale') {
        $wholesaleChannelId = (int) $channel['id'];
        break;
    }
}

// Wholesale wordt niet meegekopieerd: een kopie is meestal het startpunt voor
// een nieuw (Greetz-)ontwerp en mag niet ongemerkt als wholesale-artikel meelopen.
$salesChannelIds = array_values(array_filter(
    $original['sales_channel_ids'],
    static fn (int $channelId): bool => $channelId !== $wholesaleChannelId
));

$newId = CardRepository::create($data, $salesChannelIds);

header('Location: card-form.php?id=' . $newId . '&duplicated=1');
exit;
