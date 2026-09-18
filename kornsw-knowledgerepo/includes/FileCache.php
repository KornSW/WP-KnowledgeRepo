<?php
namespace KornSW\KnowledgeRepo;

/** Encrypted, atomic source cache. No tokens or repository text are stored in plain text. */
final class FileCache {
    public static function invalidate(): void {
        update_option('kornsw_kr_cache_epoch', bin2hex(random_bytes(16)), false);
    }
    public static function remember(string $key, callable $loader): array {
        $ttl = max(0, min(604800, (int) (Auth::settings()['cache_ttl'] ?? 14400)));
        if (!$ttl) { return $loader(); }
        $root = defined('KORNSW_KR_CACHE_DIR') ? KORNSW_KR_CACHE_DIR : WP_CONTENT_DIR . '/kornsw-knowledge-cache';
        if (!is_dir($root) && !wp_mkdir_p($root)) { return $loader(); }
        @chmod($root, 0700);
        $identity = home_url('/') . '|' . get_option('kornsw_kr_cache_epoch', '0') . '|' . $key;
        $file = $root . '/' . hash_hmac('sha256', $identity, wp_salt('auth')) . '.cache';
        $read = static function () use ($file, $ttl) {
            if (!is_file($file)) { return null; }
            try { $item = json_decode(Auth::revealSecret((string) @file_get_contents($file)), true); }
            catch (Failure $e) { return null; }
            if (!is_array($item) || !isset($item['at'], $item['value']) || time() - $item['at'] >= min($ttl, isset($item['error']) ? 30 : $ttl)) { return null; }
            return $item;
        };
        $unwrap = static function (array $item): array {
            if (isset($item['error'])) { throw new Failure($item['error'], (int) $item['value']['status']); }
            return $item['value'];
        };
        if (($item = $read()) !== null) { return $unwrap($item); }
        // Fixed number of lock files prevents an unbounded lock-file collection.
        $lock = @fopen($root . '/' . substr(hash('sha256', $identity), 0, 2) . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { if ($lock) { fclose($lock); } return $loader(); }
        try {
            clearstatcache(true, $file);
            if (($item = $read()) !== null) { return $unwrap($item); }
            try { $item = ['at' => time(), 'value' => $loader()]; }
            catch (Failure $e) { $item = ['at' => time(), 'error' => $e->getMessage(), 'value' => ['status' => $e->status]]; }
            $json = wp_json_encode($item);
            if (is_string($json)) {
                $bytes = Auth::protectSecret($json); $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
                if (@file_put_contents($tmp, $bytes, LOCK_EX) === strlen($bytes)) { @chmod($tmp, 0600); @rename($tmp, $file); }
                @unlink($tmp);
            }
            // Amortized cleanup also removes obsolete configuration/token generations.
            if (random_int(1, 100) === 1) {
                foreach (glob($root . '/*.cache') ?: [] as $old) { if (@filemtime($old) < time() - 604800) { @unlink($old); } }
            }
            return $unwrap($item);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
