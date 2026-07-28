<?php

declare(strict_types=1);

namespace WicketImporter\Support;

/**
 * Durable storage for the original uploaded CSV, keyed by import session.
 *
 * The staged rows cannot reconstruct the source file (the cheque spec says extra
 * columns are ignored), so the original is retained for download/audit and
 * deleted with its session. Files live under wp_upload_dir()/wicket-importer/,
 * served ONLY through the nonce-gated REST route — never via a raw URL — with an
 * .htaccess (Apache "Require all denied") + an index.php to deny directory listing.
 * nginx ignores .htaccess, so on nginx also add a server-level deny on the
 * wicket-importer/ location; the UUID filename + index.php + nonce route are the
 * portable mitigation until then.
 */
final class CsvStorage
{
    /**
     * The uploads subdirectory holding retained source CSVs.
     */
    public static function storageDir(): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit((string) ($uploads['basedir'] ?? '')) . 'wicket-importer/';
    }

    /**
     * The absolute path to a session's retained source CSV.
     */
    public static function pathFor(string $sessionId): string
    {
        return self::storageDir() . $sessionId . '.csv';
    }

    public static function exists(string $sessionId): bool
    {
        return file_exists(self::pathFor($sessionId));
    }

    /**
     * Move an uploaded CSV into durable storage keyed by session. Creates the
     * storage directory (+ access-deny .htaccess) on first use. Returns true
     * when the file ended up at the target path.
     */
    public static function store(string $uploadedPath, string $sessionId): bool
    {
        if ($uploadedPath === '' || !file_exists($uploadedPath)) {
            return false;
        }

        self::ensureStorageDir();
        $target = self::pathFor($sessionId);

        // rename() moves within the same filesystem; fall back to copy + unlink.
        if (@rename($uploadedPath, $target)) {
            return true;
        }

        return @copy($uploadedPath, $target) && @unlink($uploadedPath);
    }

    /**
     * Delete a session's retained source CSV. Returns true if a file was removed.
     */
    public static function delete(string $sessionId): bool
    {
        $path = self::pathFor($sessionId);

        return file_exists($path) && @unlink($path);
    }

    /**
     * Ensure the storage directory exists and direct web access is denied.
     *
     * Hardening: an Apache 2.4 "Require all denied" .htaccess (the 2.2 "Deny
     * from all" needs mod_access_compat and can 500 without it, so it is not
     * emitted) + an index.php that silences directory listing on any server.
     * nginx ignores .htaccess entirely — for nginx, also configure a server-
     * level `location ~ /wicket-importer/ { deny all; }`; the UUID filename +
     * index.php + the nonce-gated source-csv route are the portable mitigation.
     */
    private static function ensureStorageDir(): void
    {
        $dir = self::storageDir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        // Silence directory listing on any server (the WP convention).
        $index = $dir . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
