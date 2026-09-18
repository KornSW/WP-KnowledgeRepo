<?php
namespace KornSW\KnowledgeRepo;

/** Ordered overlays with conservative writes and opaque aggregate resource identities. */
final class Aggregator extends TreeRepository {
    private $mounts; private $owners = []; private $loaded = false; private $tolerant; private $errors = [];
    public function __construct(array $mounts, bool $tolerant = false) { $this->mounts = $mounts; $this->tolerant = $tolerant; }
    public function errors(): array { return array_values($this->errors); }
    private function source(string $key, string $method, array $args = []): ?array {
        if (isset($this->errors[$key]) && $this->tolerant && !Contract::mutation($method)) { return null; }
        try {
            if (isset($this->mounts[$key]['error'])) { throw $this->mounts[$key]['error']; }
            return $this->mounts[$key]['repo']->call($method, $args);
        } catch (Failure $e) {
            if (!$this->tolerant || Contract::mutation($method)) { throw $e; }
            $this->errors[$key] = ['label' => $this->mounts[$key]['label'] ?? $this->mounts[$key]['mount'], 'mount' => $this->mounts[$key]['mount'], 'message' => $e->getMessage()];
            return null;
        }
    }
    private function assertWritableScope(string $area): void {
        foreach ($this->errors as $error) {
            if (Path::contains($error['mount'], $area) || Path::contains($area, $error['mount'])) {
                throw new Failure('Schreibziel wegen einer ausgefallenen Quelle nicht eindeutig verfügbar.', 503);
            }
        }
    }
    private function globalPath(string $mount, string $local): string { return Path::join($mount, ltrim($local, '/')); }
    protected function load(): void {
        if ($this->loaded) { return; }
        $this->nodes = ['/'=> ['name' => 'Wissen', 'level' => 0, 'text' => '']]; $this->owners = [];
        foreach ($this->mounts as $key => $mount) {
            $root = Path::normalize($mount['mount']); $ancestors = []; $parent = $root;
            while ($parent !== '/') { $ancestors[] = $parent; $parent = Path::parent($parent); }
            foreach (array_reverse($ancestors) as $p) {
                if (!isset($this->nodes[$p])) { $this->nodes[$p] = ['name' => rawurldecode(basename($p)), 'level' => 0, 'text' => '']; }
            }
            $result = $this->source($key, 'GetAreas', ['recurse' => true]);
            if ($result === null) { continue; }
            $areas = array_merge(['/'], $result['return']);
            foreach ($areas as $local) {
                $p = $this->globalPath($root, $local); $this->owners[$p][] = [$key, $local];
                if (!isset($this->nodes[$p])) { $this->nodes[$p] = ['name' => '', 'level' => 0, 'text' => '']; }
            }
        }
        $this->loaded = true;
    }
    private function targets(string $area): array { $this->load(); return $this->owners[Path::normalize($area)] ?? []; }
    private function resourceId(string $key, string $child): string { return 'a.' . Path::b64(json_encode([$key, $child], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private function resolveResource(string $id): array {
        if (strpos($id, 'a.') !== 0) { throw new Failure('Ressource nicht gefunden.', 404); }
        $pair = json_decode(Path::unb64(substr($id, 2)), true);
        if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1]) || !isset($this->mounts[$pair[0]])) { throw new Failure('Ressource nicht gefunden.', 404); }
        return $pair;
    }
    private function translate(string $text, string $key, bool $outward): string {
        return preg_replace_callback('/knowledge-resource:([A-Za-z0-9._~-]+)/', function ($m) use ($key, $outward) {
            if ($outward) { return 'knowledge-resource:' . $this->resourceId($key, $m[1]); }
            [$owner, $id] = $this->resolveResource($m[1]);
            if ($owner !== $key) { throw new Failure('Ressourcen können nicht providerübergreifend geschrieben werden.'); }
            return 'knowledge-resource:' . $id;
        }, $text);
    }
    protected function caps(string $area): array {
        $targets = $this->targets($area); $this->node($area);
        $out = ['contentLevel' => 0, 'supportsSubAreas' => true, 'canBeRenamed' => false, 'canBeDeleted' => false, 'canAddSubAreas' => false, 'canAppendContent' => false, 'canTruncate' => false, 'supportsResources' => false];
        foreach ($targets as [$key, $local]) {
            $c = $this->source($key, 'GetAreaCapabilities', ['area' => $local]);
            if ($c === null) { continue; }
            $level = $c['contentLevel'];
            if (is_string($level)) { $level = array_search($level, ['BeyondContent', 'ContentAggregation', 'ContentContainer'], true); }
            $out['contentLevel'] = max($out['contentLevel'], (int) $level);
            $out['supportsResources'] = $out['supportsResources'] || $c['supportsResources'];
            if (count($targets) === 1) {
                foreach (['canBeRenamed', 'canBeDeleted', 'canAddSubAreas', 'canAppendContent', 'canTruncate'] as $field) { $out[$field] = $c[$field]; }
                if ($local === '/') { $out['canBeRenamed'] = $out['canBeDeleted'] = false; }
            }
        }
        return $out;
    }
    public function call(string $method, array $args = []): array {
        $a = Contract::arguments($method, $args); $this->load();
        if (isset($a['resourceId'])) {
            [$key, $local] = $this->resolveResource($a['resourceId']); $a['resourceId'] = $local;
            if (isset($this->errors[$key])) { throw new Failure($this->errors[$key]['message'], 503); }
            return $this->mounts[$key]['repo']->call($method, $a);
        }
        $area = Path::normalize($a['area'] ?? $a['contentAreaToMove'] ?? $a['startArea'] ?? '/');
        $targets = $this->targets($area);
        if (Contract::mutation($method)) {
            $this->assertWritableScope($area);
            if (isset($a['newParentArea'])) { $this->assertWritableScope($a['newParentArea']); }
            if (count($targets) !== 1) { return Contract::failure($method); }
            [$key, $local] = $targets[0];
            if ($local === '/' && in_array($method, ['TryDelete', 'TryRename', 'TryMoveContent'], true)) { return Contract::failure($method); }
            if ($method === 'TryMoveContent') {
                $dest = $this->targets($a['newParentArea']);
                if (count($dest) !== 1 || $dest[0][0] !== $key) { return Contract::failure($method); }
                $a['newParentArea'] = $dest[0][1]; $a['contentAreaToMove'] = $local;
            } else { $a['area'] = $local; }
            foreach (['content', 'newContent'] as $field) {
                if (isset($a[$field]) && $method !== 'TryAddResource') { $a[$field] = $this->translate($a[$field], $key, false); }
            }
            $result = $this->mounts[$key]['repo']->call($method, $a);
            if (!empty($result['resourceId'])) { $result['resourceId'] = $this->resourceId($key, $result['resourceId']); }
            foreach ($result['resourceIdChanges'] ?? [] as $i => $change) {
                $result['resourceIdChanges'][$i] = ['PreviousResourceId' => $this->resourceId($key, $change['PreviousResourceId']), 'CurrentResourceId' => $this->resourceId($key, $change['CurrentResourceId'])];
            }
            $this->loaded = false; return $result;
        }
        if ($method === 'GetAreas') { return parent::call($method, $a); }
        if ($method === 'GetAreaCapabilities') { return $this->caps($area); }
        if ($method === 'GetAreasByKeyword') {
            $matches = [];
            foreach ($this->mounts as $key => $mount) {
                $mountPoint = Path::normalize($mount['mount']);
                if (!Path::contains($area, $mountPoint) && !Path::contains($mountPoint, $area)) { continue; }
                $local = Path::contains($area, $mountPoint) ? '/' : Path::normalize(substr($area, strlen(rtrim($mountPoint, '/'))));
                $result = $this->source($key, $method, ['keyword' => $a['keyword'], 'startArea' => $local]);
                if ($result === null) { continue; }
                foreach ($result['return'] as $p) { $global = $this->globalPath($mountPoint, $p); if (Path::contains($area, $global)) { $matches[$global] = true; } }
            }
            return ['return' => array_values(array_filter($this->walk($area), static function ($p) use ($matches) { return isset($matches[$p]); }))];
        }
        $this->node($area);
        if ($method === 'GetAreaName') {
            if ($area === '/' || !$targets || $targets[0][1] === '/') { return ['return' => $this->nodes[$area]['name'] ?: rawurldecode(basename($area))]; }
            return $this->source($targets[0][0], $method, ['area' => $targets[0][1]]) ?? ['return' => rawurldecode(basename($area))];
        }
        if ($method === 'HasDirectContent') { return ['return' => $this->call('GetDirectContent', ['area' => $area])['return'] !== '']; }
        $values = [];
        foreach ($targets as [$key, $local]) {
            $result = $this->source($key, $method, ['area' => $local]);
            if ($result === null) { continue; }
            $r = $result['return'];
            if ($method === 'GetResources') {
                foreach ($r as $info) { $info['ResourceId'] = $this->resourceId($key, $info['ResourceId']); $values[] = $info; }
            } elseif ($r !== '') { $values[] = $this->translate($r, $key, true); }
        }
        return ['return' => $method === 'GetResources' ? $values : implode("\n\n", $values)];
    }
}
