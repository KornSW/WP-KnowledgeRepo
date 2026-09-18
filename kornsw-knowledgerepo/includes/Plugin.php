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
    public static function repository(string $incoming = '', bool $tolerant = false): Repository {
        $mounts = [];
        foreach (Auth::settings()['sources'] ?? [] as $id => $source) {
            $mount = ['mount' => $source['mount'], 'label' => $source['label'] ?: $source['mount']];
            try {
                $source['token'] = Auth::revealSecret($source['token'] ?? '');
                if ($source['type'] === 'ujmw' && $source['token'] === '[PASS-TROUGH]' && $incoming === '') { continue; }
                switch ($source['type']) {
                    case 'wordpress': $mount['repo'] = new WordPressRepository($source); break;
                    case 'github': $mount['repo'] = new GitHubRepository($source); break;
                    case 'ujmw': $mount['repo'] = new RemoteRepository($source, $incoming); break;
                    default: throw new Failure('Unbekannter Quellentyp.', 500);
                }
            } catch (Failure $e) {
                if (!$tolerant) { throw $e; }
                $mount['error'] = $e;
            }
            $mounts[$id] = $mount;
        }
        return new Aggregator($mounts, $tolerant);
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
            if ($relative === '/_search') { $json = true; }
            $level = Auth::requireAccess('page');
            if (strpos($relative, '/_resource/') === 0) {
                if (!in_array($method, ['GET', 'HEAD'], true)) { throw new Failure('Methode nicht erlaubt.', 405); }
                $id = rawurldecode(substr($relative, strlen('/_resource/')));
                $r = self::repository('', true)->call('GetResourceContent', ['resourceId' => $id]);
                $bytes = base64_decode($r['return'], true);
                if ($bytes === false) { throw new Failure('Ressource ungültig.', 502); }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                $inline = in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'], true);
                self::respond(200, ['Content-Type' => $inline ? $mime : 'application/octet-stream', 'Content-Disposition' => $inline ? 'inline' : 'attachment; filename="resource"', 'Content-Security-Policy' => "default-src 'none'; sandbox"], $bytes);
            }
            if ($relative === '/_search') {
                $json = true;
                if ($method !== 'GET') { throw new Failure('Suche benötigt GET.', 405); }
                $query = trim(sanitize_text_field(wp_unslash($_GET['q'] ?? '')));
                if (mb_strlen($query) > 200) { throw new Failure('Suchbegriff ist zu lang.', 400); }
                $repo = self::repository('', true);
                self::respond(200, ['Content-Type' => 'application/json; charset=utf-8'], wp_json_encode(self::search($repo, $query)));
            }
            $token = null; $message = ''; $activeDialog = '';
            $area = Path::normalize(rawurldecode($relative ?: '/')); $repo = self::repository('', true);
            if ($method === 'POST') {
                $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
                if (!is_user_logged_in() || !wp_verify_nonce($nonce, 'kornsw_kr_wiki')) { throw new Failure('Sitzung abgelaufen. Bitte Seite neu laden.', 403); }
                $action = $_POST['kr_action'] ?? '';
                $activeDialog = in_array($action, ['token', 'revoke'], true) ? 'api-dialog' : 'edit-dialog';
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
                    try {
                        $r = $repo->call($action, $args);
                        $message = $r['return'] ? 'Änderung gespeichert.' : 'Die Quelle unterstützt diese Änderung nicht oder lehnt sie ab.';
                    } catch (Failure $e) { $message = $e->getMessage(); }
                }
            } elseif (!in_array($method, ['GET', 'HEAD'], true)) { throw new Failure('Methode nicht erlaubt.', 405); }
            $document = self::documentArea($repo, $area);
            if ($method === 'GET' && $document !== $area) {
                $view = self::documentView($repo, $document);
                $anchor = $view['anchors'][$area] ?? '';
                wp_safe_redirect(self::link($document) . ($anchor !== '' ? '#' . $anchor : ''), 302); exit;
            }
            self::wiki($repo, $document, $level, $message, $token, $activeDialog);
        } catch (Failure $e) {
            $headers = ['Content-Type' => $json ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'];
            if ($e->status === 401) { $headers['WWW-Authenticate'] = $dav ? 'Basic realm="KornSW KnowledgeRepo", charset="UTF-8"' : 'Bearer'; }
            if ($e->status === 503) { $headers['Retry-After'] = '5'; }
            self::respond($e->status, $headers, $json ? wp_json_encode(['fault' => $e->getMessage()]) : $e->getMessage());
        }
    }
    private static function link(string $area): string { return home_url('/wiki' . implode('/', array_map('rawurlencode', explode('/', $area)))); }
    /** Breadcrumb/page navigation stops at the first content container. */
    public static function documentArea(Repository $repo, string $area): string {
        $chain = []; $path = Path::normalize($area);
        while ($path !== '/') { $chain[] = $path; $path = Path::parent($path); }
        foreach (array_reverse($chain) as $candidate) {
            $level = $repo->call('GetAreaCapabilities', ['area' => $candidate])['contentLevel'];
            if (in_array($level, [2, 'ContentContainer'], true)) { return $candidate; }
        }
        return $area;
    }
    /** Assign deterministic anchors to rendered headings and their logical child areas. */
    public static function documentView(Repository $repo, string $area): array {
        $text = $repo->call('GetAggregatedContent', ['area' => $area])['return'];
        $display = preg_replace_callback('/knowledge-resource:([A-Za-z0-9._~-]+)/', static function ($m) { return home_url('/wiki/_resource/' . rawurlencode($m[1])); }, $text);
        if (!class_exists(Vendor\Parsedown::class, false)) { require_once dirname(KORNSW_KR_FILE) . '/vendor/Parsedown.php'; }
        $markdown = new Vendor\Parsedown(); $markdown->setSafeMode(true);
        $html = wp_kses_post($markdown->text($display));
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="kr-body">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom); $wrapper = $dom->getElementById('kr-body');
        $children = $repo->call('GetAreas', ['recurse' => true, 'startArea' => $area])['return'];
        $names = [];
        foreach ($children as $child) {
            $name = $repo->call('GetAreaName', ['area' => $child])['return'];
            $names[$child] = trim(html_entity_decode(strip_tags($markdown->line($name)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $outline = []; $anchors = []; $cursor = 0; $occurrences = [];
        foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $wrapper) as $heading) {
            $label = trim($heading->textContent); $logical = null;
            for ($i = $cursor; $i < count($children); $i++) {
                if ($names[$children[$i]] === $label) { $logical = $children[$i]; $cursor = $i + 1; break; }
            }
            $occurrences[$label] = ($occurrences[$label] ?? 0) + 1;
            $identity = $logical ?? $area . "\0" . $label . "\0" . $occurrences[$label];
            $id = 'section-' . substr(hash('sha256', $identity), 0, 16);
            $heading->setAttribute('id', $id);
            if ($logical !== null) { $anchors[$logical] = $id; }
            $outline[] = ['id' => $id, 'label' => $label, 'level' => (int) substr($heading->tagName, 1)];
        }
        $html = '';
        foreach ($wrapper->childNodes as $node) { $html .= $dom->saveHTML($node); }
        return ['text' => $text, 'html' => $html, 'outline' => $outline, 'anchors' => $anchors];
    }
    public static function search(Repository $repo, string $query): array {
        $results = []; $views = []; $matches = $query === '' ? [] : $repo->call('GetAreasByKeyword', ['keyword' => $query, 'startArea' => '/'])['return'];
        foreach (array_slice($matches, 0, 100) as $area) {
            $document = self::documentArea($repo, $area); $anchor = '';
            if ($document !== $area) {
                if (!isset($views[$document])) { $views[$document] = self::documentView($repo, $document); }
                $anchor = $views[$document]['anchors'][$area] ?? '';
            }
            $snippet = preg_replace('/\s+/', ' ', strip_tags($repo->call('GetDirectContent', ['area' => $area])['return']));
            $position = mb_stripos($snippet, $query);
            $start = $position === false ? 0 : max(0, $position - 70);
            $results[] = ['title' => $repo->call('GetAreaName', ['area' => $area])['return'], 'path' => $area,
                'url' => self::link($document) . ($anchor !== '' ? '#' . $anchor : ''),
                'snippet' => ($start ? '…' : '') . mb_substr($snippet, $start, 220) . (mb_strlen($snippet) > $start + 220 ? '…' : '')];
        }
        return ['results' => $results, 'more' => count($matches) > 100, 'warnings' => $repo instanceof Aggregator ? $repo->errors() : []];
    }
    private static function dialogStart(string $id, string $title): void {
        echo '<dialog id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id . '-title') . '"><div class="dialog-head"><h2 id="' . esc_attr($id . '-title') . '">' . esc_html($title) . '</h2><button type="button" class="quiet close-dialog" aria-label="Schließen">×</button></div>';
    }
    private static function wiki(Repository $repo, string $area, int $permission, string $message, ?array $token, string $activeDialog = ''): void {
        $title = $repo->call('GetAreaName', ['area' => $area])['return'];
        $content = in_array($repo->call('GetAreaCapabilities', ['area' => $area])['contentLevel'], [2, 'ContentContainer'], true);
        $view = $content ? self::documentView($repo, $area) : ['text' => '', 'html' => '', 'outline' => [], 'anchors' => []];
        $navArea = $content ? Path::parent($area) : $area;
        $children = $repo->call('GetAreas', ['recurse' => false, 'startArea' => $navArea])['return'];
        $links = [];
        foreach ($children as $child) { $links[$child] = $repo->call('GetAreaName', ['area' => $child])['return']; }
        $crumbs = []; $p = $area;
        while ($p !== '/') { $crumbs[$p] = $repo->call('GetAreaName', ['area' => $p])['return']; $p = Path::parent($p); }
        $crumbs = ['/'=>'Wissen'] + array_reverse($crumbs, true);
        $canEdit = $permission === 2 && is_user_logged_in(); $canToken = is_user_logged_in() && Auth::permission('ujmw') > 0;
        $scriptNonce = base64_encode(random_bytes(18));
        $errors = $repo instanceof Aggregator ? $repo->errors() : [];
        ob_start();
        ?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html($title); ?> · Wissen</title><link rel="stylesheet" href="<?php echo esc_url(plugins_url('assets/wiki.css', KORNSW_KR_FILE) . '?ver=0.1.1'); ?>"></head><body>
        <header class="site-header"><a class="brand" href="<?php echo esc_url(home_url('/wiki/')); ?>">Wissen</a><div class="header-actions">
        <form id="wiki-search" role="search" action="<?php echo esc_url(home_url('/wiki/_search')); ?>"><input type="search" name="q" maxlength="200" aria-label="Wissen durchsuchen" placeholder="Wissen durchsuchen …" required><button type="submit" class="quiet">Suchen</button></form>
        <?php if ($canEdit) { ?><button type="button" class="quiet" data-dialog="edit-dialog">Bearbeiten</button><?php } ?>
        <?php if ($canToken) { ?><button type="button" class="quiet" data-dialog="api-dialog">API-Zugang</button><?php } ?>
        <?php if (!is_user_logged_in()) { ?><a class="quiet" href="<?php echo esc_url(wp_login_url(self::link($area))); ?>">Anmelden</a><?php } ?>
        </div></header><nav class="breadcrumbs" aria-label="Breadcrumb"><ol><?php
        foreach ($crumbs as $path => $name) {
            echo '<li>' . ($path === $area ? '<span aria-current="page">' . esc_html($name) . '</span>' : '<a href="' . esc_url(self::link($path)) . '">' . esc_html($name) . '</a>') . '</li>';
        }
        ?></ol></nav><div class="layout"><nav class="area-nav" aria-label="Wissensbereiche"><h2>Bereiche</h2><?php
        foreach ($links as $path => $name) { echo '<a' . ($path === $area ? ' aria-current="page"' : '') . ' href="' . esc_url(self::link($path)) . '">' . esc_html($name) . '</a>'; }
        ?></nav><main><h1><?php echo esc_html($title); ?></h1><?php
        if ($errors) {
            echo '<details class="source-status"><summary>' . count($errors) . ' Quelle(n) momentan nicht verfügbar</summary><ul>';
            foreach ($errors as $error) { echo '<li><strong>' . esc_html($error['label']) . '</strong>: ' . esc_html($error['message']) . '</li>'; }
            echo '</ul></details>';
        }
        if ($content) { echo '<article id="document-content">' . $view['html'] . '</article>'; }
        else {
            echo '<ul class="document-list">';
            foreach ($links as $path => $name) { echo '<li><a href="' . esc_url(self::link($path)) . '">' . esc_html($name) . '</a></li>'; }
            echo '</ul>';
            if (!$links && !$errors) { echo '<p class="muted">Hier sind noch keine Inhalte vorhanden.</p>'; }
        }
        ?></main><aside class="outline" aria-label="Dokumentgliederung"><?php
        if ($view['outline']) {
            echo '<h2>Auf dieser Seite</h2><nav>';
            foreach ($view['outline'] as $item) { echo '<a class="outline-level-' . $item['level'] . '" href="#' . esc_attr($item['id']) . '">' . esc_html($item['label']) . '</a>'; }
            echo '</nav>';
        }
        ?></aside></div><?php
        self::dialogStart('search-dialog', 'Wissen durchsuchen');
        echo '<p id="search-status" role="status"></p><div id="search-results"></div></dialog>';
        if ($canEdit) {
            self::dialogStart('edit-dialog', 'Inhalt bearbeiten');
            if ($message !== '' && $activeDialog === 'edit-dialog') { echo '<p class="notice" role="status">' . esc_html($message) . '</p>'; }
            $editText = $view['text'];
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) { $editText = wp_unslash($_POST['content']); }
            ?><form method="post" action="<?php echo esc_url(self::link($area)); ?>"><?php wp_nonce_field('kornsw_kr_wiki'); ?><label for="edit-content">Markdown</label><textarea id="edit-content" name="content" rows="16"><?php echo esc_textarea($editText); ?></textarea><div class="form-actions"><button name="kr_action" value="TryReplace">Inhalt ersetzen</button><button name="kr_action" value="TryAppendContent">Ergänzung zusammenführen</button></div></form><form class="new-area" method="post" action="<?php echo esc_url(self::link($area)); ?>"><?php wp_nonce_field('kornsw_kr_wiki'); ?><input name="name" aria-label="Name des Unterbereichs" placeholder="Neuer Unterbereich" required><select name="kind" aria-label="Art des Unterbereichs"><option value="1">Inhalt</option><option value="0">Struktur</option></select><button name="kr_action" value="TryAddSubArea">Anlegen</button></form></dialog><?php
        }
        if ($canToken) {
            self::dialogStart('api-dialog', 'API-Zugang');
            if ($message !== '' && $activeDialog === 'api-dialog') { echo '<p class="notice" role="status">' . esc_html($message) . '</p>'; }
            ?><p>Vertragsbasis</p><code class="api-url"><?php echo esc_html(home_url('/wiki/ujmw/IKnowledgeRepository/')); ?></code><form method="post" action="<?php echo esc_url(self::link($area)); ?>"><?php wp_nonce_field('kornsw_kr_wiki'); ?><button name="kr_action" value="token">JWT erzeugen</button><button name="kr_action" value="revoke">Meine Tokens widerrufen</button></form><?php
            if ($token) { echo '<p>Gültig bis ' . esc_html(gmdate('d.m.Y H:i', $token['expires'])) . ' UTC. Header: <code>Authorization: Bearer TOKEN</code></p><textarea readonly rows="5" aria-label="JWT">' . esc_textarea($token['token']) . '</textarea>'; }
            echo '</dialog>';
        }
        ?><script nonce="<?php echo esc_attr($scriptNonce); ?>">
        (() => {
            const show = id => { const dialog = document.getElementById(id); if (dialog && !dialog.open) dialog.showModal(); };
            document.querySelectorAll('[data-dialog]').forEach(button => button.addEventListener('click', () => show(button.dataset.dialog)));
            document.querySelectorAll('.close-dialog').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
            document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', event => {
                if (event.target !== dialog) return;
                const r = dialog.getBoundingClientRect();
                if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close();
            }));
            let controller;
            document.getElementById('wiki-search').addEventListener('submit', async event => {
                event.preventDefault(); const form = event.currentTarget; const query = form.elements.q.value.trim(); if (!query) return;
                if (controller) controller.abort(); const active = new AbortController(); controller = active;
                const status = document.getElementById('search-status'); const results = document.getElementById('search-results');
                results.replaceChildren(); status.textContent = 'Suche läuft …'; show('search-dialog');
                try {
                    const response = await fetch(form.action + '?q=' + encodeURIComponent(query), {credentials: 'same-origin', signal: active.signal, headers: {'Accept': 'application/json'}});
                    const data = await response.json(); if (!response.ok) throw new Error(data.fault || 'Suche derzeit nicht verfügbar.');
                    if (controller !== active) return;
                    status.textContent = data.results.length + ' Treffer für „' + query + '“' + (data.more ? ' (erste 100)' : '');
                    data.results.forEach(result => {
                        const card = document.createElement('div'); card.className = 'search-result'; const link = document.createElement('a');
                        link.href = result.url; link.textContent = result.title; link.addEventListener('click', () => document.getElementById('search-dialog').close());
                        const path = document.createElement('small'); path.textContent = result.path; const snippet = document.createElement('p'); snippet.textContent = result.snippet;
                        card.append(link, path, snippet); results.append(card);
                    });
                    (data.warnings || []).forEach(warning => { const p = document.createElement('p'); p.className = 'muted'; p.textContent = warning.label + ': ' + warning.message; results.append(p); });
                } catch (error) { if (error.name !== 'AbortError') status.textContent = error.message || 'Suche derzeit nicht verfügbar.'; }
            });
            const outlineLinks = [...document.querySelectorAll('.outline a')];
            if ('IntersectionObserver' in window && outlineLinks.length) {
                const observer = new IntersectionObserver(entries => { for (const entry of entries) if (entry.isIntersecting) {
                    outlineLinks.forEach(link => { if (link.hash === '#' + entry.target.id) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current'); });
                } }, {rootMargin: '-8% 0px -70% 0px'});
                document.querySelectorAll('article h1[id],article h2[id],article h3[id],article h4[id],article h5[id],article h6[id]').forEach(h => observer.observe(h));
            }
            const initial = <?php echo wp_json_encode($activeDialog); ?>; if (initial) show(initial);
        })();
        </script></body></html><?php
        self::respond(200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'nonce-" . $scriptNonce . "'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'"], ob_get_clean());
    }
}
