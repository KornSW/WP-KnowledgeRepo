<?php
namespace KornSW\KnowledgeRepo;

/** GitHub object API provider. No checkout, clone, shell or persisted source files. */
final class GitHubRepository extends TreeRepository {
    private $config; private $api; private $root; private $branch; private $head; private $tree;
    private $files = []; private $bytes = []; private $documents = []; private $loaded = false;
    public function __construct(array $config) {
        $this->config = $config;
        if (!preg_match('~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~', $config['url'], $m)) { throw new Failure('GitHub-URL muss https://github.com/Owner/Repository sein.', 400); }
        $this->api = 'https://api.github.com/repos/' . $m[1] . '/' . $m[2];
        $this->root = trim(Path::normalize($config['root'] ?? '/'), '/');
    }
    private function api(string $path, string $method = 'GET', ?array $body = null): array {
        return Http::json($this->api . $path, $method, $body, $this->config['token'] ?? '', true);
    }
    protected function load(): void {
        if ($this->loaded) { return; }
        $state = FileCache::remember('github:' . wp_json_encode($this->config), function () {
            try { $this->loadFresh(); } catch (\Throwable $e) { $this->loaded = false; throw $e; }
            return ['nodes' => $this->nodes, 'files' => $this->files, 'documents' => $this->documents,
                'bytes' => array_map('base64_encode', $this->bytes), 'branch' => $this->branch, 'head' => $this->head, 'tree' => $this->tree];
        });
        foreach (['nodes', 'files', 'documents', 'branch', 'head', 'tree'] as $key) { $this->$key = $state[$key]; }
        $this->bytes = array_map('base64_decode', $state['bytes']); $this->loaded = true;
    }
    private function loadFresh(): void {
        $this->branch = $this->config['branch'] ?? '';
        if ($this->branch === '') { $this->branch = $this->api('')['default_branch']; }
        $commit = $this->api('/commits/' . rawurlencode($this->branch));
        $this->head = $commit['sha']; $this->tree = $commit['commit']['tree']['sha'];
        $knowledgeTree = $this->tree;
        if ($this->root !== '') {
            foreach (explode('/', $this->root) as $segment) {
                $level = $this->api('/git/trees/' . $knowledgeTree); $next = null;
                foreach ($level['tree'] as $entry) { if ($entry['type'] === 'tree' && $entry['path'] === $segment) { $next = $entry['sha']; break; } }
                if ($next === null) { throw new Failure('GitHub-Einstiegsverzeichnis nicht gefunden.', 404); }
                $knowledgeTree = $next;
            }
        }
        $listing = $this->api('/git/trees/' . $knowledgeTree . '?recursive=1');
        if (!empty($listing['truncated'])) { throw new Failure('GitHub-Baum überschreitet das API-Limit. Bitte ein tieferes Einstiegsverzeichnis wählen.', 503); }
        $this->files = [];
        foreach ($listing['tree'] as $entry) {
            if ($entry['type'] !== 'blob' || $entry['mode'] === '120000') { continue; }
            $this->files[$entry['path']] = $entry;
        }
        $this->loaded = true; $this->rebuild();
    }
    private function read(string $path): string {
        if (array_key_exists($path, $this->bytes)) { return $this->bytes[$path]; }
        if (!isset($this->files[$path])) { throw new Failure('Ressource nicht gefunden.', 404); }
        if (($this->files[$path]['size'] ?? 0) > 16777216) { throw new Failure('Ressource überschreitet 16 MiB.', 413); }
        $blob = $this->api('/git/blobs/' . $this->files[$path]['sha']);
        $bytes = base64_decode(preg_replace('/\s+/', '', $blob['content']), true);
        if ($bytes === false) { throw new Failure('Ungültiger GitHub-Blob.', 502); }
        $this->bytes[$path] = $bytes; return $bytes;
    }
    private function rid(string $path): string { return '1.' . Path::b64($path); }
    private function resourcePath(string $id): string {
        if (strpos($id, '1.') !== 0) { throw new Failure('Unbekannte Ressourcen-ID.', 404); }
        $p = Path::unb64(substr($id, 2));
        if (ltrim(Path::normalize($p), '/') !== $p || !isset($this->files[$p])) { throw new Failure('Ressource nicht gefunden.', 404); }
        return $p;
    }
    private function relative(string $from, string $to): string {
        $a = $from === '.' ? [] : explode('/', $from); $b = explode('/', $to);
        while ($a && $b && $a[0] === $b[0]) { array_shift($a); array_shift($b); }
        return implode('/', array_merge(array_fill(0, count($a), '..'), array_map('rawurlencode', $b)));
    }
    private function resolveLink(string $file, string $link): ?string {
        if ($link === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $link)) { return null; }
        $link = rawurldecode(explode('#', $link)[0]);
        $parts = dirname($file) === '.' ? [] : explode('/', dirname($file));
        foreach (explode('/', $link) as $p) {
            if ($p === '..') { if (!$parts) { return null; } array_pop($parts); }
            elseif ($p !== '.' && $p !== '') { $parts[] = $p; }
        }
        $p = implode('/', $parts); return isset($this->files[$p]) ? $p : null;
    }
    private function canonical(string $text, string $file): string {
        return preg_replace_callback('~(!?\[[^\]\n]*\]\()([^\s)]+)([^)]*\))~', function ($m) use ($file) {
            $path = $this->resolveLink($file, $m[2]);
            if ($path === null || (substr($m[1], 0, 1) !== '!' && preg_match('/\.md$/i', $path))) { return $m[0]; }
            return $m[1] . 'knowledge-resource:' . $this->rid($path) . $m[3];
        }, $text);
    }
    private function physical(string $text, string $file): string {
        return preg_replace_callback('/knowledge-resource:([A-Za-z0-9._~-]+)/', function ($m) use ($file) {
            return $this->relative(dirname($file), $this->resourcePath($m[1]));
        }, $text);
    }
    private function addDirectory(string $dir): string {
        if ($dir === '.' || $dir === '') { return '/'; }
        $parent = $this->addDirectory(dirname($dir));
        $p = Path::join($parent, '[' . Path::segment(basename($dir)) . ']');
        if (!isset($this->nodes[$p])) { $this->nodes[$p] = ['name' => basename($dir), 'level' => 0, 'text' => '', 'file' => $dir, 'indices' => null]; }
        return $p;
    }
    private function addHeadings(string $parent, array $tree, string $file, array $indices): void {
        $used = [];
        foreach ($tree['children'] as $i => $child) {
            $segment = Path::segment($child['name']); $base = $segment; $number = 2;
            while (isset($used[$segment])) { $segment = $base . '~' . $number++; }
            $used[$segment] = true; $path = Path::join($parent, $segment); $chain = array_merge($indices, [$i]);
            $this->nodes[$path] = ['name' => $child['name'], 'level' => 2, 'text' => $child['text'], 'file' => $file, 'indices' => $chain];
            $this->addHeadings($path, $child, $file, $chain);
        }
    }
    private function rebuild(): void {
        $this->nodes = ['/'=> ['name' => $this->config['label'] ?? 'GitHub', 'level' => 0, 'text' => '', 'file' => '', 'indices' => null]];
        $this->documents = [];
        $paths = array_keys($this->files); sort($paths, SORT_STRING);
        foreach ($paths as $file) {
            $parent = $this->addDirectory(dirname($file));
            if (!preg_match('/\.md$/i', $file) || preg_match('/\.DELETED(?:\.\d+)?\.md$|\.Res\d+\./i', $file)) { continue; }
            $name = substr(basename($file), 0, -3); $p = Path::join($parent, Path::segment($name));
            if (isset($this->nodes[$p])) { throw new Failure('Mehrdeutige Markdown-Dateinamen.'); }
            $tree = Markdown::parse($this->canonical($this->read($file), $file), $name); $this->documents[$file] = $tree;
            $this->nodes[$parent]['level'] = 1;
            $this->nodes[$p] = ['name' => $name, 'level' => 2, 'text' => $tree['text'], 'file' => $file, 'indices' => []];
            $this->addHeadings($p, $tree, $file, []);
        }
    }
    protected function caps(string $area): array {
        $n = $this->node($area); $c = parent::caps($area);
        if (empty($this->config['readonly'])) {
            $c['canBeRenamed'] = $c['canBeDeleted'] = $area !== '/';
            $c['canAddSubAreas'] = true;
            $c['canAppendContent'] = $c['canTruncate'] = $n['level'] !== 0;
        }
        return $c;
    }
    protected function aggregate(string $area): string {
        $n = $this->node($area);
        if ($n['indices'] !== null) { $node = $this->documentNode($n); return Markdown::render($node); }
        $parts = [];
        foreach ($this->children($area) as $p) {
            $child = $this->node($p);
            if ($child['indices'] === []) { $parts[] = Markdown::render($this->documents[$child['file']], 1); }
        }
        return implode("\n\n", $parts);
    }
    protected function resources(string $area): array {
        $text = $this->aggregate($area); preg_match_all('/knowledge-resource:([A-Za-z0-9._~-]+)/', $text, $matches); $out = [];
        foreach (array_unique($matches[1]) as $id) {
            $p = $this->resourcePath($id);
            $out[] = ['ResourceId' => $id, 'FileName' => basename($p), 'ContentType' => $this->mime($p), 'Length' => strlen($this->read($p))];
        }
        return $out;
    }
    private function mime(string $p): string { $m = wp_check_filetype($p); return $m['type'] ?: 'application/octet-stream'; }
    protected function resource(string $id): string { $this->load(); return $this->read($this->resourcePath($id)); }
    private function &documentNode(array $n): array {
        $tree =& $this->documents[$n['file']];
        foreach ($n['indices'] as $index) { $tree =& $tree['children'][$index]; }
        return $tree;
    }
    private function persistDocuments(): void {
        foreach ($this->documents as $file => $tree) {
            if (isset($this->files[$file])) {
                $rendered = Markdown::render($tree);
                $old = $this->read($file);
                $oldTree = Markdown::parse($this->canonical($old, $file));
                if (Markdown::render($oldTree) !== $rendered) { $this->bytes[$file] = $this->physical($rendered, $file) . "\n"; }
            }
        }
    }
    private function put(string $file, string $bytes): void { $this->files[$file] = ['sha' => '', 'mode' => '100644']; $this->bytes[$file] = $bytes; }
    private function remove(string $file): void { unset($this->files[$file], $this->bytes[$file], $this->documents[$file]); }
    private function newFile(array $parent, string $name, int $kind): string {
        $name = Path::name($name); $dir = $parent['file'];
        return ($dir === '' ? '' : $dir . '/') . $name . ($kind === 1 ? '.md' : '/.gitkeep');
    }
    private function mergeAggregation(string $area, array $incoming): void {
        if (trim($incoming['text']) !== '') { throw new Failure('Ein Sammelbereich kann keinen direkten Text besitzen.'); }
        $target = $this->node($area);
        foreach ($incoming['children'] as $child) {
            $structural = strlen($child['name']) >= 2 && $child['name'][0] === '[' && substr($child['name'], -1) === ']';
            $displayName = $structural ? substr($child['name'], 1, -1) : $child['name'];
            $matches = [];
            foreach ($this->children($area) as $p) {
                if ($this->nodes[$p]['name'] === $displayName && ($this->nodes[$p]['indices'] === null) === $structural) { $matches[] = $p; }
            }
            if (count($matches) > 1) { throw new Failure('Mehrdeutiger Bereich.'); }
            if ($matches) {
                $n = $this->node($matches[0]);
                if ($n['level'] === 2) { $node =& $this->documentNode($n); Markdown::merge($node, $child); unset($node); }
                else { $this->mergeAggregation($matches[0], $child); }
            } else {
                $file = $this->newFile($target, $displayName, $structural ? 0 : 1);
                if (isset($this->files[$file])) { throw new Failure('Datei existiert bereits.'); }
                $this->put($file, '');
                if ($structural) {
                    $path = $this->addDirectory(dirname($file)); $this->mergeAggregation($path, $child);
                } else { $this->documents[$file] = $child; }

            }
        }
    }
    /** Apply one logical mutation in memory; only a successful ref update publishes it. */
    private function mutate(string $method, array $a): array {
        $result = ['return' => true];
        if (in_array($method, ['TryRename', 'TryMoveContent'], true)) { $result['resourceIdChanges'] = []; }
        if (isset($a['resourceId'])) {
            $p = $this->resourcePath($a['resourceId']);
            if ($method === 'TryDeleteResource') {
                foreach ($this->documents as $tree) { if (strpos(Markdown::render($tree), 'knowledge-resource:' . $a['resourceId']) !== false) { return Contract::failure($method); } }
                $this->remove($p);
            } else {
                $bytes = base64_decode($a['content'], true);
                if ($bytes === false) { throw new Failure('Ungültige Base64-Ressource.', 400); }
                $this->bytes[$p] = $bytes;
            }
            return $result;
        }
        $area = Path::normalize($a['area'] ?? $a['contentAreaToMove'] ?? '/'); $n = $this->node($area);
        if ($method === 'TryAddResource') {
            $bytes = base64_decode($a['content'], true);
            if ($bytes === false) { throw new Failure('Ungültige Base64-Ressource.', 400); }
            $name = sanitize_file_name($a['preferredFileName']);
            if ($name === '') {
                $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
                $prefixName = $n['indices'] === null ? 'Resource' : pathinfo($n['file'], PATHINFO_FILENAME);
                $name = $prefixName . '.Res' . random_int(100000000000, 999999999999) . '.' . ($extensions[$a['contentType']] ?? 'bin');
            }
            $dir = $n['indices'] === null ? $n['file'] : dirname($n['file']);
            $prefix = ($dir === '.' || $dir === '') ? '' : $dir . '/';
            $p = $prefix . $name;
            if (isset($this->files[$p]) || preg_match('/\.md$/i', $name)) {
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $p = $prefix . pathinfo($name, PATHINFO_FILENAME) . '.Res' . random_int(100000000000, 999999999999) . ($extension ? '.' . $extension : '');
            }
            $this->put($p, $bytes); $result['resourceId'] = $this->rid($p); return $result;
        }
        if ($method === 'TryAddSubArea') {
            $name = Path::name($a['name']);
            foreach ($this->children($area) as $p) { if ($this->nodes[$p]['name'] === $name) { return Contract::failure($method); } }
            if ($n['indices'] !== null) {
                if ($a['kind'] !== 1) { return Contract::failure($method); }
                $node =& $this->documentNode($n); $node['children'][] = ['name' => $name, 'text' => '', 'children' => []];
            } else { $file = $this->newFile($n, $name, $a['kind']); if (isset($this->files[$file])) { return Contract::failure($method); } $this->put($file, ''); }
        } elseif (in_array($method, ['TryAppendContent', 'TryReplace', 'TryTruncate'], true)) {
            if ($n['level'] === 0) { return Contract::failure($method); }
            $incoming = Markdown::parse($a['content'] ?? $a['newContent'] ?? '');
            if ($n['indices'] !== null) {
                $node =& $this->documentNode($n);
                if ($method !== 'TryAppendContent') { $node['text'] = ''; $node['children'] = []; }
                Markdown::merge($node, $incoming);
            } else {
                if ($method !== 'TryAppendContent') {
                    foreach ($this->children($area) as $p) { $child = $this->nodes[$p]; if ($child['level'] === 2) { $this->remove($child['file']); unset($this->nodes[$p]); } }
                }
                $this->mergeAggregation($area, $incoming);
            }
        } elseif ($method === 'TryDelete') {
            if ($area === '/') { return Contract::failure($method); }
            if ($n['indices']) {
                $indices = $n['indices']; $index = array_pop($indices); $parent = $n; $parent['indices'] = $indices;
                $node =& $this->documentNode($parent); array_splice($node['children'], $index, 1);
            } else {
                foreach (array_keys($this->files) as $p) {
                    if ($p === $n['file'] || ($n['indices'] === null && strpos($p, $n['file'] . '/') === 0)) { $this->remove($p); }
                }
            }
        } elseif (in_array($method, ['TryRename', 'TryMoveContent'], true)) {
            if ($area === '/') { return Contract::failure($method); }
            $name = $method === 'TryRename' ? Path::name($a['newName']) : $n['name'];
            $destination = $method === 'TryRename' ? Path::parent($area) : Path::normalize($a['newParentArea']);
            if (Path::contains($area, $destination)) { return Contract::failure($method); }
            $parent = $this->node($destination);
            foreach ($this->children($destination) as $p) { if ($p !== $area && $this->nodes[$p]['name'] === $name) { return Contract::failure($method); } }
            if ($n['indices']) {
                if ($parent['indices'] === null) { return Contract::failure($method); }
                $moving = $this->documentNode($n); $moving['name'] = $name;
                if ($method === 'TryRename') { $node =& $this->documentNode($n); $node['name'] = $name; }
                else {
                    // Locate the destination again after removal; numeric heading indices can shift.
                    $old = $n['indices']; $i = array_pop($old); $oldNode = $n; $oldNode['indices'] = $old;
                    $node =& $this->documentNode($oldNode); array_splice($node['children'], $i, 1); unset($node);
                    $this->persistDocuments(); $this->rebuild(); $parent = $this->node($destination);
                    $node =& $this->documentNode($parent); $node['children'][] = $moving;
                }
            } else {
                if ($parent['indices'] !== null) { return Contract::failure($method); }
                $newPath = ($parent['file'] === '' ? '' : $parent['file'] . '/') . $name . ($n['indices'] === [] ? '.md' : '');
                if ($newPath === $n['file']) { return $result; }
                $mapping = [];
                foreach (array_keys($this->files) as $p) {
                    if ($p === $n['file'] || ($n['indices'] === null && strpos($p, $n['file'] . '/') === 0)) { $mapping[$p] = $newPath . substr($p, strlen($n['file'])); }
                    elseif ($n['indices'] === [] && strpos($p, substr($n['file'], 0, -3) . '.Res') === 0) { $mapping[$p] = substr($newPath, 0, -3) . substr($p, strlen($n['file']) - 3); }
                }
                foreach ($mapping as $old => $new) { if (isset($this->files[$new]) && !isset($mapping[$new])) { return Contract::failure($method); } }
                $newFiles = $this->files; $newBytes = $this->bytes; $newDocs = $this->documents;
                foreach ($mapping as $old => $new) {
                    $newFiles[$new] = $this->files[$old]; $newBytes[$new] = $this->read($old);
                    unset($newFiles[$old], $newBytes[$old]);
                    if (isset($this->documents[$old])) { $newDocs[$new] = $this->documents[$old]; unset($newDocs[$old]); }
                    $result['resourceIdChanges'][] = ['PreviousResourceId' => $this->rid($old), 'CurrentResourceId' => $this->rid($new)];
                }
                $this->files = $newFiles; $this->bytes = $newBytes; $this->documents = $newDocs;
                foreach ($this->documents as &$tree) { $this->replaceIds($tree, $result['resourceIdChanges']); }
                unset($tree);
            }
        }
        $this->persistDocuments(); return $result;
    }
    private function replaceIds(array &$tree, array $changes): void {
        foreach ($changes as $change) { $tree['text'] = str_replace('knowledge-resource:' . $change['PreviousResourceId'], 'knowledge-resource:' . $change['CurrentResourceId'], $tree['text']); }
        foreach ($tree['children'] as &$child) { $this->replaceIds($child, $changes); }
    }
    public function call(string $method, array $args = []): array {
        if (!Contract::mutation($method)) { return parent::call($method, $args); }
        $a = Contract::arguments($method, $args);
        if (!empty($this->config['readonly'])) { return Contract::failure($method); }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->loaded = false; $this->bytes = []; $this->loadFresh(); $before = $this->files; $original = $this->bytes;
            try {
                $result = $this->mutate($method, $a);
                if (!$result['return']) { $this->loaded = false; $this->bytes = []; return $result; }
                $entries = [];
                foreach ($before as $p => $info) { if (!isset($this->files[$p])) { $entries[] = ['path' => ($this->root ? $this->root . '/' : '') . $p, 'mode' => $info['mode'], 'type' => 'blob', 'sha' => null]; } }
                foreach ($this->bytes as $p => $bytes) {
                    if (isset($before[$p]) && hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes) === $before[$p]['sha']) { continue; }
                    $blob = $this->api('/git/blobs', 'POST', ['content' => base64_encode($bytes), 'encoding' => 'base64']);
                    $entries[] = ['path' => ($this->root ? $this->root . '/' : '') . $p, 'mode' => $this->files[$p]['mode'] ?? '100644', 'type' => 'blob', 'sha' => $blob['sha']];
                }
                if (!$entries) { return $result; }
                $tree = $this->api('/git/trees', 'POST', ['base_tree' => $this->tree, 'tree' => $entries]);
                $commit = $this->api('/git/commits', 'POST', ['message' => 'KnowledgeRepository: ' . $method, 'tree' => $tree['sha'], 'parents' => [$this->head]]);
                try {
                    $this->api('/git/refs/heads/' . str_replace('%2F', '/', rawurlencode($this->branch)), 'PATCH', ['sha' => $commit['sha'], 'force' => false]);
                } finally { FileCache::invalidate(); }
                $this->loaded = false; $this->bytes = []; return $result;
            } catch (Failure $e) {
                $this->loaded = false; $this->bytes = [];
                if ($e->status === 409 && $attempt < 2) { continue; }
                throw $e;
            }
        }
        return Contract::failure($method);
    }
}
