<?php
namespace KornSW\KnowledgeRepo;

final class Plugin {
    public static function activate(): void {
        foreach (['dom', 'mbstring', 'openssl', 'fileinfo'] as $extension) {
            if (!extension_loaded($extension)) { wp_die('KornSW KnowledgeRepo benötigt die PHP-Erweiterung ' . esc_html($extension) . '.'); }
        }
        add_option('kornsw_kr_settings', ['jwt_ttl' => 86400, 'sources' => [], 'permissions' => ['administrator' => ['page' => 2, 'joplin' => 2, 'ujmw' => 2], 'anonymous' => ['page' => 0, 'joplin' => 0, 'ujmw' => 0]]], '', false);
    }
    public static function boot(): void {
        Admin::boot();
        // init runs before canonical redirects, REST dispatch and theme rendering.
        add_action('init', [self::class, 'route'], 1);
    }
    public static function repository(string $incoming = ''): Repository {
        $mounts = [];
        foreach (Auth::settings()['sources'] ?? [] as $id => $source) {
            $source['token'] = Auth::revealSecret($source['token'] ?? '');
            // A pass-through-only source cannot be authenticated on the other channels.
            if ($source['type'] === 'ujmw' && $source['token'] === '[PASS-TROUGH]' && $incoming === '') { continue; }
            switch ($source['type']) {
                case 'wordpress': $repo = new WordPressRepository($source); break;
                case 'github': $repo = new GitHubRepository($source); break;
                case 'ujmw': $repo = new RemoteRepository($source, $incoming); break;
                default: throw new Failure('Unbekannter Quellentyp.', 500);
            }
            $mounts[$id] = ['mount' => $source['mount'], 'repo' => $repo];
        }
        return new Aggregator($mounts);
    }
    private static function respond(int $status, array $headers, string $body): void {
        status_header($status); nocache_headers();
        header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
        foreach ($headers as $key => $value) { header($key . ': ' . $value); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') { echo $body; }
        exit;
    }
    private static function body(): string {
        $handle = fopen('php://input', 'rb'); $body = stream_get_contents($handle, 16777217); fclose($handle);
        if (strlen($body) > 16777216) { throw new Failure('Anfrage überschreitet 16 MiB.', 413); }
        return $body;
    }
    public static function route(): void {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); $prefix = parse_url(home_url('/wiki'), PHP_URL_PATH);
        if ($path !== $prefix && strpos($path, $prefix . '/') !== 0) { return; }
        $relative = substr($path, strlen($prefix)); $method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; $json = false; $dav = false;
        try {
            if (preg_match('~^/joplin/([^/]+)(/.*)?$~', $relative, $match)) {
                $dav = true; $profile = rawurldecode($match[1]);
                if (strlen($profile) > 256 || preg_match('/[\x00-\x1f\/\\\\]/', $profile) || in_array($profile, ['.', '..'], true)) { throw new Failure('Ungültiges Sync-Profil.', 400); }
                $auth = Auth::basic(); $level = Auth::requireAccess('joplin', $auth['user']);
                wp_set_current_user($auth['user']->ID);
                $store = new SyncStore($auth['name'], $profile);
                $davPath = rawurldecode($match[2] ?? '/');
                $handler = new Joplin(self::repository(), $store, $level === 2, $prefix . '/joplin/' . $match[1]);
                $headers = [];
                foreach (['depth', 'destination', 'overwrite', 'if-match', 'if-none-match'] as $key) {
                    $server = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
                    if (isset($_SERVER[$server])) { $headers[$key] = $_SERVER[$server]; }
                }
                [$code, $responseHeaders, $body] = $handler->handle($method, $davPath, self::body(), $headers);
                unset($handler, $store); self::respond($code, $responseHeaders, $body);
            }
            if (strpos($relative, '/ujmw/') === 0) {
                $json = true;
                if ($method !== 'POST') { throw new Failure('UJMW benötigt POST.', 405); }
                if (!preg_match('~^/ujmw/(?:IKnowledgeRepository/)?([A-Za-z]+)/*$~', $relative, $match)) { throw new Failure('UJMW-Operation fehlt.', 404); }
                $auth = Auth::bearer(); $level = Auth::requireAccess('ujmw', $auth['user']); wp_set_current_user($auth['user']->ID);
                $operation = $match[1];
                if (Contract::mutation($operation) && $level !== 2) { throw new Failure('Schreibzugriff nicht erlaubt.', 403); }
                $a = json_decode(self::body(), true);
                if (!is_array($a)) { throw new Failure('JSON-Objekt erwartet.', 400); }
                $r = self::repository($auth['token'])->call($operation, $a);
                self::respond(200, ['Content-Type' => 'application/json; charset=utf-8'], wp_json_encode($r));
            }
            $level = Auth::requireAccess('page');
            if (strpos($relative, '/_resource/') === 0) {
                if (!in_array($method, ['GET', 'HEAD'], true)) { throw new Failure('Methode nicht erlaubt.', 405); }
                $id = rawurldecode(substr($relative, strlen('/_resource/')));
                $r = self::repository()->call('GetResourceContent', ['resourceId' => $id]);
                $bytes = base64_decode($r['return'], true);
                if ($bytes === false) { throw new Failure('Ressource ungültig.', 502); }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                $inline = in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'], true);
                self::respond(200, ['Content-Type' => $inline ? $mime : 'application/octet-stream', 'Content-Disposition' => $inline ? 'inline' : 'attachment; filename="resource"', 'Content-Security-Policy' => "default-src 'none'; sandbox"], $bytes);
            }
            $token = null; $message = '';
            $area = Path::normalize(rawurldecode($relative ?: '/')); $repo = self::repository();
            if ($method === 'POST') {
                $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
                if (!is_user_logged_in() || !wp_verify_nonce($nonce, 'kornsw_kr_wiki')) { throw new Failure('Sitzung abgelaufen. Bitte Seite neu laden.', 403); }
                $action = $_POST['kr_action'] ?? '';
                if ($action === 'token') { $token = Auth::issue(); }
                elseif ($action === 'revoke') {
                    $uid = get_current_user_id(); update_user_meta($uid, 'kornsw_kr_token_version', (int) get_user_meta($uid, 'kornsw_kr_token_version', true) + 1); $message = 'Deine bisherigen Tokens wurden widerrufen.';
                } else {
                    if ($level !== 2) { throw new Failure('Schreibzugriff nicht erlaubt.', 403); }
                    $args = ['area' => $area];
                    if ($action === 'TryAppendContent') { $args['content'] = wp_unslash($_POST['content'] ?? ''); }
                    elseif ($action === 'TryReplace') { $args['newContent'] = wp_unslash($_POST['content'] ?? ''); }
                    elseif ($action === 'TryAddSubArea') { $args += ['name' => wp_unslash($_POST['name'] ?? ''), 'kind' => (int) ($_POST['kind'] ?? 1)]; }
                    else { throw new Failure('Unbekannte Aktion.', 400); }
                    $r = $repo->call($action, $args);
                    $message = $r['return'] ? 'Änderung gespeichert.' : 'Die Quelle unterstützt diese Änderung nicht oder lehnt sie ab.';
                }
            } elseif (!in_array($method, ['GET', 'HEAD'], true)) { throw new Failure('Methode nicht erlaubt.', 405); }
            self::wiki($repo, $area, $level, $message, $token);
        } catch (Failure $e) {
            $headers = ['Content-Type' => $json ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'];
            if ($e->status === 401) { $headers['WWW-Authenticate'] = $dav ? 'Basic realm="KornSW KnowledgeRepo", charset="UTF-8"' : 'Bearer'; }
            if ($e->status === 503) { $headers['Retry-After'] = '5'; }
            self::respond($e->status, $headers, $json ? wp_json_encode(['fault' => $e->getMessage()]) : $e->getMessage());
        }
    }
    private static function link(string $area): string { return home_url('/wiki' . implode('/', array_map('rawurlencode', explode('/', $area)))); }
    private static function wiki(Repository $repo, string $area, int $permission, string $message, ?array $token): void {
        $title = $repo->call('GetAreaName', ['area' => $area])['return'];
        $text = $repo->call('GetAggregatedContent', ['area' => $area])['return'];
        $text = preg_replace_callback('/knowledge-resource:([A-Za-z0-9._~-]+)/', static function ($m) { return home_url('/wiki/_resource/' . rawurlencode($m[1])); }, $text);
        if (!class_exists(Vendor\Parsedown::class, false)) { require_once dirname(KORNSW_KR_FILE) . '/vendor/Parsedown.php'; }
        $markdown = new Vendor\Parsedown(); $markdown->setSafeMode(true);
        ob_start();
        ?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html($title); ?> · Wissen</title><link rel="stylesheet" href="<?php echo esc_url(plugins_url('assets/wiki.css', KORNSW_KR_FILE)); ?>"></head><body><header><a href="<?php echo esc_url(home_url('/wiki/')); ?>">Wissen</a><span>KornSW KnowledgeRepo</span><?php if (!is_user_logged_in()) { ?><a href="<?php echo esc_url(wp_login_url(self::link($area))); ?>">Anmelden</a><?php } ?></header><div class="layout"><nav aria-label="Wissensbereiche"><h2>Bereiche</h2><?php
        if ($area !== '/') { echo '<a href="' . esc_url(self::link(Path::parent($area))) . '">← Übergeordneter Bereich</a>'; }
        foreach ($repo->call('GetAreas', ['recurse' => false, 'startArea' => $area])['return'] as $child) { echo '<a href="' . esc_url(self::link($child)) . '">' . esc_html($repo->call('GetAreaName', ['area' => $child])['return']) . '</a>'; }
        ?></nav><main><h1><?php echo esc_html($title); ?></h1><?php if ($message !== '') { echo '<p role="status" class="notice">' . esc_html($message) . '</p>'; } ?><article><?php echo wp_kses_post($markdown->text($text)); ?></article><?php
        if ($permission === 2 && is_user_logged_in()) {
            ?><details><summary>Inhalt bearbeiten</summary><form method="post"><?php wp_nonce_field('kornsw_kr_wiki'); ?><textarea name="content" rows="14"><?php echo esc_textarea($repo->call('GetAggregatedContent', ['area' => $area])['return']); ?></textarea><button name="kr_action" value="TryReplace">Inhalt ersetzen</button><button name="kr_action" value="TryAppendContent">Als Ergänzung zusammenführen</button></form><form method="post"><?php wp_nonce_field('kornsw_kr_wiki'); ?><input name="name" placeholder="Name" required><select name="kind"><option value="1">Inhaltsbereich</option><option value="0">Strukturbereich</option></select><button name="kr_action" value="TryAddSubArea">Unterbereich anlegen</button></form></details><?php
        }
        if (is_user_logged_in() && Auth::permission('ujmw') > 0) {
            ?><details <?php echo $token ? 'open' : ''; ?>><summary>API-Zugang</summary><p>Vertragsbasis: <code><?php echo esc_html(home_url('/wiki/ujmw/IKnowledgeRepository/')); ?></code></p><form method="post"><?php wp_nonce_field('kornsw_kr_wiki'); ?><button name="kr_action" value="token">JWT erzeugen</button><button name="kr_action" value="revoke">Meine Tokens widerrufen</button></form><?php
            if ($token) { echo '<p>Gültig bis ' . esc_html(gmdate('d.m.Y H:i', $token['expires'])) . ' UTC. Als <code>Authorization: Bearer TOKEN</code> senden.</p><textarea readonly rows="5" aria-label="JWT">' . esc_textarea($token['token']) . '</textarea>'; }
            ?></details><?php
        }
        ?></main></div></body></html><?php
        self::respond(200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'"], ob_get_clean());
    }
}
