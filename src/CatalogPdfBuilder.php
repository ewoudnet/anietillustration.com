<?php

declare(strict_types=1);

namespace App;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Bouwt de catalogus-PDF (zie backend/aniet-illustration/catalog.php) - gedeeld
 * tussen de download- en de e-mail-actie zodat beide exact dezelfde opmaak en
 * afbeeldingsverkleining gebruiken.
 */
final class CatalogPdfBuilder
{
    // Geen paginering nodig - de catalogus toont in één keer alles van de gekozen producttypes.
    private const MAX_ITEMS_PER_TYPE = 100000;

    // Weergavegrootte in de PDF (CSS-px) - portrait-vormig omdat kaarten/producten
    // meestal staand zijn; "as is" tonen (geen vierkante crop), dus alleen
    // max-width/max-height, geen vaste width+height die zou uitrekken.
    private const THUMB_MAX_WIDTH = 90;
    private const THUMB_MAX_HEIGHT = 130;
    // Brondata 2x zo groot verkleinen als de weergavegrootte, voor scherpte op retina/print
    // zonder de originele upload (tot 2000px/20MB) ongewijzigd mee te embedden.
    private const THUMB_RENDER_SCALE = 2;
    private const THUMB_JPEG_QUALITY = 72;

    /**
     * @param array<int, string> $typeValues 'cards' of het producttype-id (als string)
     */
    public static function build(array $typeValues, bool $includeDraft): ?string
    {
        $draftOnly = $includeDraft ? null : false;

        $groups = [];
        foreach (ProductTypeRepository::findAll() as $productType) {
            $isCards = $productType['name'] === 'Kaarten';
            $value = $isCards ? 'cards' : (string) $productType['id'];
            if (!in_array($value, $typeValues, true)) {
                continue;
            }

            $items = $isCards
                ? CardRepository::findAllForOrderPage(self::MAX_ITEMS_PER_TYPE, 0, 'title', 'asc', $draftOnly)
                : ProductRepository::findAllForOrderPage((int) $productType['id'], self::MAX_ITEMS_PER_TYPE, 0, 'title', 'asc', $draftOnly);

            if ($items === []) {
                continue;
            }

            $groups[] = ['name' => $productType['name'], 'items' => $items];
        }

        if ($groups === []) {
            return null;
        }

        $tempFiles = [];

        try {
            $html = self::renderHtml($groups, $tempFiles);

            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            // dompdf staat standaard alleen bestanden binnen zijn eigen vendor-map toe
            // (chroot-beveiliging tegen path traversal) - de originele product-/
            // kaartfoto's staan fysiek in BO_ASSETS_PATH en de verkleinde varianten in
            // de systeem-tempdir, dus die moeten expliciet toegevoegd worden.
            $options->setChroot([BO_ASSETS_PATH, dirname(__DIR__), sys_get_temp_dir()]);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        } finally {
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * @param array<int, array{name: string, items: array<int, array<string, mixed>>}> $groups
     * @param array<int, string> $tempFiles wordt gevuld met de verkleinde thumbnails, zodat
     *                                      de aanroeper ze na het renderen kan opruimen.
     */
    private static function renderHtml(array $groups, array &$tempFiles): string
    {
        ob_start();
        ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .subtitle { font-size: 11px; color: #666; margin: 0 0 20px; }
    h2 { font-size: 14px; margin: 22px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 10px; color: #666; border-bottom: 1px solid #ccc; padding: 4px 6px; }
    td { padding: 6px; border-bottom: 1px solid #eee; vertical-align: middle; }
    td.photo { width: 100px; }
    td.sku { width: 90px; font-family: monospace; }
    td.qty { width: 90px; }
    .thumb { max-width: <?= self::THUMB_MAX_WIDTH ?>px; max-height: <?= self::THUMB_MAX_HEIGHT ?>px; }
    .thumb-empty { display: block; width: 70px; height: 100px; border: 1px solid #ddd; background: #f7f7f7; }
    .qty-box { display: block; width: 70px; height: 24px; border: 1px solid #999; }
    tr { page-break-inside: avoid; }
</style>
</head>
<body>
    <h1>Aniet Illustration &ndash; Catalogus</h1>
    <p class="subtitle">Gegenereerd op <?= date('d-m-Y') ?> &ndash; vul het gewenste aantal in en stuur deze PDF terug.</p>

    <?php foreach ($groups as $group): ?>
        <h2><?= self::esc($group['name']) ?></h2>
        <table>
            <thead>
                <tr>
                    <th class="photo"></th>
                    <th class="sku">SKU</th>
                    <th>Titel</th>
                    <th class="qty">Aantal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($group['items'] as $item): ?>
                    <?php $imagePath = self::thumbnailPath($item, $tempFiles); ?>
                    <tr>
                        <td class="photo">
                            <?php if ($imagePath !== null): ?>
                                <img class="thumb" src="<?= self::esc($imagePath) ?>" alt="">
                            <?php else: ?>
                                <div class="thumb-empty"></div>
                            <?php endif; ?>
                        </td>
                        <td class="sku"><?= self::esc($item['sku']) ?></td>
                        <td><?= self::esc($item['title']) ?></td>
                        <td class="qty"><span class="qty-box"></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, string> $tempFiles
     */
    private static function thumbnailPath(array $item, array &$tempFiles): ?string
    {
        if (empty($item['image_path'])) {
            return null;
        }

        $sourcePath = BO_ASSETS_PATH . '/' . $item['image_path'];
        if (!is_file($sourcePath)) {
            return null;
        }

        $resizedPath = self::resizeForPdf($sourcePath);
        if ($resizedPath === null) {
            // Kon niet verkleinen (bv. GD ontbreekt) - toon liever het (grotere)
            // origineel dan helemaal niets.
            return str_replace('\\', '/', $sourcePath);
        }

        $tempFiles[] = $resizedPath;

        return str_replace('\\', '/', $resizedPath);
    }

    /**
     * Verkleint en comprimeert een product-/kaartfoto naar een klein JPEG voor in de
     * PDF. Zonder dit werd een catalogus tientallen MB's groot, omdat de originele
     * uploads (tot 2000px langste zijde, tot 20MB) ongewijzigd per product embedden
     * werden - bij een paar honderd producten loopt dat snel op.
     */
    private static function resizeForPdf(string $sourcePath): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }
        [$width, $height, $type] = $info;

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
        if ($source === false || $source === null) {
            return null;
        }

        $maxWidth = self::THUMB_MAX_WIDTH * self::THUMB_RENDER_SCALE;
        $maxHeight = self::THUMB_MAX_HEIGHT * self::THUMB_RENDER_SCALE;
        $ratio = min(1.0, $maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        // Eventuele transparantie (PNG/webp) op wit plakken i.p.v. zwart - past bij de
        // witte pagina-achtergrond van de PDF.
        imagealphablending($resized, true);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        $tempPath = sys_get_temp_dir() . '/catalog_thumb_' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg($resized, $tempPath, self::THUMB_JPEG_QUALITY);
        imagedestroy($resized);

        return $tempPath;
    }

    private static function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
