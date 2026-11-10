<?php

declare(strict_types=1);

namespace App;

/**
 * Beveiligde downloadbestanden van een special. De bestanden staan NIET in een publieke
 * map maar in `storage/downloads/` naast src/ (live: binnen anietillustration-core/, die
 * via .htaccess volledig geblokkeerd is) onder een willekeurige naam. Ze zijn alleen
 * bereikbaar via specials/download.php met het persoonlijke token van een betaalde order.
 */
final class SpecialDownloadRepository
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'zip', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'ai', 'psd', 'eps', 'mp3', 'mp4', 'epub', 'txt', 'procreate'];
    private const MAX_BYTES = 100 * 1024 * 1024; // 100 MB per bestand

    public static function storageDir(): string
    {
        return dirname(__DIR__) . '/storage/downloads';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySpecial(int $specialId): array
    {
        $stmt = SpecialsDatabase::connection()->prepare(
            'SELECT * FROM special_downloads WHERE special_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$specialId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = SpecialsDatabase::connection()->prepare('SELECT * FROM special_downloads WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Slaat één geüpload bestand op en koppelt het aan de special.
     *
     * @param array<string, mixed> $file Eén item uit $_FILES
     * @throws \RuntimeException met een gebruikersvriendelijke melding
     */
    public function store(int $specialId, array $file): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $name = basename((string) ($file['name'] ?? ''));

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException("'{$name}' is te groot voor de serverinstellingen (upload_max_filesize/post_max_size).");
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Uploaden van '{$name}' is mislukt.");
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException("'{$name}' is groter dan " . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException("Bestandstype van '{$name}' is niet toegestaan (toegestaan: " . implode(', ', self::ALLOWED_EXTENSIONS) . ').');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        $dir = self::storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Kan de opslagmap voor downloads niet aanmaken.');
        }
        // Extra vangnet naast de afgeschermde core-map: nooit direct uitleverbaar via webserver.
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }

        $storedName = bin2hex(random_bytes(24)) . '.dat';
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $storedName)) {
            throw new \RuntimeException("Kan '{$name}' niet opslaan.");
        }

        $stmt = SpecialsDatabase::connection()->prepare(
            'INSERT INTO special_downloads (special_id, original_name, stored_name, size_bytes, sort_order)
             VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(d.sort_order), 0) + 1 FROM special_downloads d WHERE d.special_id = ?))'
        );
        $stmt->execute([$specialId, mb_substr($name, 0, 255), $storedName, (int) $file['size'], $specialId]);
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) {
            return;
        }

        SpecialsDatabase::connection()->prepare('DELETE FROM special_downloads WHERE id = ?')->execute([$id]);
        $this->unlink((string) $row['stored_name']);
    }

    /**
     * Verwijdert alle bestanden van een special van schijf (de rijen verdwijnen via ON DELETE CASCADE
     * zodra de special zelf wordt verwijderd).
     */
    public function deleteFilesForSpecial(int $specialId): void
    {
        foreach ($this->findBySpecial($specialId) as $row) {
            $this->unlink((string) $row['stored_name']);
        }
    }

    public static function pathFor(array $row): string
    {
        return self::storageDir() . '/' . basename((string) $row['stored_name']);
    }

    private function unlink(string $storedName): void
    {
        $path = self::storageDir() . '/' . basename($storedName);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
