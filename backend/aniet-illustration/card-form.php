<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Auth;
use App\CardRepository;
use App\Csrf;
use App\ImageUpload;
use App\SalesChannelRepository;
use App\WholesaleStockSyncService;

Auth::requireSection('aniet-illustration');

$activeSection = 'aniet-illustration';
$activePage = 'card-form';
$activeProductType = 'cards';
$csrfToken = Csrf::token();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? CardRepository::find($id) : null;

if ($id !== null && $existing === null) {
    header('Location: cards.php');
    exit;
}

$channels = SalesChannelRepository::findAll();
$greetzChannelId = null;
$wholesaleChannelId = null;
foreach ($channels as $channel) {
    if ($channel['name'] === 'Greetz') {
        $greetzChannelId = (int) $channel['id'];
    }
    if ($channel['name'] === 'Wholesale') {
        $wholesaleChannelId = (int) $channel['id'];
    }
}

$errors = [];

$values = [
    'sku' => $existing['sku'] ?? '',
    'title' => $existing['title'] ?? '',
    'format' => $existing['format'] ?? '',
    'card_type' => $existing['card_type'] ?? 'ansichtkaart',
    'has_envelope' => $existing['has_envelope'] ?? null,
    'envelope_color' => $existing['envelope_color'] ?? '',
    'min_stock' => (string) ($existing['min_stock'] ?? 0),
    'current_stock' => $existing !== null && $existing['current_stock'] !== null ? (string) $existing['current_stock'] : '',
    'to_order' => (string) ($existing['to_order'] ?? 0),
    'comments' => $existing['comments'] ?? '',
    'greetz_type' => $existing['greetz_type'] ?? '',
    'submission_date' => $existing !== null && $existing['submission_date'] !== null
        ? (new DateTime($existing['submission_date']))->format('d-m-Y') : '',
    'rejected_date' => $existing !== null && $existing['rejected_date'] !== null
        ? (new DateTime($existing['rejected_date']))->format('d-m-Y') : '',
    'psd_filename' => $existing['psd_filename'] ?? '',
    'wholesale_draft' => (int) ($existing['wholesale_draft'] ?? 0),
];
$selectedChannelIds = $existing['sales_channel_ids'] ?? [];
$imagePath = $existing['image_path'] ?? null;
$wholesaleSelected = $wholesaleChannelId !== null && in_array($wholesaleChannelId, $selectedChannelIds, true);

if ($existing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $values['sku'] = CardRepository::suggestNextSku(date('ymd'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    $values['sku'] = trim((string) ($_POST['sku'] ?? ''));
    $values['title'] = trim((string) ($_POST['title'] ?? ''));
    $values['format'] = trim((string) ($_POST['format'] ?? ''));
    $values['card_type'] = (string) ($_POST['card_type'] ?? 'ansichtkaart');
    $hasEnvelopeInput = $_POST['has_envelope'] ?? null;
    $values['has_envelope'] = ($hasEnvelopeInput === null || $hasEnvelopeInput === '') ? null : (int) $hasEnvelopeInput;
    $values['envelope_color'] = trim((string) ($_POST['envelope_color'] ?? ''));
    $values['min_stock'] = trim((string) ($_POST['min_stock'] ?? ''));
    $values['current_stock'] = trim((string) ($_POST['current_stock'] ?? ''));
    $values['to_order'] = trim((string) ($_POST['to_order'] ?? '0'));
    if ($values['to_order'] === '') {
        $values['to_order'] = '0';
    }
    $values['comments'] = trim((string) ($_POST['comments'] ?? ''));
    $values['greetz_type'] = (string) ($_POST['greetz_type'] ?? '');
    $values['submission_date'] = trim((string) ($_POST['submission_date'] ?? ''));
    $values['rejected_date'] = trim((string) ($_POST['rejected_date'] ?? ''));
    $values['psd_filename'] = trim((string) ($_POST['psd_filename'] ?? ''));
    $values['wholesale_draft'] = isset($_POST['wholesale_draft']) ? 1 : 0;
    $selectedChannelIds = array_map('intval', $_POST['sales_channel_ids'] ?? []);
    $wholesaleSelected = $wholesaleChannelId !== null && in_array($wholesaleChannelId, $selectedChannelIds, true);

    if (!Csrf::verify($submittedToken)) {
        $errors[] = 'Je sessie is verlopen. Probeer het opnieuw.';
    }

    if ($values['sku'] === '') {
        $errors[] = 'Vul een SKU in.';
    } elseif (CardRepository::skuExists($values['sku'], $id)) {
        $errors[] = 'Deze SKU is al in gebruik door een andere kaart.';
    }

    if ($values['title'] === '') {
        $errors[] = 'Vul een titel in.';
    }

    if (!in_array($values['card_type'], ['ansichtkaart', 'gevouwen'], true)) {
        $errors[] = 'Kies een geldig type kaart.';
    }

    if ($values['card_type'] === 'gevouwen' && $values['has_envelope'] === null) {
        $errors[] = 'Geef aan of de gevouwen kaart met of zonder envelop is.';
    }

    if ($wholesaleSelected) {
        if ($values['min_stock'] === '' || !ctype_digit($values['min_stock'])) {
            $errors[] = 'Minimale voorraad is verplicht wanneer Wholesale een verkoopkanaal is.';
        }
    } elseif ($values['min_stock'] !== '' && !ctype_digit($values['min_stock'])) {
        $errors[] = 'Minimale voorraad moet een geheel getal zijn.';
    }

    if ($values['current_stock'] !== '' && !ctype_digit($values['current_stock'])) {
        $errors[] = 'Huidige voorraad moet leeg zijn of een geheel getal.';
    }

    if (!ctype_digit($values['to_order'])) {
        $errors[] = 'Te bestellen moet een geheel getal zijn.';
    }

    $greetzSelected = $greetzChannelId !== null && in_array($greetzChannelId, $selectedChannelIds, true);

    if ($greetzSelected && !in_array($values['greetz_type'], ['briefing', 'ingestuurd', 'nog_in_te_sturen'], true)) {
        $errors[] = 'Kies bij de Greetz-sectie of het een briefing, ingestuurd of nog in te sturen ontwerp is.';
    }

    // Submission/rejected date horen bij de Greetz-sectie en worden genegeerd (niet
    // gevalideerd, niet opgeslagen) als Greetz niet is aangevinkt - zelfde principe als
    // greetz_type hieronder.
    $submissionDateIso = null;
    if ($greetzSelected && $values['submission_date'] !== '') {
        $submissionDateIso = parseNlDate($values['submission_date']);
        if ($submissionDateIso === null) {
            $errors[] = 'Submission date moet een geldige datum zijn (dd-mm-jjjj).';
        }
    }

    $rejectedDateIso = null;
    if ($greetzSelected && $values['rejected_date'] !== '') {
        $rejectedDateIso = parseNlDate($values['rejected_date']);
        if ($rejectedDateIso === null) {
            $errors[] = 'Rejected date moet een geldige datum zijn (dd-mm-jjjj).';
        }
    }

    if ($greetzSelected && $values['greetz_type'] === 'nog_in_te_sturen'
        && ($values['submission_date'] !== '' || $values['rejected_date'] !== '')) {
        $errors[] = '"Nog in te sturen" kan niet gekozen worden als er al een submission of rejected date is ingevuld.';
    }

    if (empty($errors)) {
        try {
            $uploaded = ImageUpload::store($_FILES['image'] ?? [], 'cards', BO_ASSETS_PATH);
            if ($uploaded !== null) {
                if ($existing !== null) {
                    ImageUpload::delete($existing['image_path'], BO_ASSETS_PATH);
                }
                $imagePath = $uploaded;
            }
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $data = [
            'sku' => $values['sku'],
            'title' => $values['title'],
            'image_path' => $imagePath,
            'format' => $values['format'] !== '' ? $values['format'] : null,
            'card_type' => $values['card_type'],
            'has_envelope' => $values['card_type'] === 'gevouwen' ? $values['has_envelope'] : null,
            'envelope_color' => ($values['card_type'] === 'gevouwen' && $values['has_envelope'] === 1 && $values['envelope_color'] !== '')
                ? $values['envelope_color'] : null,
            'min_stock' => $values['min_stock'] === '' ? 0 : (int) $values['min_stock'],
            'current_stock' => $values['current_stock'] === '' ? null : (int) $values['current_stock'],
            'to_order' => (int) $values['to_order'],
            'comments' => $values['comments'] !== '' ? $values['comments'] : null,
            'greetz_type' => $greetzSelected ? $values['greetz_type'] : null,
            'submission_date' => $submissionDateIso,
            'rejected_date' => $rejectedDateIso,
            'psd_filename' => ($greetzSelected && $values['psd_filename'] !== '') ? $values['psd_filename'] : null,
            'wholesale_draft' => $wholesaleSelected ? $values['wholesale_draft'] : 0,
        ];

        $wasCreate = $id === null;
        if ($wasCreate) {
            $id = CardRepository::create($data, $selectedChannelIds);
        } else {
            CardRepository::update($id, $data, $selectedChannelIds);
        }

        $syncQuery = '';
        if (isset($_POST['sync_now']) && Auth::isAdmin() && !WHOLESALE_SYNC_PAUSED) {
            WholesaleStockSyncService::run();
            $syncQuery = '&synced=1';
        }

        header('Location: cards.php?' . ($wasCreate ? 'created=1' : 'updated=1') . $syncQuery);
        exit;
    }
}

$pageTitle = $existing !== null ? 'Kaart bewerken' : 'Kaart toevoegen';
require __DIR__ . '/../partials/layout-start.php';
?>
<div class="page-header">
    <h1><?= $existing !== null ? '✏️ Kaart bewerken' : '+ Kaart toevoegen' ?></h1>
</div>

<div class="card">
    <?php if (isset($_GET['duplicated'])): ?>
        <div class="alert alert-success">Kaart gedupliceerd met SKU <?= h($values['sku']) ?>. Controleer en pas de gegevens hieronder aan.</div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>Controleer de volgende velden:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="card-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <fieldset>
            <legend>Afbeelding</legend>
            <?php if ($imagePath !== null): ?>
                <img class="table-thumb" style="width: 96px; height: 96px; margin-bottom: 10px;" src="<?= h(BO_ASSETS_URL) ?>/<?= h($imagePath) ?>" alt="">
            <?php endif; ?>
            <div class="field">
                <input type="file" name="image" accept="image/png,image/jpeg,image/webp">
                <small class="hint">jpg, png of webp, max 20 MB. Grote afbeeldingen worden automatisch verkleind tot max 2000px. Laat leeg om de huidige afbeelding te behouden.</small>
            </div>
        </fieldset>

        <fieldset>
            <legend>Basisgegevens</legend>
            <div class="row">
                <div class="field">
                    <label for="sku">SKU *</label>
                    <input type="text" id="sku" name="sku" required value="<?= h($values['sku']) ?>">
                </div>
                <div class="field" style="flex: 2 1 300px;">
                    <label for="title">Titel *</label>
                    <input type="text" id="title" name="title" required value="<?= h($values['title']) ?>">
                </div>
                <div class="field">
                    <label for="format">Formaat</label>
                    <input type="text" id="format" name="format" list="format-options" value="<?= h($values['format']) ?>">
                    <datalist id="format-options">
                        <option value="A6">
                        <option value="Carré M">
                        <option value="A6 Flat">
                    </datalist>
                    <small class="hint">Vrij invulbaar, dit zijn alleen suggesties.</small>
                </div>
            </div>
            <?php if ($existing !== null): ?>
                <div class="field">
                    <label>Datum creatie</label>
                    <input type="text" disabled value="<?= h((new DateTime($existing['created_at']))->format('d-m-Y H:i')) ?>">
                </div>
            <?php endif; ?>
        </fieldset>

        <fieldset>
            <legend>Verkoopkanalen</legend>
            <div class="checkbox-group">
                <?php foreach ($channels as $channel): ?>
                    <label>
                        <input type="checkbox" name="sales_channel_ids[]" value="<?= (int) $channel['id'] ?>"
                               data-name="<?= h($channel['name']) ?>"
                               <?= in_array((int) $channel['id'], $selectedChannelIds, true) ? 'checked' : '' ?>>
                        <?= h($channel['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div id="wholesale-draft-section" class="conditional-field" style="margin-top: 16px;">
                <label>
                    <input type="checkbox" id="wholesale_draft" name="wholesale_draft" value="1" <?= $values['wholesale_draft'] === 1 ? 'checked' : '' ?>>
                    Wholesale Draft
                </label>
                <small class="hint">Draft-kaarten verschijnen, net als alle andere kaarten, alleen in de bestellijst via een expliciete "te bestellen" op de bestelpagina - niet automatisch op basis van voorraad.</small>
            </div>

            <div id="greetz-section" class="conditional-field" style="margin-top: 16px;">
                <label>Greetz-sectie</label>
                <div class="checkbox-group">
                    <label><input type="radio" name="greetz_type" value="briefing" <?= $values['greetz_type'] === 'briefing' ? 'checked' : '' ?>> Briefing</label>
                    <label><input type="radio" name="greetz_type" value="ingestuurd" <?= $values['greetz_type'] === 'ingestuurd' ? 'checked' : '' ?>> Ingestuurd</label>
                    <label><input type="radio" name="greetz_type" value="nog_in_te_sturen" <?= $values['greetz_type'] === 'nog_in_te_sturen' ? 'checked' : '' ?>> Nog in te sturen</label>
                </div>
                <div class="row" style="margin-top: 12px;">
                    <div class="field">
                        <label for="submission_date">Submission date</label>
                        <input type="text" id="submission_date" name="submission_date" placeholder="dd-mm-jjjj" value="<?= h($values['submission_date']) ?>">
                    </div>
                    <div class="field">
                        <label for="rejected_date">Rejected date</label>
                        <input type="text" id="rejected_date" name="rejected_date" placeholder="dd-mm-jjjj" value="<?= h($values['rejected_date']) ?>">
                    </div>
                    <div class="field">
                        <label for="psd_filename">PSD file name</label>
                        <input type="text" id="psd_filename" name="psd_filename" value="<?= h($values['psd_filename']) ?>">
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Type kaart</legend>
            <div class="checkbox-group">
                <label><input type="radio" name="card_type" value="ansichtkaart" <?= $values['card_type'] === 'ansichtkaart' ? 'checked' : '' ?>> Ansichtkaart</label>
                <label><input type="radio" name="card_type" value="gevouwen" <?= $values['card_type'] === 'gevouwen' ? 'checked' : '' ?>> Gevouwen kaart</label>
            </div>

            <div id="envelope-section" class="conditional-field" style="margin-top: 16px;">
                <div class="checkbox-group">
                    <label><input type="radio" name="has_envelope" value="1" <?= $values['has_envelope'] === 1 ? 'checked' : '' ?>> Met envelop</label>
                    <label><input type="radio" name="has_envelope" value="0" <?= $values['has_envelope'] === 0 ? 'checked' : '' ?>> Zonder envelop</label>
                </div>
                <div id="envelope-color-field" class="field conditional-field" style="margin-top: 10px;">
                    <label for="envelope_color">Kleur envelop</label>
                    <input type="text" id="envelope_color" name="envelope_color" value="<?= h($values['envelope_color']) ?>">
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Voorraad</legend>
            <div class="row">
                <div class="field">
                    <label for="min_stock">Minimale voorraad<span id="min-stock-required" style="<?= $wholesaleSelected ? '' : 'display:none;' ?>"> *</span></label>
                    <input type="number" id="min_stock" name="min_stock" min="0" value="<?= h($values['min_stock']) ?>">
                </div>
                <div class="field">
                    <label for="current_stock">Huidige voorraad</label>
                    <input type="number" id="current_stock" name="current_stock" min="0" value="<?= h($values['current_stock']) ?>">
                    <small class="hint">Handmatig bijhouden totdat de Faire-koppeling live voorraad kan aanleveren.</small>
                </div>
                <div class="field">
                    <label for="to_order">Te bestellen</label>
                    <input type="number" id="to_order" name="to_order" min="0" value="<?= h($values['to_order']) ?>">
                    <small class="hint">Kan ook direct op de bestelpagina aangepast worden.</small>
                </div>
            </div>
            <?php if (Auth::isAdmin() && !WHOLESALE_SYNC_PAUSED): ?>
                <div class="field field-checkbox" style="margin-top: 10px;">
                    <label>
                        <input type="checkbox" id="sync_now" name="sync_now" value="1">
                        Voorraad direct synchroniseren naar Faire/Orderchamp na opslaan
                    </label>
                    <small class="hint">Schrijft bij het opslaan meteen alle afwijkende voorraad terug naar de platformen (niet alleen deze kaart) - zelfde actie als de knop op de SKU-vergelijkingspagina.</small>
                </div>
            <?php endif; ?>
        </fieldset>

        <fieldset>
            <legend>Comments</legend>
            <div class="field">
                <textarea name="comments" rows="4"><?= h($values['comments']) ?></textarea>
            </div>
        </fieldset>

        <div class="row" style="margin-top: 6px;">
            <button type="submit" class="btn" style="width: auto;"><?= $existing !== null ? 'Opslaan' : 'Kaart toevoegen' ?></button>
            <a href="cards.php" class="btn btn-secondary" style="width: auto;">Annuleren</a>
        </div>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const envelopeSection = document.getElementById('envelope-section');
    const envelopeColorField = document.getElementById('envelope-color-field');
    const greetzSection = document.getElementById('greetz-section');
    const greetzCheckbox = document.querySelector('input[name="sales_channel_ids[]"][data-name="Greetz"]');
    const wholesaleDraftSection = document.getElementById('wholesale-draft-section');
    const wholesaleCheckbox = document.querySelector('input[name="sales_channel_ids[]"][data-name="Wholesale"]');
    const minStockInput = document.getElementById('min_stock');
    const minStockRequired = document.getElementById('min-stock-required');
    const wholesaleDraftCheckbox = document.getElementById('wholesale_draft');

    function updateEnvelopeVisibility() {
        const isFolded = document.querySelector('input[name="card_type"]:checked')?.value === 'gevouwen';
        envelopeSection.classList.toggle('visible', isFolded);

        const hasEnvelope = document.querySelector('input[name="has_envelope"]:checked')?.value === '1';
        envelopeColorField.classList.toggle('visible', isFolded && hasEnvelope);
    }

    function updateGreetzVisibility() {
        greetzSection.classList.toggle('visible', greetzCheckbox instanceof HTMLInputElement && greetzCheckbox.checked);
    }

    function updateWholesaleVisibility() {
        const wholesaleSelected = wholesaleCheckbox instanceof HTMLInputElement && wholesaleCheckbox.checked;
        wholesaleDraftSection.classList.toggle('visible', wholesaleSelected);
        minStockInput.required = wholesaleSelected;
        minStockRequired.style.display = wholesaleSelected ? '' : 'none';
        if (!wholesaleSelected && wholesaleDraftCheckbox.checked) {
            wholesaleDraftCheckbox.checked = false;
        }
    }

    const nogInTeSturenRadio = document.querySelector('input[name="greetz_type"][value="nog_in_te_sturen"]');
    const submissionDateInput = document.getElementById('submission_date');
    const rejectedDateInput = document.getElementById('rejected_date');

    function updateNogInTeSturenAvailability() {
        const hasDate = submissionDateInput.value.trim() !== '' || rejectedDateInput.value.trim() !== '';
        nogInTeSturenRadio.disabled = hasDate;
        if (hasDate && nogInTeSturenRadio.checked) {
            nogInTeSturenRadio.checked = false;
        }
    }

    document.querySelectorAll('input[name="card_type"], input[name="has_envelope"]')
        .forEach((input) => input.addEventListener('change', updateEnvelopeVisibility));

    if (greetzCheckbox) {
        greetzCheckbox.addEventListener('change', updateGreetzVisibility);
    }

    if (wholesaleCheckbox) {
        wholesaleCheckbox.addEventListener('change', updateWholesaleVisibility);
    }

    submissionDateInput.addEventListener('input', updateNogInTeSturenAvailability);
    rejectedDateInput.addEventListener('input', updateNogInTeSturenAvailability);

    updateEnvelopeVisibility();
    updateGreetzVisibility();
    updateWholesaleVisibility();
    updateNogInTeSturenAvailability();
});
</script>
<?php require __DIR__ . '/../partials/layout-end.php'; ?>
