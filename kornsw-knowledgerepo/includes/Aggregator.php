<?php
namespace KornSW\KnowledgeRepo;

/**
 * Ordered overlays with conservative writes and opaque aggregate resource identities.
 * Lazy: ownership is derived from mount points plus the parent's child listing, so a page
 * only touches the areas on its own path. Full trees are built only for recursive calls.
 */
final class Aggregator implements Repository {
    private $mounts; private $tolerant; private $errors = [];
    private $listed = []; private $owners = []; private $prefetched = []; private $names = [];
    public function __construct(array $mounts, bool $tolerant = false) {
        foreach ($mounts as $key => $mount) { $mounts[$key]['mount'] = Path::normalize($mount['mount']); }
        $this->mounts = $mounts; $this->tolerant = $tolerant;
    }
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
    private function reset(): void { $this->listed = []; $this->owners = []; $this->prefetched = []; $this->names = []; }
    private function assertWritableScope(string $area): void {
        foreach ($this->errors as $error) {
            if (Path::contains($error['mount'], $area) || Path::contains($area, $error['mount'])) {
                throw new Failure('Schreibziel wegen einer ausgefallenen Quelle nicht eindeutig verfügbar.', 503);
            }
        }
    }
    private function globalPath(string $mount, string $local): string { return Path::join($mount, ltrim($local, '/')); }
    private function localPath(string $mount, string $global): string { return $mount === '/' ? $global : Path::normalize(substr($global, strlen($mount))); }
    /** Direct children of one provider area (local paths), from a recursive prefetch when available. */
    private function sourceChildren(string $key, string $local): ?array {
        if (isset($this->prefetched[$key])) { return $this->prefetched[$key][$local] ?? []; }
        if (!array_key_exists($key . "\0" . $local, $this->listed)) {
            $result = $this->source($key, 'GetAreas', ['recurse' => false, 'startArea' => $local]);
            $this->listed[$key . "\0" . $local] = $result === null ? null : $result['return'];
        }
        return $this->listed[$key . "\0" . $local];
    }
    /** One recursive provider call per mount instead of one call per node. */
    private function prefetch(string $area): void {
        foreach ($this->mounts as $key => $mount) {
            if (isset($this->prefetched[$key]) || (!Path::contains($area, $mount['mount']) && !Path::contains($mount['mount'], $area))) { continue; }
            $result = $this->source($key, 'GetAreas', ['recurse' => true, 'startArea' => '/']);
            if ($result === null) { continue; }
            $index = ['/' => []];
            foreach ($result['return'] as $local) { $index[Path::parent($local)][] = $local; $index[$local] = $index[$local] ?? []; }
            $this->prefetched[$key] = $index;
        }
    }
    private function sourceHas(string $key, string $local): bool {
        // A mount root always exists; a failing source is reported via errors() on first use.
        if ($local === '/') { return true; }
        if (isset($this->prefetched[$key])) { return isset($this->prefetched[$key][$local]); }
        if (!$this->sourceHas($key, Path::parent($local))) { return false; }
        return in_array($local, $this->sourceChildren($key, Path::parent($local)) ?? [], true);
    }
    /** Providers owning an area, in configured overlay order. */
    private function targets(string $area): array {
        $area = Path::normalize($area);
        if (!isset($this->owners[$area])) {
            $out = [];
            foreach ($this->mounts as $key => $mount) {
                if (!Path::contains($mount['mount'], $area)) { continue; }
                $local = $this->localPath($mount['mount'], $area);
                if ($this->sourceHas((string) $key, $local)) { $out[] = [(string) $key, $local]; }
            }
            $this->owners[$area] = $out;
        }
        return $this->owners[$area];
    }
    /** Synthetic navigation nodes: the root and every ancestor of a mount point. */
    private function synthetic(string $area): bool {
        if ($area === '/') { return true; }
        foreach ($this->mounts as $mount) { if ($mount['mount'] !== $area && Path::contains($area, $mount['mount'])) { return true; } }
        return false;
    }
    private function exists(string $area): void {
        if (!$this->synthetic($area) && !$this->targets($area)) { throw new Failure('Bereich nicht gefunden.', 404); }
    }
    private function name(string $area): string {
        if (!isset($this->names[$area])) { $this->names[$area] = $this->call('GetAreaName', ['area' => $area])['return']; }
        return $this->names[$area];
    }
    private function compare(string $a, string $b): int { return strnatcasecmp($this->name($a), $this->name($b)); }
    private function children(string $area): array {
        $out = [];
        foreach ($this->targets($area) as [$key, $local]) {
            foreach ($this->sourceChildren($key, $local) ?? [] as $child) { $out[$this->globalPath($this->mounts[$key]['mount'], $child)] = true; }
        }
        $out = array_keys($out); $mounted = [];
        foreach ($this->mounts as $mount) {
            if ($mount['mount'] === $area || !Path::contains($area, $mount['mount'])) { continue; }
            $child = Path::join($area, explode('/', ltrim($this->localPath($area, $mount['mount']), '/'))[0]);
            if (!in_array($child, $out, true)) { $mounted[$child] = true; }
        }
        $mounted = array_keys($mounted); usort($mounted, [$this, 'compare']);
        if ($area === '/') { $out = array_merge($out, $mounted); usort($out, [$this, 'compare']); return $out; }
        // Deeper levels keep the provider order; mount points are inserted before the first
        // sibling that sorts after them. Unsorted provider lists therefore stay unchanged.
        foreach ($mounted as $child) {
            $at = count($out);
            foreach ($out as $i => $sibling) { if ($this->compare($sibling, $child) > 0) { $at = $i; break; } }
            array_splice($out, $at, 0, [$child]);
        }
        return $out;
    }
    private function walk(string $area): array {
        $this->prefetch($area); $out = [];
        $visit = function (string $parent) use (&$visit, &$out): void {
            foreach ($this->children($parent) as $child) { $out[] = $child; $visit($child); }
        };
        $visit($area); return $out;
    }
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
    private function caps(string $area): array {
        $targets = $this->targets($area); $this->exists($area);
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
        $a = Contract::arguments($method, $args);
        if (isset($a['resourceId'])) {
            [$key, $local] = $this->resolveResource($a['resourceId']); $a['resourceId'] = $local;
            if (isset($this->errors[$key])) { throw new Failure($this->errors[$key]['message'], 503); }
            if (isset($this->mounts[$key]['error'])) { throw $this->mounts[$key]['error']; }
            $result = $this->mounts[$key]['repo']->call($method, $a);
            if (Contract::mutation($method)) { $this->reset(); }
            return $result;
        }
        $area = Path::normalize($a['area'] ?? $a['contentAreaToMove'] ?? $a['startArea'] ?? '/');
        if (Contract::mutation($method)) {
            $targets = $this->targets($area);
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
            try { $result = $this->mounts[$key]['repo']->call($method, $a); }
            finally { $this->reset(); }
            if (!empty($result['resourceId'])) { $result['resourceId'] = $this->resourceId($key, $result['resourceId']); }
            foreach ($result['resourceIdChanges'] ?? [] as $i => $change) {
                $result['resourceIdChanges'][$i] = ['PreviousResourceId' => $this->resourceId($key, $change['PreviousResourceId']), 'CurrentResourceId' => $this->resourceId($key, $change['CurrentResourceId'])];
            }
            return $result;
        }
        $this->exists($area);
        if ($method === 'GetAreas') { return ['return' => $a['recurse'] ? $this->walk($area) : $this->children($area)]; }
        if ($method === 'GetAreaCapabilities') { return $this->caps($area); }
        if ($method === 'GetAreasByKeyword') {
            $matches = [];
            foreach ($this->mounts as $key => $mount) {
                $mountPoint = $mount['mount'];
                if (!Path::contains($area, $mountPoint) && !Path::contains($mountPoint, $area)) { continue; }
                $local = Path::contains($area, $mountPoint) ? '/' : $this->localPath($mountPoint, $area);
                $result = $this->source((string) $key, $method, ['keyword' => $a['keyword'], 'startArea' => $local]);
                if ($result === null) { continue; }
                foreach ($result['return'] as $p) { $global = $this->globalPath($mountPoint, $p); if (Path::contains($area, $global)) { $matches[$global] = true; } }
            }
            if (!$matches) { return ['return' => []]; }
            return ['return' => array_values(array_filter($this->walk($area), static function ($p) use ($matches) { return isset($matches[$p]); }))];
        }
        $targets = $this->targets($area);
        if ($method === 'GetAreaName') {
            if ($area === '/') { return ['return' => 'Wissen']; }
            if (!$targets || $targets[0][1] === '/') { return ['return' => rawurldecode(basename($area))]; }
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
