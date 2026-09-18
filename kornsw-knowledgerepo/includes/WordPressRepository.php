<?php
namespace KornSW\KnowledgeRepo;

/** WordPress projections are always read-only, independent of the role matrix. */
final class WordPressRepository extends TreeRepository {
    private $config; private $loaded = false; private $attachments = [];
    public function __construct(array $config) { $this->config = $config; }
    protected function load(): void {
        if ($this->loaded) { return; }
        $state = FileCache::remember('wordpress:' . wp_json_encode($this->config), function () {
            $this->loadFresh();
            return ['nodes' => $this->nodes, 'attachments' => $this->attachments];
        });
        $this->nodes = $state['nodes']; $this->attachments = $state['attachments'];
        $this->loaded = true;
    }
    private function loadFresh(): void {
        $this->nodes['/'] = ['name' => $this->config['label'] ?? 'WordPress', 'level' => 1, 'text' => ''];
        $selected = array_map('intval', $this->config['categories'] ?? []);
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name']);
        if (is_wp_error($terms)) { throw new Failure('Kategorien können nicht gelesen werden.', 503); }
        $byId = [];
        foreach ($terms as $term) { $byId[$term->term_id] = $term; }
        $paths = [];
        $addCategory = function (int $id) use (&$addCategory, &$paths, $byId): string {
            if (isset($paths[$id])) { return $paths[$id]; }
            $term = $byId[$id] ?? null;
            if (!$term) { return '/'; }
            $parent = $term->parent ? $addCategory((int) $term->parent) : '/';
            $path = Path::join($parent, Path::segment($term->name) . '~' . $id);
            $paths[$id] = $path;
            $this->nodes[$path] = ['name' => $term->name, 'level' => 1, 'text' => ''];
            return $path;
        };
        foreach ($selected as $id) {
            if (!isset($byId[$id])) { continue; }
            $parent = ($this->config['category_levels'] ?? true) ? $addCategory($id) : '/'; $page = 1;
            do {
                $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'has_password' => false,
                    'posts_per_page' => 100, 'paged' => $page++, 'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
                    'tax_query' => [['taxonomy' => 'category', 'field' => 'term_id', 'terms' => [$id], 'include_children' => false]]]);
                foreach ($posts as $post) {
                    $path = Path::join($parent, Path::segment($post->post_title ?: 'Beitrag') . '~' . $post->ID);
                    if (isset($this->nodes[$path])) { continue; }
                    // Do not execute shortcodes, dynamic blocks or content filters in authenticated exports.
                    $text = $this->htmlToMarkdown($post->post_content);
                    $this->nodes[$path] = ['name' => $post->post_title, 'level' => 2, 'text' => $text];
                }
            } while (count($posts) === 100);
        }
    }
    private function htmlToMarkdown(string $html): string {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $visit = function ($node) use (&$visit): string {
            if ($node instanceof \DOMText) { return $node->nodeValue; }
            if (!($node instanceof \DOMElement)) { return ''; }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'form'], true)) { return ''; }
            $text = '';
            foreach ($node->childNodes as $child) { $text .= $visit($child); }
            if ($tag === 'img') {
                $src = $node->getAttribute('src'); $id = attachment_url_to_postid($src);
                if ($id && get_post_status($id) === 'inherit') {
                    $file = get_attached_file($id);
                    if ($file && is_file($file)) {
                        $rid = 'wp.' . $id;
                        $this->attachments[$rid] = ['file' => $file, 'ResourceId' => $rid, 'FileName' => basename($file), 'ContentType' => get_post_mime_type($id), 'Length' => filesize($file)];
                        $src = 'knowledge-resource:' . $rid;
                    }
                }
                return '![' . str_replace(['[', ']'], '', $node->getAttribute('alt')) . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $src) . ')';
            }
            if ($tag === 'a') { return '[' . $text . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $node->getAttribute('href')) . ')'; }
            if (preg_match('/^h([1-6])$/', $tag, $m)) { return "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . trim($text) . "\n\n"; }
            if (in_array($tag, ['strong', 'b'], true)) { return '**' . $text . '**'; }
            if (in_array($tag, ['em', 'i'], true)) { return '*' . $text . '*'; }
            if ($tag === 'pre') { return "\n\n````\n" . trim($node->textContent) . "\n````\n\n"; }
            if ($tag === 'code') { return '`' . $text . '`'; }
            if ($tag === 'li') { return "\n- " . trim($text); }
            if ($tag === 'br') { return "  \n"; }
            if (in_array($tag, ['p', 'div', 'ul', 'ol', 'blockquote', 'table', 'tr'], true)) { return "\n\n" . $text . "\n\n"; }
            if (in_array($tag, ['td', 'th'], true)) { return trim($text) . ' | '; }
            return $text;
        };
        return trim(preg_replace('/\n{3,}/', "\n\n", $visit($doc->documentElement)));
    }
    protected function resources(string $area): array {
        $text = $this->aggregate($area); $out = [];
        foreach ($this->attachments as $id => $info) {
            if (strpos($text, 'knowledge-resource:' . $id) !== false) { unset($info['file']); $out[] = $info; }
        }
        return $out;
    }
    protected function resource(string $id): string {
        $this->load();
        if (!isset($this->attachments[$id])) { throw new Failure('Ressource nicht freigegeben.', 404); }
        $bytes = file_get_contents($this->attachments[$id]['file']);
        if ($bytes === false) { throw new Failure('Ressource nicht lesbar.', 503); }
        return $bytes;
    }
}
