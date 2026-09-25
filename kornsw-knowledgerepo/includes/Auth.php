<?php
namespace KornSW\KnowledgeRepo;

final class Auth {
    private static $introspectionHit = false;
    public static function settings(): array { return get_option('kornsw_kr_settings', []); }
    public static function introspectionHit(): bool { return self::$introspectionHit; }
    public static function invalidateIntrospection(...$args): void {
        try { $epoch = bin2hex(random_bytes(16)); }
        catch (\Throwable $e) { $epoch = hash('sha256', microtime(true) . ':' . mt_rand()); }
        update_option('kornsw_kr_auth_epoch', $epoch, true);
    }
    public static function header(): string {
        return trim($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }
    /** The highest explicitly granted role permission wins; anonymous is capped at read. */
    public static function permission(string $channel, $user = null): int {
        if ($user === null) { $user = wp_get_current_user(); }
        $matrix = self::settings()['permissions'] ?? [];
        if (!$user || !$user->ID) { return min(1, (int) ($matrix['anonymous'][$channel] ?? 0)); }
        $level = 0;
        foreach ($user->roles as $role) { $level = max($level, (int) ($matrix[$role][$channel] ?? 0)); }
        return min(2, $level);
    }
    public static function requireAccess(string $channel, $user = null): int {
        $level = self::permission($channel, $user);
        if (!$level) { throw new Failure('Zugriff nicht erlaubt.', 403); }
        return $level;
    }
    public static function basic(): array {
        $name = $_SERVER['PHP_AUTH_USER'] ?? ''; $password = $_SERVER['PHP_AUTH_PW'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', self::header(), $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded === false || strpos($decoded, ':') === false) { throw new Failure('Anmeldung erforderlich.', 401); }
            [$name, $password] = explode(':', $decoded, 2);
        }
        if ($name === 'anonymous' && $password === 'anonymous') { return ['user' => new \WP_User(0), 'name' => 'anonymous']; }
        if ($name === '' || $password === '') { throw new Failure('Anmeldung erforderlich.', 401); }
        $user = wp_authenticate($name, $password);
        if (is_wp_error($user)) { throw new Failure('Anmeldung fehlgeschlagen.', 401); }
        return ['user' => $user, 'name' => $name];
    }
    private static function secret(): string {
        $secret = get_option('kornsw_kr_jwt_secret', '');
        if ($secret === '') { $new = Path::b64(random_bytes(48)); add_option('kornsw_kr_jwt_secret', $new, '', true); $secret = get_option('kornsw_kr_jwt_secret'); }
        return $secret;
    }
    private static function principal(int $id, array $roles): object {
        $principal = new \stdClass(); $principal->ID = $id; $principal->roles = array_values($roles); return $principal;
    }
    /** Cache only WordPress user existence, roles and revocation version. JWT crypto/time checks stay per request. */
    private static function introspect(string $token, array $claims): object {
        self::$introspectionHit = false;
        $ttl = max(0, min(300, (int) (self::settings()['auth_cache_ttl'] ?? 30)));
        if ($ttl === 0) {
            $user = get_user_by('id', (int) $claims['sub']);
            if (!$user || (int) ($claims['ver'] ?? -1) !== (int) get_user_meta($user->ID, 'kornsw_kr_token_version', true)) { throw new Failure('Token widerrufen.', 401); }
            return self::principal((int) $user->ID, array_values(array_filter($user->roles, 'is_string')));
        }
        $epoch = (string) get_option('kornsw_kr_auth_epoch', '0');
        $root = FileCache::root() . '/auth-v1';
        $id = hash_hmac('sha256', $token, wp_salt('auth')); $file = $root . '/' . $id . '.cache';
        if ($ttl > 0) {
            // Authentication must continue through WordPress if an object-cache plugin or the
            // optional filesystem cache is unavailable. The cache is only an optimization.
            try { $cached = function_exists('wp_cache_get') ? wp_cache_get($id, 'kornsw_kr_auth') : false; }
            catch (\Throwable $e) { $cached = false; }
            if (!is_array($cached)) {
                try { $cached = FileCache::read($file); }
                catch (\Throwable $e) { $cached = false; }
            }
            if ($cached && ($cached['epoch'] ?? '') === $epoch && (int) ($cached['until'] ?? 0) >= time()
                && (int) ($cached['id'] ?? 0) === (int) $claims['sub'] && (int) ($cached['ver'] ?? -1) === (int) ($claims['ver'] ?? -2)
                && isset($cached['roles']) && is_array($cached['roles'])) {
                self::$introspectionHit = true; return self::principal((int) $cached['id'], $cached['roles']);
            }
        }
        $user = get_user_by('id', (int) $claims['sub']);
        if (!$user || (int) ($claims['ver'] ?? -1) !== (int) get_user_meta($user->ID, 'kornsw_kr_token_version', true)) {
            throw new Failure('Token widerrufen.', 401);
        }
        $roles = array_values(array_filter($user->roles, 'is_string'));
        if ($ttl > 0) {
            $record = ['epoch'=>$epoch, 'until'=>min((int) $claims['exp'], time() + $ttl),
                'id'=>(int) $user->ID, 'roles'=>$roles, 'ver'=>(int) ($claims['ver'] ?? -1)];
            try {
                if (function_exists('wp_cache_set')) { wp_cache_set($id, $record, 'kornsw_kr_auth', $ttl); }
            } catch (\Throwable $e) { /* Cache failures must not reject a valid token. */ }
            try {
                if (is_dir($root) || wp_mkdir_p($root)) { @chmod($root, 0700); FileCache::write($file, $record); }
            } catch (\Throwable $e) { /* Cache failures must not reject a valid token. */ }
        }
        return self::principal((int) $user->ID, $roles);
    }
    public static function issue(): array {
        $user = wp_get_current_user();
        if (!$user->ID) { throw new Failure('Bitte zuerst anmelden.', 401); }
        self::requireAccess('page', $user); self::requireAccess('ujmw', $user);
        $ttl = max(300, min(31536000, (int) (self::settings()['jwt_ttl'] ?? 86400)));
        $expires = time() + $ttl;
        $header = Path::b64('{"alg":"HS256","typ":"JWT"}');
        $claims = ['iss' => home_url('/wiki/'), 'aud' => 'KornSW-KnowledgeRepo', 'sub' => (string) $user->ID,
            'iat' => time(), 'nbf' => time() - 5, 'exp' => $expires, 'jti' => bin2hex(random_bytes(16)),
            'ver' => (int) get_user_meta($user->ID, 'kornsw_kr_token_version', true)];
        $payload = $header . '.' . Path::b64(wp_json_encode($claims));
        return ['token' => $payload . '.' . Path::b64(hash_hmac('sha256', $payload, self::secret(), true)), 'expires' => $expires];
    }
    public static function bearer(): array {
        self::$introspectionHit = false;
        if (self::header() === '') { return ['user' => self::principal(0, []), 'token' => '']; }
        if (!preg_match('/^(?:Bearer\s+)?([A-Za-z0-9_.-]+)$/i', self::header(), $m) || strlen($m[1]) > 8192) { throw new Failure('JWT erforderlich.', 401); }
        $parts = explode('.', $m[1]);
        if (count($parts) !== 3) { throw new Failure('Ungültiger Token.', 401); }
        try { $header = json_decode(Path::unb64($parts[0]), true); $claims = json_decode(Path::unb64($parts[1]), true); }
        catch (Failure $e) { throw new Failure('Ungültiger Token.', 401); }
        $expected = Path::b64(hash_hmac('sha256', $parts[0] . '.' . $parts[1], self::secret(), true));
        if (($header['alg'] ?? '') !== 'HS256' || !hash_equals($expected, $parts[2]) || !is_array($claims)
            || ($claims['iss'] ?? '') !== home_url('/wiki/') || ($claims['aud'] ?? '') !== 'KornSW-KnowledgeRepo'
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time() || ($claims['nbf'] ?? PHP_INT_MAX) > time()
            || !ctype_digit((string) ($claims['sub'] ?? ''))) { throw new Failure('Token ungültig oder abgelaufen.', 401); }
        $user = self::introspect($m[1], $claims);
        return ['user' => $user, 'token' => $m[1]];
    }
    public static function protectSecret(string $value): string {
        if ($value === '' || $value === '[PASS-TROUGH]') { return $value; }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new Failure('Zugangsdaten konnten nicht verschlüsselt werden.', 500); }
        return 'enc:' . base64_encode($iv . $tag . $cipher);
    }
    public static function revealSecret(string $value): string {
        if (strpos($value, 'enc:') !== 0) { return $value; }
        $raw = base64_decode(substr($value, 4), true);
        if ($raw === false || strlen($raw) < 28) { throw new Failure('Gespeicherte Zugangsdaten ungültig.', 500); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new Failure('Zugangsdaten nach Änderung der WordPress-Salts bitte erneut speichern.', 503); }
        return $plain;
    }
}
/** Disk state uses one lock and an atomically replaced document per profile. */
final class SyncStore {
    private $file; private $lock; public $state;
    public function __construct(string $name, string $profile) {
        // .NET String.Length counts UTF-16 code units, not UTF-8 bytes.
        $length = static function ($s) { return (int) (strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2); };
        $identity = 'JoplinProfile:v1:' . $length($name) . ':' . $name . ':' . $length($profile) . ':' . $profile;
        $root = defined('KORNSW_KR_STATE_DIR') ? KORNSW_KR_STATE_DIR : WP_CONTENT_DIR . '/kornsw-knowledge-state-' . substr(hash('sha256', ABSPATH), 0, 16);
        if (!is_dir($root) && !wp_mkdir_p($root)) { throw new Failure('Sync-Verzeichnis nicht verfügbar.', 503); }
        @chmod($root, 0700);
        $id = hash('sha256', $identity); $this->file = $root . '/' . $id . '.state';
        $this->lock = @fopen($root . '/' . $id . '.lock', 'c');
        if (!$this->lock || !@flock($this->lock, LOCK_EX | LOCK_NB)) { throw new Failure('Sync-Profil wird gerade verwendet.', 503); }
        $this->state = ['created' => time(), 'items' => [], 'resources' => [], 'files' => [], 'collections' => ['/', '/locks', '/temp', '/.resource', '/.sync', '/.lock'], 'pending' => []];
        if (is_file($this->file)) {
            $encrypted = @file_get_contents($this->file);
            if ($encrypted === false) { throw new Failure('Sync-Zustand nicht lesbar.', 503); }
            $data = json_decode(Auth::revealSecret($encrypted), true);
            if (!is_array($data) || !isset($data['items'], $data['resources'], $data['files'], $data['created'])) { throw new Failure('Sync-Zustand beschädigt; keine automatische Neuerstellung.', 503); }
            $this->state = $data;
        }
    }
    public function save(): void {
        $bytes = wp_json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bytes === false || strlen($bytes) > 134217728) { throw new Failure('Sync-Zustand überschreitet 128 MiB.', 507); }
        $bytes = Auth::protectSecret($bytes);
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($temporary); throw new Failure('Sync-Zustand konnte nicht gespeichert werden.', 503); }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) { @unlink($temporary); throw new Failure('Sync-Zustand konnte nicht ersetzt werden.', 503); }
    }
    public function __destruct() { if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); } }
}
