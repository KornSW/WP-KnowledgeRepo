<?php
namespace KornSW\KnowledgeRepo;

/**
 * Read-only RAW facade (/wiki/raw/…), modeled on the .NET KnowledgeRepositoryRawController.
 * Self-describing Markdown navigation for humans and simple agents; containers return their
 * aggregated Markdown with absolute URLs. Aggregations never materialize their whole subtree.
 * Access control (channel "page") happens in Plugin::route() before this is called.
 */
final class Raw {
    const MARKDOWN = 'text/markdown; charset=utf-8';
    public static function url(string $area): string {
        return home_url('/wiki/raw' . ($area === '/' ? '/' : implode('/', array_map('rawurlencode', explode('/', Path::transport($area))))));
    }
    public static function resourceUrl(string $id): string { return home_url('/wiki/raw/resources/' . rawurlencode($id)); }
    /** @return array{0:int,1:array,2:string} */
    public static function handle(string $relative): array {
        $repo = Plugin::repository('', true);
        if (strpos($relative, '/resources/') === 0) {
            return Plugin::resourceResponse($repo, rawurldecode(substr($relative, strlen('/resources/'))));
        }
        $area = Path::normalize(Path::fromTransport(rawurldecode($relative ?: '/')));
        $level = $repo->call('GetAreaCapabilities', ['area' => $area]);
        $contentLevel = $level['contentLevel'];
        if (is_string($contentLevel)) { $contentLevel = (int) array_search($contentLevel, ['BeyondContent', 'ContentAggregation', 'ContentContainer'], true); }
        if ($contentLevel === 2) {
            $body = self::resolve($repo->call('GetAggregatedContent', ['area' => $area])['return']);
        } else {
            // ContentAggregation is navigation only: aggregating it could load an arbitrarily large subtree.
            $body = self::navigation($repo, $area, $level['supportsSubAreas']);
        }
        $errors = method_exists($repo, 'errors') ? $repo->errors() : [];
        if ($errors) {
            $body .= "\n\n---\n\nSome knowledge sources are currently unavailable; this response may be incomplete:\n";
            foreach ($errors as $error) { $body .= "\n- " . $error['label'] . ': ' . $error['message']; }
        }
        return [200, ['Content-Type' => self::MARKDOWN], $body];
    }
    private static function navigation(Repository $repo, string $area, bool $supportsSubAreas): string {
        $out = 'Knowledge area: `' . $area . "`\n\n";
        $children = $supportsSubAreas ? $repo->call('GetAreas', ['recurse' => false, 'startArea' => $area])['return'] : [];
        if (!$children) { return $out . 'This knowledge area contains no directly accessible sub-areas.'; }
        $out .= "The following directly accessible sub-area URLs are available:\n\n";
        foreach ($children as $child) { $out .= '- `' . $child . '` -> <' . self::url($child) . ">\n"; }
        return $out . "\nUse HTTP GET on any URL above to continue browsing or to retrieve its Markdown content.";
    }
    /** Resource IDs stay opaque; area references are URI-escaped logical paths. */
    private static function resolve(string $content): string {
        return preg_replace_callback('/(knowledge-area|knowledge-resource):([^\s\)\]>"\']+)/i', static function ($m) {
            if (strcasecmp($m[1], 'knowledge-resource') === 0) { return self::resourceUrl($m[2]); }
            $area = str_replace('\\', '/', trim(rawurldecode($m[2])));
            try { return self::url(Path::normalize($area === '' ? '/' : $area)); }
            catch (Failure $e) { return $m[0]; }
        }, $content);
    }
}
