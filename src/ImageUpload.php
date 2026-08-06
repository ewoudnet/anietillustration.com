<?php

declare(strict_types=1);

namespace App;

final class ImageUpload
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_BYTES = 20 * 1024 * 1024; // 20 MB - grote foto's mogen, worden na upload verkleind
    private const MAX_DIMENSION = 2000; // px, langste zijde na resize

    /**
     * @param array<string, mixed> $file Eén item uit $_FILES
     * @return string|null Relatief pad (t.o.v. $assetsBasePath) of null als er niets is geüpload
     */
    public static function store(array $file, string $subDir, string $assetsBasePath): ?string
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van de afbeelding is mislukt.');
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('De afbeelding mag maximaal 20 MB zijn.');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Alleen jpg, png of webp-afbeeldingen zijn toegestaan.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetDir = $assetsBasePath . '/uploads/' . $subDir;

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Kan de upload-map niet aanmaken.');
        }

        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Kan de afbeelding niet opslaan.');
        }

        self::resizeIfNeeded($targetPath, $extension);

        return 'uploads/' . $subDir . '/' . $filename;
    }

    /**
     * Verkleint de afbeelding tot een langste zijde van MAX_DIMENSION px, met behoud van
     * beeldverhouding. Doet niets als GD ontbreekt of de afbeelding al klein genoeg is - een
     * uitgevallen resize mag een geslaagde upload nooit alsnog laten falen.
     */
    private static function resizeIfNeeded(string $path, string $extension): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return;
        }

        [$width, $height] = $info;
        if (max($width, $height) <= self::MAX_DIMENSION) {
            return;
        }

        $source = match ($extension) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if ($source === false || $source === null) {
            return;
        }

        $ratio = self::MAX_DIMENSION / max($width, $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        if ($extension === 'png') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        match ($extension) {
            'jpg', 'jpeg' => imagejpeg($resized, $path, 85),
            'png' => imagepng($resized, $path),
            'webp' => function_exists('imagewebp') ? imagewebp($resized, $path, 85) : null,
            default => null,
        };

        imagedestroy($source);
        imagedestroy($resized);
    }

    public static function delete(?string $relativePath, string $assetsBasePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        $fullPath = $assetsBasePath . '/' . $relativePath;

        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
