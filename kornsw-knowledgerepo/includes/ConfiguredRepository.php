<?php
namespace KornSW\KnowledgeRepo;

/** Read-only configured documents. Navigation never downloads remote content. */
final class ConfiguredRepository extends TreeRepository {
    private $config; private $entries = []; private $loaded = false; private $contents = [];
    public function __construct(array $config) { $this->config = $config; }
    public static function url(string $url): string {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new Failure('Bitte eine HTTP(S)-URL ohne eingebettete Zugangsdaten eingeben.', 400);
        }
        return $url;
    }
    public static function label(string $text): string { return str_replace(['\\', '[', ']', '*', '_', '`', '<', '>'], ['\\\\', '\\[', '\\]', '\\*', '\\_', '\\`', '&lt;', '&gt;'], preg_replace('/[\r\n]+/', ' ', $text)); }
    public static function destination(string $url): string { return str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $url); }
    protected function load(): void {
        if ($this->loaded) { return; }
        $this->nodes = ['/'=>['name'=>($this->config['label'] ?? '') ?: 'Dokumente', 'level'=>1, 'text'=>'']];
        if ($this->config['type'] === 'links' && self::tags($this->config['links'] ?? [])) { $this->loadTagged(); $this->loaded = true; return; }
        $rows = $this->config['type'] === 'links' ? [['mount'=>'/', 'alias'=>$this->config['alias']]] : ($this->config['entries'] ?? []);
        foreach ($rows as $row) {
            $parent = Path::normalize($row['mount'] ?? '/'); $path = '/';
            foreach (array_filter(explode('/', $parent), 'strlen') as $part) {
                $path = Path::join($path, $part);
                if (isset($this->entries[$path])) { throw new Failure('Ein Dokument kann nicht zugleich Mountpunkt sein.', 400); }
                $this->nodes[$path] = ['name'=>$part, 'level'=>1, 'text'=>''];
            }
            $alias = Path::name($row['alias']); $path = Path::join($parent, Path::segment($alias));
            if (isset($this->nodes[$path])) { throw new Failure('Doppelter Dokumentpfad: ' . $path, 400); }
            $this->entries[$path] = $row;
            $this->nodes[$path] = ['name'=>$alias, 'level'=>2, 'text'=>''];
        }
        $this->loaded = true;
    }
    /** Distinct tags in natural order; an untagged link list keeps its single-document shape. */
    public static function tags(array $links): array {
        $tags = [];
        foreach ($links as $link) { foreach ($link['tags'] ?? [] as $tag) { if (!in_array((string) $tag, $tags, true)) { $tags[] = (string) $tag; } } }
        usort($tags, 'strnatcasecmp'); return $tags;
    }
    /** Tagged list: the alias becomes a folder, each tag a page; untagged links get a page named like the list. */
    private function loadTagged(): void {
        $alias = Path::name($this->config['alias']); $folder = Path::join('/', Path::segment($alias));
        $this->nodes[$folder] = ['name'=>$alias, 'level'=>1, 'text'=>''];
        $pages = [];
        foreach ($this->config['links'] ?? [] as $link) { if (!($link['tags'] ?? [])) { $pages[$alias] = [null]; break; } }
        foreach (self::tags($this->config['links'] ?? []) as $tag) { $pages[$tag][] = $tag; }
        foreach ($pages as $name => $tags) {
            $path = Path::join($folder, Path::segment((string) $name));
            $this->entries[$path] = ['tags'=>$tags]; $this->nodes[$path] = ['name'=>(string) $name, 'level'=>2, 'text'=>''];
        }
    }
    /** Validate structure without contacting any source. */
    public function validate(): void { $this->load(); }
    protected function caps(string $area): array {
        $caps = parent::caps($area); $caps['supportsResources'] = false;
        $caps['supportsSubAreas'] = $this->node($area)['level'] !== 2; return $caps;
    }
    private function content(string $area): void {
        $this->node($area);
        if (!isset($this->entries[$area]) || isset($this->contents[$area])) { return; }
        if ($this->config['type'] === 'links') {
            $lines = []; $page = $this->entries[$area]['tags'] ?? null;
            foreach ($this->config['links'] ?? [] as $link) {
                if ($page !== null) {
                    $tags = $link['tags'] ?? [];
                    if (!array_filter($page, static function ($tag) use ($tags) { return $tag === null ? !$tags : in_array($tag, $tags, true); })) { continue; }
                }
                $lines[] ='- [' . self::label($link['title']) . '](' . self::destination($link['url']) . ')';
            }
            $text = implode("\n", $lines);
        } else {
            $row = $this->entries[$area];
            $result = self::download($row['url']); $body = $result['body']; $format = $row['format'] ?? 'auto';
            if ($format === 'auto') {
                $extension = strtolower(pathinfo((string) parse_url($result['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
                $format = in_array($extension, ['md', 'markdown'], true) || strpos($result['type'], 'markdown') !== false ? 'md' : (in_array($extension, ['html','htm'], true) || strpos($result['type'], 'html') !== false ? 'html' : 'text');
            }
            if ($format === 'html') { $text = self::htmlToMarkdown($body, $result['url']); }
            elseif ($format === 'text') { $fence = str_repeat('`', max(3, self::longestFence($body) + 1)); $text = $fence . "\n" . $body . "\n" . $fence; }
            else {
                // Resolve common inline Markdown destinations relative to the final response URL.
                $text = preg_replace_callback('~(!?\[[^\]\r\n]*\]\()([^\s)]+)(\))~', static function ($m) use ($result) {
                    return $m[1] . self::destination(self::absolute($m[2], $result['url'])) . $m[3];
                }, $body);
            }
        }
        $this->nodes[$area]['text'] = $text; $this->contents[$area] = true;
    }
    private static function longestFence(string $text): int { preg_match_all('/`+/', $text, $matches); return $matches[0] ? max(array_map('strlen', $matches[0])) : 0; }
    public static function absolute(string $url, string $base): string {
        $url = trim($url);
        if ($url === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || $url[0] === '#') { return $url; }
        return \WP_Http::make_absolute_url($url, $base);
    }
    private static function download(string $url): array {
        $limit = 4194304;
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $url = self::url($url);
            $response = wp_safe_remote_get($url, ['timeout'=>20, 'redirection'=>0, 'limit_response_size'=>$limit + 1,
                'user-agent'=>'KornSW-WordPress-KnowledgeRepo/0.1.8', 'headers'=>['Accept'=>'text/markdown, text/plain, text/html']]);
            if (is_wp_error($response)) { throw new Failure('URL-Quelle nicht erreichbar.', 503); }
            $status = (int) wp_remote_retrieve_response_code($response);
            if (in_array($status, [301,302,303,307,308], true)) {
                $location = (string) wp_remote_retrieve_header($response, 'location');
                if ($location === '') { throw new Failure('Ungültige Weiterleitung der URL-Quelle.', 502); }
                $url = self::absolute($location, $url); continue;
            }
            if ($status < 200 || $status >= 300) { throw new Failure('URL-Quelle meldet HTTP ' . $status . '.', 503); }
            $body = wp_remote_retrieve_body($response);
            if (strlen($body) > $limit) { throw new Failure('URL-Dokument überschreitet 4 MiB.', 413); }
            $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
            if (preg_match('/charset\s*=\s*["\x27]?([^;"\x27\s]+)/i', $type, $m)) {
                try { $body = mb_convert_encoding($body, 'UTF-8', $m[1]); } catch (\ValueError $e) { throw new Failure('Unbekannte Zeichenkodierung.', 502); }
            }
            if (!mb_check_encoding($body, 'UTF-8') || strpos($body, "\0") !== false) { throw new Failure('URL-Quelle liefert keinen UTF-8-Text.', 502); }
            return ['body'=>$body, 'type'=>$type, 'url'=>$url];
        }
        throw new Failure('Zu viele Weiterleitungen der URL-Quelle.', 502);
    }
    public static function htmlToMarkdown(string $html, string $base): string {
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $visit = function ($node) use (&$visit, $base): string {
            if ($node instanceof \DOMText) { return self::label($node->nodeValue); }
            if (!($node instanceof \DOMElement)) { return ''; }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script','style','iframe','object','embed','form','head','svg','template'], true)) { return ''; }
            if ($tag === 'pre') { $fence = str_repeat('`', max(3, self::longestFence($node->textContent) + 1)); return "\n\n" . $fence . "\n" . $node->textContent . "\n" . $fence . "\n\n"; }
            $text = ''; foreach ($node->childNodes as $child) { $text .= $visit($child); }
            if ($tag === 'a' || $tag === 'img') {
                $url = self::absolute($node->getAttribute($tag === 'a' ? 'href' : 'src'), $base);
                if (!preg_match('~^(https?://|mailto:|#)~i', $url)) { return $tag === 'a' ? $text : ''; }
                return ($tag === 'img' ? '![' . self::label($node->getAttribute('alt')) : '[' . $text) . '](' . self::destination($url) . ')';
            }
            if (preg_match('/^h([1-6])$/', $tag, $m)) { return "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . trim($text) . "\n\n"; }
            if ($tag === 'li') { return "\n- " . trim($text); }
            if ($tag === 'br') { return "  \n"; }
            if (in_array($tag, ['b','strong'], true)) { return '**' . $text . '**'; }
            if (in_array($tag, ['i','em'], true)) { return '*' . $text . '*'; }
            if (in_array($tag, ['p','div','section','article','ul','ol','table','tr','blockquote'], true)) { return "\n\n" . $text . "\n\n"; }
            if (in_array($tag, ['td','th'], true)) { return $text . ' | '; }
            return $text;
        };
        return trim(preg_replace('/\n{3,}/', "\n\n", $visit($dom->documentElement)));
    }
    protected function aggregate(string $area): string { $this->content($area); return parent::aggregate($area); }
    public function call(string $method, array $args = []): array {
        $a = Contract::arguments($method, $args);
        if (in_array($method, ['GetDirectContent','HasDirectContent'], true)) { $this->content(Path::normalize($a['area'])); }
        if ($method === 'GetAreasByKeyword') {
            $out = []; foreach ($this->walk($a['startArea']) as $path) { $this->content($path); if (mb_stripos($this->nodes[$path]['name'] . "\n" . $this->nodes[$path]['text'], $a['keyword']) !== false) { $out[] = $path; } }
            return ['return'=>$out];
        }
        return parent::call($method, $a);
    }
    /** Decorate only links already present in the rendered, authorized repository document. */
    public static function decorate(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $wrapper, string $area): void {
        foreach (Auth::settings()['sources'] ?? [] as $source) {
            if (($source['type'] ?? '') === 'urls' && ($source['show_source'] ?? true)) {
                foreach ($source['entries'] ?? [] as $entry) {
                    $path = Path::join(Path::join($source['mount'], $entry['mount'] ?? '/'), Path::segment($entry['alias']));
                    if ($path !== $area) { continue; }
                    $notice = $dom->createElement('p'); $notice->setAttribute('class', 'kr-source-credit');
                    $notice->appendChild($dom->createTextNode('DISCLAIMER & THANKS: This content has been embedded from its original source, '));
                    $link = $dom->createElement('a'); $link->setAttribute('href', $entry['url']); $link->setAttribute('target', '_blank'); $link->setAttribute('rel', 'noopener noreferrer');
                    $link->appendChild($dom->createTextNode($entry['url'])); $notice->appendChild($link);
                    $notice->appendChild($dom->createTextNode(', to help its wonderful authors share their knowledge. Please treat it as external content, which we do not continuously monitor. To the extent permitted by applicable law, we disclaim liability for the accuracy, completeness and currency of this third-party content and for any consequences of relying on it. This does not exclude any liability that cannot lawfully be excluded.'));
                    $wrapper->insertBefore($notice, $wrapper->firstChild);
                }
            }
            if (($source['type'] ?? '') !== 'links' || !Path::contains(Path::join($source['mount'], Path::segment($source['alias'])), $area)) { continue; }
            foreach ($xpath->query('.//ul', $wrapper) as $list) {
                $list->setAttribute('class', 'kr-link-directory ' . (($source['mode'] ?? 'list') === 'tiles' ? 'kr-link-tiles' : 'kr-link-list'));
                foreach ($xpath->query('./li/a', $list) as $anchor) {
                    foreach ($source['links'] as $link) {
                        if ($anchor->getAttribute('href') !== self::destination($link['url'])) { continue; }
                        $parts = parse_url($link['url']);
                        $icon = ($link['icon'] ?? '') ?: $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/favicon.ico';
                        $img = $dom->createElement('img'); $img->setAttribute('src', $icon); $img->setAttribute('alt', ''); $img->setAttribute('loading', 'lazy'); $img->setAttribute('referrerpolicy', 'no-referrer'); $img->setAttribute('width','32'); $img->setAttribute('height','32');
                        $anchor->insertBefore($img, $anchor->firstChild); break;
                    }
                }
            }
        }
    }
}
