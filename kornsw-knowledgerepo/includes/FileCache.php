<?php
namespace KornSW\KnowledgeRepo;

/** Demand-driven encrypted read cache. Refresh preserves LKG; authoritative changes revoke it. */
final class FileCache {
    private static $warnings = [];
    private static $warningVersion = 0;
    private static $dependencies = [];
    private static function observed(int $at): void {
        foreach (self::$dependencies as &$value) { $value = min($value, $at); } unset($value);
    }
    private static $stats = ['hit'=>0, 'miss'=>0, 'stale'=>0, 'lock_ms'=>0.0];
    public static function statistics(): array { return self::$stats; }
    public static function warningVersion(): int { return self::$warningVersion; }
    public static function root(): string { return defined('KORNSW_KR_CACHE_DIR') ? KORNSW_KR_CACHE_DIR : WP_CONTENT_DIR . '/kornsw-knowledge-cache'; }
    public static function warnings(): array { return array_values(self::$warnings); }
    public static function warn(string $scope, \Throwable $e): void {
        self::$warningVersion++;
        self::$warnings[$scope] = ['label' => 'Wissensquelle', 'mount' => '', 'message' => 'Ein Teil der Wissensquelle ist momentan nicht verfügbar. Verfügbare oder zuletzt erfolgreich geladene Inhalte werden weiterhin angezeigt.'];
        error_log('KornSW KnowledgeRepo read failure [' . substr(hash('sha256', $scope), 0, 12) . ']: ' . get_class($e));
    }
    public static function invalidate(string $scope = ''): void {
        update_option($scope === '' ? 'kornsw_kr_cache_epoch' : 'kornsw_kr_cache_' . hash('sha256', $scope), bin2hex(random_bytes(16)), false);
    }
    public static function refresh(): void { update_option('kornsw_kr_cache_refresh', bin2hex(random_bytes(16)), false); }
    public static function write(string $file, array $value): void {
        $json = wp_json_encode($value); if (!is_string($json)) { return; }
        $bytes = Auth::protectSecret($json); $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === strlen($bytes)) { @chmod($tmp, 0600); @rename($tmp, $file); }
        @unlink($tmp);
    }
    public static function read(string $file): ?array {
        if (!is_file($file)) { return null; }
        try { $v = json_decode(Auth::revealSecret((string) @file_get_contents($file)), true); return is_array($v) ? $v : null; }
        catch (\Throwable $e) { return null; }
    }
    public static function remember(string $key, callable $loader, string $scope = '', bool $allowStale = true, ?callable $cacheable = null): array {
        $ttl = max(0, min(604800, (int) (Auth::settings()['cache_ttl'] ?? 14400)));
        if (!$ttl) { self::$stats['miss']++; return $loader(); }
        $root = self::root() . '/reads-v2';
        if (!is_dir($root) && !wp_mkdir_p($root)) { return $loader(); } @chmod($root, 0700);
        $id = hash_hmac('sha256', home_url('/') . '|' . $scope . '|' . $key, wp_salt('auth'));
        $file = $root . '/' . $id . '.cache';
        $epoch = (string) get_option('kornsw_kr_cache_epoch', '0') . ':' . (string) get_option('kornsw_kr_cache_' . hash('sha256', $scope), '0');
        $refresh = (string) get_option('kornsw_kr_cache_refresh', '0');
        $read = static function () use ($file, $epoch): ?array {
            $item = self::read($file);
            return $item && ($item['epoch'] ?? '') === $epoch && isset($item['value'], $item['at']) && is_array($item['value']) ? $item : null;
        };
        $fresh = static function ($v) use ($ttl, $refresh) { return $v && ($v['refresh'] ?? '') === $refresh && empty($v['retry']) && time() - $v['at'] < $ttl; };
        $old = $read(); if ($fresh($old)) { self::$stats['hit']++; self::observed($old['at']); return $old['value']; }
        self::$stats['miss']++;
        if ($old && $allowStale && ($old['refresh'] ?? '') === $refresh && ($old['retry'] ?? 0) > time()) {
            self::$stats['stale']++; self::warn($scope, new Failure('Offline cache')); return $old['value'];
        }
        $lock = @fopen($root . '/' . $id . '.lock', 'c');
        if (!$lock) { return $loader(); }
        // Do not let one slow loader indefinitely queue every request behind its lock.
        $started = microtime(true); $budget = max(0, min(5000, defined('KORNSW_KR_CACHE_LOCK_WAIT_MS') ? (int) KORNSW_KR_CACHE_LOCK_WAIT_MS : 1000));
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if ($old && $allowStale) {
                fclose($lock); self::$stats['lock_ms'] += (microtime(true) - $started) * 1000;
                self::$stats['stale']++; self::warn($scope, new Failure('Cache refresh already running', 503)); return $old['value'];
            }
            if ((microtime(true) - $started) * 1000 >= $budget) {
                fclose($lock); self::$stats['lock_ms'] += (microtime(true) - $started) * 1000;
                throw new Failure('Quelle wird gerade geladen. Bitte kurz erneut versuchen.', 503);
            }
            usleep(10000);
        }
        self::$stats['lock_ms'] += (microtime(true) - $started) * 1000;
        try {
            $old = $read(); if ($fresh($old)) { self::$stats['hit']++; self::observed($old['at']); return $old['value']; }
            if ($old && $allowStale && ($old['refresh'] ?? '') === $refresh && ($old['retry'] ?? 0) > time()) {
                self::$stats['stale']++; self::warn($scope, new Failure('Offline cache')); return $old['value'];
            }
            self::$dependencies[] = time();
            try {
                try { $value = $loader(); }
                finally { $loadedAt = array_pop(self::$dependencies); self::observed($loadedAt); }
            }
            catch (\Throwable $e) {
                self::warn($scope, $e);
                if (!$old || !$allowStale) { throw $e; }
                // Do not refresh the original success timestamp or replace good data with an error.
                $old['retry'] = time() + 30; $old['refresh'] = $refresh;
                self::write($file, $old); self::$stats['stale']++; return $old['value'];
            }
            if ($cacheable !== null && !$cacheable($value)) { return $value; }
            self::write($file, ['epoch' => $epoch, 'refresh' => $refresh, 'at' => $loadedAt, 'value' => $value]);
            return $value;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
/** Cache below the aggregator: never persist a degraded composite result as complete. */
final class CachedRepository implements Repository {
    private $source; private $scope; private $stale; private $memo = []; private $revision;
    public function __construct(Repository $source, string $scope, bool $allowStale = true) { $this->source = $source; $this->scope = $scope; $this->stale = $allowStale; }
    public function call(string $method, array $args = []): array {
        $args = Contract::arguments($method, $args);
        if (Contract::mutation($method)) {
            try { return $this->source->call($method, $args); }
            finally { $this->memo = []; FileCache::invalidate(); }
        }
        if ($method === 'GetAreas' && $args['recurse']) {
            $out = []; $stack = array_reverse($this->call('GetAreas', ['startArea' => $args['startArea']])['return']); $seen = [];
            while ($stack) {
                $area = array_pop($stack); if (isset($seen[$area])) { continue; } $seen[$area] = true; $out[] = $area;
                try { $children = $this->call('GetAreas', ['startArea' => $area])['return']; }
                catch (\Throwable $e) { if (!$this->stale) { throw $e; } FileCache::warn($this->scope, $e); continue; }
                foreach (array_reverse($children) as $child) { $stack[] = $child; }
            }
            return ['return' => $out];
        }
        $revision = wp_json_encode([get_option('kornsw_kr_cache_epoch', '0'), get_option('kornsw_kr_cache_' . hash('sha256', $this->scope), '0'), get_option('kornsw_kr_cache_refresh', '0')]);
        if ($revision !== $this->revision) { $this->memo = []; $this->revision = $revision; }
        $key = $method . ':' . wp_json_encode($args);
        if (isset($this->memo[$key])) { return $this->memo[$key]; }
        $value = FileCache::remember($key, function () use ($method, $args) { return $this->source->call($method, $args); }, $this->scope, $this->stale);
        return $this->memo[$key] = $value;
    }
}

/** UI-only degradation. Never convert mutation/resource errors into successful results. */
final class UiRepository implements Repository {
    private $source;
    public function __construct(Repository $source) { $this->source = $source; }
    public function errors(): array { return array_merge(method_exists($this->source, 'errors') ? $this->source->errors() : [], FileCache::warnings()); }
    public function call(string $method, array $args = []): array {
        $args = Contract::arguments($method, $args);
        try { return $this->source->call($method, $args); }
        catch (\Throwable $e) {
            if (Contract::mutation($method) || $method === 'GetResourceContent') { throw $e; }
            FileCache::warn('ui:' . ($args['area'] ?? $args['startArea'] ?? '/'), $e);
            if ($method === 'GetAreaCapabilities') { return ['contentLevel'=>0, 'supportsSubAreas'=>true, 'canBeRenamed'=>false, 'canBeDeleted'=>false, 'canAddSubAreas'=>false, 'canAppendContent'=>false, 'canTruncate'=>false, 'supportsResources'=>false]; }
            if ($method === 'GetAreaName') { return ['return'=>basename($args['area']) ?: 'Wissen']; }
            return ['return'=>in_array($method, ['GetAreas','GetAreasByKeyword','GetResources'], true) ? [] : ($method === 'HasDirectContent' ? false : '')];
        }
    }
}
