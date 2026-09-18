<?php
namespace KornSW\KnowledgeRepo;

/** Raw WebDAV adapter over Repository. No provider-specific path or file assumptions. */
final class Joplin {
    private $repo; private $store; private $write; private $base; private $projected = []; private $activeResources = [];
    public function __construct(Repository $repo, SyncStore $store, bool $write, string $base) {
        $this->repo = $repo; $this->store = $store; $this->write = $write; $this->base = rtrim($base, '/');
    }
    private function invoke(string $method, array $a = []): array { return $this->repo->call($method, $a); }
    private function mutate(string $method, array $a): array {
        if (!$this->write) { throw new Failure('Wissen ist für diesen Zugang schreibgeschützt.', 403); }
        $r = $this->invoke($method, $a);
        if (empty($r['return'])) { throw new Failure('Die Quelle unterstützt diese Änderung nicht oder lehnt sie ab.', 409); }
        foreach ($r['resourceIdChanges'] ?? [] as $change) {
            foreach ($this->store->state['resources'] as &$resource) {
                if ($resource['rid'] === $change['PreviousResourceId']) { $resource['rid'] = $change['CurrentResourceId']; }
            }
            unset($resource);
        }
        return $r;
    }
    public static function parse(string $raw): array {
        $raw = str_replace("\r\n", "\n", $raw); $lines = explode("\n", rtrim($raw, "\n")); $props = [];
        while ($lines) {
            $line = end($lines);
            if (!preg_match('/^([a-z_]+):[ \t]?(.*)$/', $line, $m)) { break; }
            array_pop($lines); $props[$m[1]] = $m[2];
        }
        if (!isset($props['type_'], $props['id']) || !preg_match('/^[a-f0-9]{32}$/', $props['id'])) { throw new Failure('Ungültiges Joplin-Element.', 400); }
        $text = trim(implode("\n", $lines), "\n"); $blocks = explode("\n\n", $text, 2);
        return ['id' => $props['id'], 'type' => (int) $props['type_'], 'title' => $blocks[0], 'body' => $blocks[1] ?? '', 'parent' => $props['parent_id'] ?? '', 'props' => $props];
    }
    private static function stamp(int $time): string { return gmdate('Y-m-d\TH:i:s', $time) . '.000Z'; }
    private function serialize(array $item, array $record): string {
        $props = ['id' => $item['id'], 'parent_id' => $item['parent'], 'created_time' => self::stamp($record['created']), 'updated_time' => self::stamp($record['modified'])];
        if ($item['type'] === 1) {
            $props += ['is_conflict' => 0, 'latitude' => '0.00000000', 'longitude' => '0.00000000', 'altitude' => '0.0000', 'author' => '', 'source_url' => '',
                'is_todo' => 0, 'todo_due' => 0, 'todo_completed' => 0, 'source' => 'knowledge-repository', 'source_application' => 'knowledge-repository', 'application_data' => '', 'order' => 0];
        }
        if ($item['type'] === 4) { $props += $item['metadata']; }
        $props += ['user_created_time' => self::stamp($record['created']), 'user_updated_time' => self::stamp($record['modified']), 'encryption_cipher_text' => '', 'encryption_applied' => 0];
        if ($item['type'] === 1) { $props['markup_language'] = 1; }
        $props += ['is_shared' => 0, 'share_id' => '', 'master_key_id' => '', 'user_data' => '', 'deleted_time' => 0, 'type_' => $item['type']];
        $rows = [];
        foreach ($props as $key => $value) { $rows[] = $key . ': ' . str_replace(["\r", "\n"], '', (string) $value); }
        return trim(str_replace(["\r", "\n"], ' ', $item['title'])) . "\n\n" . ($item['body'] === '' ? '' : rtrim($item['body'], "\r\n") . "\n\n") . implode("\n", $rows);
    }
    private function file(string $path, string $bytes): void {
        $old = $this->store->state['files'][$path] ?? null;
        $encoded = base64_encode($bytes);
        if ($old && $old['data'] === $encoded) { return; }
        $this->store->state['files'][$path] = ['data' => $encoded, 'modified' => time()];
    }
    private function resourceToJoplin(string $area, string $body): string {
        $infos = [];
        foreach ($this->invoke('GetResources', ['area' => $area])['return'] as $info) { $infos[$info['ResourceId']] = $info; }
        return preg_replace_callback('/knowledge-resource:([A-Za-z0-9._~-]+)/', function ($m) use ($area, $infos) {
            $rid = $m[1]; $id = null;
            foreach ($this->store->state['resources'] as $candidate => $resource) { if ($resource['rid'] === $rid) { $id = $candidate; break; } }
            if ($id === null) {
                $id = substr(hash('sha256', 'resource:' . $rid), 0, 32);
                $this->store->state['resources'][$id] = ['rid' => $rid, 'area' => $area, 'created' => time(), 'modified' => time(), 'hash' => '', 'suppressed' => false];
            }
            $this->activeResources[$id] = true;
            $r =& $this->store->state['resources'][$id];
            if ($r['suppressed']) { return ':/' . $id; }
            if (!isset($infos[$rid])) { throw new Failure('Ressourcenmetadaten fehlen.', 503); }
            $info = $infos[$rid]; $bytes = base64_decode($this->invoke('GetResourceContent', ['resourceId' => $rid])['return'], true);
            if ($bytes === false) { throw new Failure('Ressource ungültig.', 502); }
            $hash = hash('sha256', $bytes . json_encode($info));
            if ($hash !== $r['hash']) { $r['hash'] = $hash; $r['modified'] = time(); }
            $item = ['id' => $id, 'type' => 4, 'title' => $info['FileName'], 'body' => '', 'parent' => '',
                'metadata' => ['mime' => $info['ContentType'], 'filename' => $info['FileName'], 'file_extension' => pathinfo($info['FileName'], PATHINFO_EXTENSION), 'size' => strlen($bytes), 'blob_updated_time' => $r['modified'] * 1000, 'encryption_blob_encrypted' => 0, 'ocr_text' => '', 'ocr_details' => '', 'ocr_status' => 0, 'ocr_error' => '', 'ocr_driver_id' => 0, 'is_locked' => 0]];
            $this->file('/.resource/' . $id, $bytes); $this->file('/' . $id . '.md', $this->serialize($item, $r));
            return ':/' . $id;
        }, $body);
    }
    public function project(): void {
        $areas = $this->invoke('GetAreas', ['recurse' => true])['return']; $levels = ['/'=>0]; $ids = []; $active = [];
        $this->projected = []; $this->activeResources = [];
        foreach ($areas as $area) {
            $c = $this->invoke('GetAreaCapabilities', ['area' => $area]); $level = $c['contentLevel'];
            if (is_string($level)) { $level = array_search($level, ['BeyondContent', 'ContentAggregation', 'ContentContainer'], true); }
            $levels[$area] = (int) $level;
            if ($level === 2 && ($levels[Path::parent($area)] ?? 0) === 2) { continue; }
            $type = $level === 2 ? 1 : 2; $id = null;
            foreach ($this->store->state['items'] as $key => $record) { if ($record['area'] === $area && $record['type'] === $type) { $id = $key; break; } }
            if ($id === null) {
                $id = substr(hash('sha256', $type . ':' . $area), 0, 32);
                $this->store->state['items'][$id] = ['area' => $area, 'type' => $type, 'created' => time(), 'modified' => time(), 'hash' => '', 'suppressed' => false];
            }
            $active[$id] = true; $ids[$area] = $id;
            if (isset($this->store->state['pending'][$id])) {
                foreach ($this->store->state['resources'] as $rid => $resource) { if ($resource['area'] === $area) { $this->activeResources[$rid] = true; } }
                continue;
            }
            $record =& $this->store->state['items'][$id];
            if ($record['suppressed']) { unset($this->store->state['files']['/' . $id . '.md']); continue; }
            $item = ['id' => $id, 'type' => $type, 'title' => $this->invoke('GetAreaName', ['area' => $area])['return'], 'parent' => $ids[Path::parent($area)] ?? '', 'body' => ''];
            if ($type === 1) { $item['body'] = $this->resourceToJoplin($area, $this->invoke('GetAggregatedContent', ['area' => $area])['return']); }
            $hash = hash('sha256', json_encode($item));
            if ($hash !== $record['hash']) { $record['hash'] = $hash; $record['modified'] = time(); }
            $this->projected[$id] = $item;
            $this->file('/' . $id . '.md', $this->serialize($item, $record)); unset($record);
        }
        foreach ($this->store->state['items'] as $id => $record) {
            if (!isset($active[$id])) { unset($this->store->state['items'][$id], $this->store->state['files']['/' . $id . '.md']); }
        }
        foreach ($this->store->state['resources'] as $id => $resource) {
            if (!isset($this->activeResources[$id])) {
                // Never serve stale projected binaries after a source/category was removed.
                unset($this->store->state['files']['/' . $id . '.md'], $this->store->state['files']['/.resource/' . $id]);
                if (!$resource['suppressed']) { unset($this->store->state['resources'][$id]); }
            }
        }
        if (!isset($this->store->state['files']['/info.json'])) { $this->file('/info.json', '{"version":3}'); }
    }
    private function findChild(string $parent, string $title, int $type): ?string {
        $found = [];
        foreach ($this->invoke('GetAreas', ['recurse' => false, 'startArea' => $parent])['return'] as $p) {
            $level = $this->invoke('GetAreaCapabilities', ['area' => $p])['contentLevel'];
            $isNote = in_array($level, [2, 'ContentContainer'], true);
            if (($type === 1) === $isNote && $this->invoke('GetAreaName', ['area' => $p])['return'] === $title) { $found[] = $p; }
        }
        if (count($found) > 1) { throw new Failure('Mehrdeutiger Zielbereich.'); }
        return $found[0] ?? null;
    }
    private function remap(string $old, string $new): void {
        foreach ($this->store->state['items'] as &$r) { if (Path::contains($old, $r['area'])) { $r['area'] = $new . substr($r['area'], strlen($old)); } }
        unset($r);
        foreach ($this->store->state['resources'] as &$r) { if (Path::contains($old, $r['area'])) { $r['area'] = $new . substr($r['area'], strlen($old)); } }
        unset($r);
    }
    private function translateIncoming(string $body, string $area): ?string {
        preg_match_all('~:/([a-f0-9]{32})~', $body, $m);
        foreach (array_unique($m[1]) as $id) {
            if (isset($this->store->state['items'][$id])) { continue; }
            $resource = $this->store->state['resources'][$id] ?? null;
            if ($resource) { $body = str_replace(':/' . $id, 'knowledge-resource:' . $resource['rid'], $body); continue; }
            $raw = $this->store->state['files']['/' . $id . '.md']['data'] ?? null;
            if ($raw === null) { return null; }
            $item = self::parse(base64_decode($raw));
            if ($item['type'] !== 4) { continue; }
            $blob = $this->store->state['files']['/.resource/' . $id]['data'] ?? null;
            if ($blob === null) { return null; }
            $r = $this->mutate('TryAddResource', ['area' => $area, 'preferredFileName' => $item['props']['filename'] ?? '', 'contentType' => $item['props']['mime'] ?? 'application/octet-stream', 'content' => $blob]);
            $this->store->state['resources'][$id] = ['rid' => $r['resourceId'], 'area' => $area, 'created' => time(), 'modified' => time(), 'hash' => '', 'suppressed' => false];
            $this->store->save();
            $body = str_replace(':/' . $id, 'knowledge-resource:' . $r['resourceId'], $body);
        }
        return $body;
    }
    /** Return false only for a temporarily missing parent/resource, not a rejected write. */
    private function apply(array $item): bool {
        $id = $item['id'];
        if (!in_array($item['type'], [1, 2], true)) { return true; }
        if (!empty($item['props']['encryption_applied'])) { throw new Failure('Verschlüsselte Joplin-Notizen können nicht als Wissen verarbeitet werden.', 409); }
        if (isset($this->projected[$id])) {
            $current = $this->projected[$id];
            if ($current['title'] === $item['title'] && $current['parent'] === $item['parent'] && trim($current['body']) === trim($item['body'])) { return true; }
        }
        if (!$this->write) { throw new Failure('Wissen ist schreibgeschützt.', 403); }
        $parent = '/';
        if ($item['parent'] !== '') {
            $parentRecord = $this->store->state['items'][$item['parent']] ?? null;
            if ($parentRecord === null) { return false; }
            if ($parentRecord['type'] !== 2 || $parentRecord['suppressed']) { throw new Failure('Ungültiges Zielnotizbuch.'); }
            $parent = $parentRecord['area'];
        }
        $record = $this->store->state['items'][$id] ?? null;
        if ($record === null) {
            $area = $this->findChild($parent, $item['title'], $item['type']);
            if ($area !== null) {
                // Rebind only a suppressed identity; never silently overwrite a name collision.
                $oldId = null;
                foreach ($this->store->state['items'] as $key => $r) { if ($r['area'] === $area && $r['suppressed']) { $oldId = $key; } }
                if ($oldId === null) { throw new Failure('Ein Bereich mit diesem Namen existiert bereits.'); }
                unset($this->store->state['items'][$oldId]);
            } else {
                $this->mutate('TryAddSubArea', ['area' => $parent, 'name' => $item['title'], 'kind' => $item['type'] === 1 ? 1 : 0]);
                $area = $this->findChild($parent, $item['title'], $item['type']);
                if ($area === null) { throw new Failure('Neu angelegter Bereich nicht auffindbar.', 503); }
            }
            $this->store->state['items'][$id] = ['area' => $area, 'type' => $item['type'], 'created' => time(), 'modified' => time(), 'hash' => '', 'suppressed' => false];
            $this->store->save();
        } else {
            if ($record['type'] !== $item['type']) { throw new Failure('Joplin-Elementtyp darf nicht geändert werden.'); }
            $area = $record['area'];
            if (Path::parent($area) !== $parent) {
                $title = $this->invoke('GetAreaName', ['area' => $area])['return'];
                $this->mutate('TryMoveContent', ['contentAreaToMove' => $area, 'newParentArea' => $parent]);
                $new = $this->findChild($parent, $title, $item['type']);
                if ($new === null) { throw new Failure('Verschobener Bereich nicht auffindbar.', 503); }
                $this->remap($area, $new); $area = $new; $this->store->save();
            }
            if ($this->invoke('GetAreaName', ['area' => $area])['return'] !== $item['title']) {
                $this->mutate('TryRename', ['area' => $area, 'newName' => $item['title']]);
                $new = $this->findChild($parent, $item['title'], $item['type']);
                if ($new === null) { throw new Failure('Umbenannter Bereich nicht auffindbar.', 503); }
                $this->remap($area, $new); $area = $new; $this->store->save();
            }
        }
        if ($item['type'] === 1) {
            $body = $this->translateIncoming($item['body'], $area);
            if ($body === null) { return false; }
            if (trim($this->invoke('GetAggregatedContent', ['area' => $area])['return']) !== trim($body)) { $this->mutate('TryReplace', ['area' => $area, 'newContent' => $body]); }
        }
        $this->store->state['items'][$id]['suppressed'] = false;
        return true;
    }
    private function retryPending(): void {
        $limit = count($this->store->state['pending']) + 1;
        while ($limit-- > 0) {
            $changed = false;
            foreach ($this->store->state['pending'] as $id => $item) {
                if ($this->apply($item)) { unset($this->store->state['pending'][$id]); $changed = true; }
            }
            if (!$changed) { break; }
        }
    }
    private function href(string $path): string {
        $parts = array_map('rawurlencode', explode('/', ltrim($path, '/')));
        return $this->base . '/' . implode('/', $parts);
    }
    private function xml(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
    private function isCollection(string $path): bool { return in_array($path, $this->store->state['collections'], true); }
    private function exists(string $path): bool { return $this->isCollection($path) || isset($this->store->state['files'][$path]); }
    public function handle(string $method, string $path, string $body, array $headers): array {
        $path = Path::normalize($path);
        $allow = ['DAV' => '1', 'Allow' => 'OPTIONS, PROPFIND, GET, HEAD, PUT, DELETE, MKCOL, MOVE'];
        if ($method === 'OPTIONS') { return [200, $allow, '']; }
        $this->project();
        $exists = $this->exists($path);
        $etag = isset($this->store->state['files'][$path]) ? '"' . hash('sha256', base64_decode($this->store->state['files'][$path]['data'])) . '"' : null;
        if (isset($headers['if-match']) && $headers['if-match'] !== '*' && $headers['if-match'] !== $etag) { return [412, [], '']; }
        if (($headers['if-match'] ?? '') === '*' && !$exists) { return [412, [], '']; }
        if (($headers['if-none-match'] ?? '') === '*' && $exists && !in_array($method, ['GET', 'HEAD'], true)) { return [412, [], '']; }
        if ($method === 'PROPFIND') {
            if (!$exists) { return [404, [], '']; }
            $depth = $headers['depth'] ?? '1';
            if (!in_array($depth, ['0', '1'], true)) { return [403, [], 'Depth 0 oder 1 erforderlich.']; }
            $paths = [$path];
            if ($depth === '1' && $this->isCollection($path)) {
                foreach (array_merge($this->store->state['collections'], array_keys($this->store->state['files'])) as $child) { if ($child !== $path && Path::parent($child) === $path) { $paths[] = $child; } }
            }
            $xml = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">';
            foreach (array_unique($paths) as $p) {
                $collection = $this->isCollection($p); $file = $this->store->state['files'][$p] ?? null; $bytes = $file ? base64_decode($file['data']) : '';
                $xml .= '<d:response><d:href>' . $this->xml($this->href($p) . ($collection && $p !== '/' ? '/' : '')) . '</d:href><d:propstat><d:prop><d:displayname>' . $this->xml(basename($p)) . '</d:displayname><d:resourcetype>' . ($collection ? '<d:collection/>' : '') . '</d:resourcetype><d:getcontentlength>' . strlen($bytes) . '</d:getcontentlength><d:getlastmodified>' . gmdate('D, d M Y H:i:s', $file['modified'] ?? $this->store->state['created']) . ' GMT</d:getlastmodified><d:getetag>' . $this->xml('"' . hash('sha256', $bytes) . '"') . '</d:getetag></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
            }
            $this->store->save(); return [207, ['Content-Type' => 'application/xml; charset=utf-8'], $xml . '</d:multistatus>'];
        }
        if (in_array($method, ['GET', 'HEAD'], true)) {
            if (!$exists) { return [404, [], '']; }
            if ($this->isCollection($path)) { return [200, [], '']; }
            $bytes = base64_decode($this->store->state['files'][$path]['data']);
            if (($headers['if-none-match'] ?? '') === $etag) { return [304, ['ETag' => $etag], '']; }
            $this->store->save();
            return [200, ['Content-Type' => strpos($path, '/.resource/') === 0 ? 'application/octet-stream' : 'text/plain; charset=utf-8', 'Content-Length' => strlen($bytes), 'ETag' => $etag], $method === 'HEAD' ? '' : $bytes];
        }
        if ($method === 'MKCOL') {
            if ($exists) { return [405, [], '']; }
            if ($body !== '') { return [415, [], '']; }
            if (!$this->isCollection(Path::parent($path))) { return [409, [], '']; }
            $this->store->state['collections'][] = $path; $this->store->save(); return [201, [], ''];
        }
        if ($method === 'PUT') {
            if (!$this->isCollection(Path::parent($path)) || $this->isCollection($path)) { return [409, [], '']; }
            if (strlen($body) > 16777216) { return [413, [], '']; }
            if ($path === '/info.json') {
                $info = json_decode($body, true);
                if (!is_array($info) || ($info['version'] ?? 0) !== 3 || !empty($info['e2ee']['value'])) { throw new Failure('Nur Joplin Sync v3 ohne E2EE wird unterstützt.'); }
            }
            if (preg_match('~^/([a-f0-9]{32})\.md$~', $path, $m)) {
                $item = self::parse($body);
                if ($item['id'] !== $m[1]) { throw new Failure('Joplin-ID passt nicht zum Dateinamen.', 400); }
                if ($item['type'] === 4 && isset($this->store->state['resources'][$item['id']])) {
                    $r = $this->store->state['resources'][$item['id']];
                    $blob = $this->store->state['files']['/.resource/' . $item['id']]['data'] ?? null;
                    if ($blob !== null && $this->write) { $this->mutate('TryReplaceResource', ['resourceId' => $r['rid'], 'contentType' => $item['props']['mime'] ?? '', 'content' => $blob]); }
                }
                if (!$this->apply($item)) { $this->store->state['pending'][$item['id']] = $item; }
                else { unset($this->store->state['pending'][$item['id']]); }
            } elseif (preg_match('~^/\.resource/([a-f0-9]{32})$~', $path, $m) && isset($this->store->state['resources'][$m[1]])) {
                $old = $this->store->state['files'][$path]['data'] ?? '';
                if ($old !== base64_encode($body)) {
                    $this->mutate('TryReplaceResource', ['resourceId' => $this->store->state['resources'][$m[1]]['rid'], 'contentType' => '', 'content' => base64_encode($body)]);
                }
            }
            $this->file($path, $body); $this->retryPending(); $this->project(); $this->store->save(); return [$exists ? 204 : 201, [], ''];
        }
        if ($method === 'DELETE') {
            if (!$exists) { return [404, [], '']; }
            if ($path === '/') { return [403, [], '']; }
            $remove = [$path];
            foreach (array_keys($this->store->state['files']) as $p) { if (Path::contains($path, $p)) { $remove[] = $p; } }
            foreach ($remove as $p) {
                if (preg_match('~^/(?:\.resource/)?([a-f0-9]{32})(?:\.md)?$~', $p, $m)) {
                    if (isset($this->store->state['items'][$m[1]])) { $this->store->state['items'][$m[1]]['suppressed'] = true; }
                    if (isset($this->store->state['resources'][$m[1]])) { $this->store->state['resources'][$m[1]]['suppressed'] = true; }
                    unset($this->store->state['pending'][$m[1]]);
                }
                unset($this->store->state['files'][$p]);
            }
            $this->store->state['collections'] = array_values(array_filter($this->store->state['collections'], static function ($p) use ($path) { return !Path::contains($path, $p); }));
            $this->store->save(); return [204, [], ''];
        }
        if ($method === 'MOVE') {
            $dest = parse_url($headers['destination'] ?? '');
            $baseHost = parse_url(home_url(), PHP_URL_HOST);
            if (!$dest || (isset($dest['host']) && strcasecmp($dest['host'], $baseHost) !== 0) || strpos($dest['path'] ?? '', $this->base . '/') !== 0) { return [403, [], '']; }
            $to = Path::normalize(rawurldecode(substr($dest['path'], strlen($this->base))));
            // Projected IDs are stable. Protocol MOVE is only meaningful for raw state files.
            if (preg_match('~^/(?:\.resource/)?[a-f0-9]{32}(?:\.md)?$~', $path) || preg_match('~^/(?:\.resource/)?[a-f0-9]{32}(?:\.md)?$~', $to)) { return [409, [], '']; }
            if (!isset($this->store->state['files'][$path])) { return [404, [], '']; }
            if (!$this->isCollection(Path::parent($to))) { return [409, [], '']; }
            $overwritten = $this->exists($to);
            if ($overwritten && ($headers['overwrite'] ?? 'T') === 'F') { return [412, [], '']; }
            $this->store->state['files'][$to] = $this->store->state['files'][$path]; unset($this->store->state['files'][$path]);
            $this->store->save(); return [$overwritten ? 204 : 201, [], ''];
        }
        return [405, $allow, ''];
    }
}
