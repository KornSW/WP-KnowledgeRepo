<?php
namespace KornSW\KnowledgeRepo;

/** Provider-neutral RPC contract, preserving the .NET parameter and out names. */
final class Contract {
    const METHODS = [
        'GetAreas' => ['recurse' => false, 'startArea' => '/'],
        'GetAreasByKeyword' => ['keyword' => '', 'startArea' => '/'],
        'GetAreaName' => ['area' => '/'],
        'GetAreaCapabilities' => ['area' => '/'],
        'GetResources' => ['area' => '/'],
        'GetResourceContent' => ['resourceId' => ''],
        'HasDirectContent' => ['area' => '/'],
        'GetDirectContent' => ['area' => '/'],
        'GetAggregatedContent' => ['area' => '/'],
        'TryDelete' => ['area' => '/'],
        'TryRename' => ['area' => '/', 'newName' => ''],
        'TryAddSubArea' => ['area' => '/', 'name' => '', 'kind' => 1],
        'TryAppendContent' => ['area' => '/', 'content' => ''],
        'TryTruncate' => ['area' => '/'],
        'TryReplace' => ['area' => '/', 'newContent' => ''],
        'TryMoveContent' => ['contentAreaToMove' => '/', 'newParentArea' => '/'],
        'TryAddResource' => ['area' => '/', 'preferredFileName' => '', 'contentType' => '', 'content' => ''],
        'TryReplaceResource' => ['resourceId' => '', 'contentType' => '', 'content' => ''],
        'TryDeleteResource' => ['resourceId' => ''],
    ];
    public static function mutation(string $method): bool { return strpos($method, 'Try') === 0; }
    public static function failure(string $method): array {
        $result = ['return' => false];
        if (in_array($method, ['TryMoveContent', 'TryRename'], true)) { $result['resourceIdChanges'] = []; }
        if ($method === 'TryAddResource') { $result['resourceId'] = ''; }
        return $result;
    }
    public static function arguments(string $method, array $args): array {
        if (!isset(self::METHODS[$method])) { throw new Failure('Unbekannte Operation.', 404); }
        $out = [];
        foreach (self::METHODS[$method] as $key => $default) {
            if (self::mutation($method) && !array_key_exists($key, $args)) { throw new Failure('Pflichtparameter fehlt: ' . $key, 400); }
            $value = $args[$key] ?? $default;
            if ($key === 'recurse') {
                if (!is_bool($value)) { throw new Failure('recurse muss boolesch sein.', 400); }
            } elseif ($key === 'kind') {
                if ($value === 'Structural') { $value = 0; }
                if ($value === 'Content') { $value = 1; }
                if (!in_array($value, [0, 1], true)) { throw new Failure('Ungültiger Bereichstyp.', 400); }
            } elseif (!is_string($value)) { throw new Failure('Ungültiger Parameter: ' . $key, 400); }
            $out[$key] = $value;
        }
        return $out;
    }
}
interface Repository { public function call(string $method, array $args = []): array; }
class Failure extends \RuntimeException {
    public $status;
    public function __construct(string $message, int $status = 409) { parent::__construct($message); $this->status = $status; }
}
final class Path {
    public static function normalize(string $path): string {
        if (preg_match('/[\x00-\x1f\\\\]/', $path)) { throw new Failure('Ungültiger Pfad.', 400); }
        $parts = explode('/', trim($path, '/'));
        foreach ($parts as $p) { if ($p === '.' || $p === '..') { throw new Failure('Ungültiger Pfad.', 400); } }
        return '/' . implode('/', array_filter($parts, static function ($p) { return $p !== ''; }));
    }
    public static function parent(string $path): string { $p = strrpos($path, '/'); return $p === 0 ? '/' : substr($path, 0, $p); }
    public static function join(string $a, string $b): string { return self::normalize(rtrim($a, '/') . '/' . $b); }
    public static function contains(string $root, string $path): bool { return $root === '/' || $root === $path || strpos($path, $root . '/') === 0; }
    public static function segment(string $name): string { return str_replace(['%', '/', '\\', '[', ']'], ['%25', '%2F', '%5C', '%5B', '%5D'], $name); }
    public static function name(string $name): string {
        $name = trim($name);
        if ($name === '' || preg_match('/[\x00-\x1f\/\\\\]/', $name) || in_array($name, ['.', '..'], true)) { throw new Failure('Ungültiger Name.', 400); }
        return $name;
    }
    public static function b64(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
    public static function unb64(string $text): string {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false) { throw new Failure('Ungültige Kodierung.', 400); }
        return $bytes;
    }
}
/** Ordered Markdown tree. Fenced code blocks never create headings. */
final class Markdown {
    public static function parse(string $text, string $name = ''): array {
        $root = ['name' => $name, 'text' => '', 'children' => []];
        $stack = []; $levels = [0]; $current =& $root; $fence = '';
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                if ($fence === '') { $fence = $m[1]; }
                elseif ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence) && preg_match('/^ {0,3}(?:`+|~+)\s*$/', $line)) { $fence = ''; }
                $current['text'] .= $line . "\n"; continue;
            }
            if ($fence === '' && preg_match('/^ {0,3}(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*$/', $line, $m)) {
                $level = strlen($m[1]);
                while (count($levels) > 1 && end($levels) >= $level) { array_pop($levels); array_pop($stack); }
                $current =& $root;
                foreach ($stack as $index) { $current =& $current['children'][$index]; }
                $index = count($current['children']);
                $current['children'][] = ['name' => trim($m[2]), 'text' => '', 'children' => []];
                $stack[] = $index; $levels[] = $level;
                $current =& $current['children'][$index];
            } else { $current['text'] .= $line . "\n"; }
        }
        self::trimTree($root); return $root;
    }
    private static function trimTree(array &$node): void {
        $node['text'] = trim($node['text'], "\r\n");
        foreach ($node['children'] as &$child) { self::trimTree($child); }
    }
    public static function render(array $node, int $depth = 0): string {
        if ($depth > 6) { throw new Failure('Markdown unterstützt maximal sechs Überschriftenebenen.'); }
        $parts = [];
        if ($depth) { $parts[] = str_repeat('#', $depth) . ' ' . $node['name']; }
        if ($node['text'] !== '') { $parts[] = $node['text']; }
        foreach ($node['children'] as $child) { $parts[] = self::render($child, $depth + 1); }
        return implode("\n\n", $parts);
    }
    public static function merge(array &$target, array $incoming): void {
        if ($incoming['text'] !== '') { $target['text'] = trim($target['text'] . "\n\n" . $incoming['text'], "\n"); }
        foreach ($incoming['children'] as $child) {
            $matches = [];
            foreach ($target['children'] as $i => $existing) { if ($existing['name'] === $child['name']) { $matches[] = $i; } }
            if (count($matches) > 1) { throw new Failure('Mehrdeutige Überschrift.'); }
            if ($matches) { self::merge($target['children'][$matches[0]], $child); }
            else { $target['children'][] = $child; }
        }
    }
}
/** Common read behavior over a provider's ordered logical tree. */
abstract class TreeRepository implements Repository {
    protected $nodes = [];
    abstract protected function load(): void;
    protected function node(string $area): array {
        $this->load(); $area = Path::normalize($area);
        if (!isset($this->nodes[$area])) { throw new Failure('Bereich nicht gefunden.', 404); }
        return $this->nodes[$area];
    }
    protected function children(string $area): array {
        $this->load(); $out = [];
        foreach ($this->nodes as $p => $n) { if ($p !== '/' && Path::parent($p) === $area) { $out[] = $p; } }
        return $out;
    }
    protected function walk(string $area): array {
        $out = [];
        foreach ($this->children($area) as $p) { $out[] = $p; $out = array_merge($out, $this->walk($p)); }
        return $out;
    }
    protected function caps(string $area): array {
        $n = $this->node($area);
        return ['contentLevel' => $n['level'], 'supportsSubAreas' => true, 'canBeRenamed' => false, 'canBeDeleted' => false,
            'canAddSubAreas' => false, 'canAppendContent' => false, 'canTruncate' => false, 'supportsResources' => true];
    }
    protected function aggregate(string $area): string {
        $n = $this->node($area); $text = $n['text'];
        foreach ($this->children($area) as $p) {
            $child = $this->node($p);
            if ($n['level'] !== 2 && $child['level'] !== 2) { continue; }
            $text .= "\n\n# " . $child['name'] . "\n\n" . preg_replace('/^(#{1,5}) /m', '#$1 ', $this->aggregate($p));
        }
        return trim($text, "\n");
    }
    protected function resources(string $area): array { return []; }
    protected function resource(string $id): string { throw new Failure('Ressource nicht gefunden.', 404); }
    public function call(string $method, array $args = []): array {
        $a = Contract::arguments($method, $args);
        if (Contract::mutation($method)) { return Contract::failure($method); }
        $area = Path::normalize($a['area'] ?? $a['startArea'] ?? '/');
        switch ($method) {
            case 'GetAreas': $this->node($area); return ['return' => $a['recurse'] ? $this->walk($area) : $this->children($area)];
            case 'GetAreasByKeyword':
                $out = [];
                foreach ($this->walk($area) as $p) { $n = $this->node($p); if (stripos($n['name'] . "\n" . $n['text'], $a['keyword']) !== false) { $out[] = $p; } }
                return ['return' => $out];
            case 'GetAreaName': return ['return' => $this->node($area)['name']];
            case 'GetAreaCapabilities': return $this->caps($area);
            case 'HasDirectContent': return ['return' => $this->node($area)['text'] !== ''];
            case 'GetDirectContent': return ['return' => $this->node($area)['text']];
            case 'GetAggregatedContent': return ['return' => $this->aggregate($area)];
            case 'GetResources': $this->node($area); return ['return' => $this->resources($area)];
            case 'GetResourceContent': return ['return' => base64_encode($this->resource($a['resourceId']))];
        }
        throw new Failure('Unbekannte Operation.', 404);
    }
}
