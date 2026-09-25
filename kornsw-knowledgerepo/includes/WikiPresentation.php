<?php
namespace KornSW\KnowledgeRepo;

/** HTML-only integration; API, resource and sync requests keep their early routing. */
final class WikiPresentation {
    public static function mode(): string {
        $mode = Auth::settings()['display_mode'] ?? 'neutral';
        return in_array($mode, ['neutral','themed','on_page'], true) ? $mode : 'neutral';
    }
    public static function defer(string $relative): bool {
        if (self::mode() !== 'on_page' || preg_match('~^/(?:ujmw|joplin|raw|_search|_resource)(?:/|$)~', $relative) || did_action('template_redirect')) { return false; }
        add_filter('pre_handle_404', static function ($preempt, $query) { $query->is_404 = false; return true; }, 10, 2);
        add_action('template_redirect', [Plugin::class, 'route'], 0);
        return true;
    }
    private static function color($color): string {
        if (!is_string($color)) { return ''; }
        $color = trim($color);
        return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/i', $color) || preg_match('/^(?:rgb|hsl)a?\([0-9.,%\s+-]+\)$/i', $color) ? $color : '';
    }
    public static function accent(): string {
        // An explicit admin choice beats every heuristic below.
        $configured = self::color(Auth::settings()['accent_color'] ?? '');
        if ($configured !== '') { return $configured; }
        return self::detectAccent();
    }
    public static function detectAccent(): string {
        $palette = function_exists('wp_get_global_settings') ? wp_get_global_settings(['color','palette']) : [];
        $colors = [];
        foreach (['default','theme','custom'] as $origin) {
            foreach ($palette[$origin] ?? [] as $item) { $colors[$item['slug']] = $item['color']; }
        }
        // Honor the theme/user's configured link color before guessing an accent slug.
        $link = function_exists('wp_get_global_styles') ? wp_get_global_styles(['elements','link','color','text']) : '';
        if (is_string($link) && preg_match('/^var:preset\|color\|(.+)$/', $link, $m)) { $link = $colors[$m[1]] ?? ''; }
        if (is_string($link) && preg_match('/^var\(--wp--preset--color--([a-z0-9-]+)\)$/i', $link, $m)) { $link = $colors[$m[1]] ?? ''; }
        $candidates = [$link];
        // Kadence stores its user palette separately from theme.json.
        $kadence = get_option('kadence_global_palette', []);
        if (is_string($kadence)) { $kadence = json_decode($kadence, true) ?: []; }
        $theme = function_exists('get_template') ? get_template() : '';
        if ($theme === 'kadence') { $candidates[] = $kadence['palette'][0]['color'] ?? $kadence['colors'][0]['color'] ?? ''; }
        // "primary" is the brand color in most palettes; "accent" is often only a secondary highlight (e.g. Occasio: green accent vs. navy primary).
        foreach (['primary','accent','accent-1','contrast'] as $slug) { $candidates[] = $colors[$slug] ?? ''; }
        if (function_exists('get_theme_mod')) { foreach (['accent_color','link_color','primary_color','theme_color'] as $key) { $candidates[] = get_theme_mod($key, ''); } }
        $color = '';
        foreach ($candidates as $candidate) { $color = self::color($candidate); if ($color !== '') { break; } }
        if ($color === '') { $color = '#216d55'; }
        if (function_exists('apply_filters')) { $color = self::color(apply_filters('kornsw_kr_accent_color', $color)) ?: '#216d55'; }
        return $color;
    }
    public static function style(string $nonce): void {
        if (self::mode() === 'neutral') { return; }
        echo '<style nonce="' . esc_attr($nonce) . '">#kornsw-wiki{--accent:' . self::accent() . '}</style>';
    }
    /** File modification time as version, so browsers pick up changed stylesheets immediately. */
    private static function asset(string $file): array {
        $mtime = @filemtime(dirname(KORNSW_KR_FILE) . '/assets/' . $file);
        return [plugins_url('assets/' . $file, KORNSW_KR_FILE), $mtime ? (string) $mtime : null];
    }
    /** Stylesheets of the standalone page (neutral/themed). */
    public static function head(string $nonce): void {
        $files = self::mode() === 'neutral' ? ['wiki.css'] : ['wiki.css', 'wiki-themed.css'];
        foreach ($files as $file) {
            [$url, $ver] = self::asset($file);
            echo '<link rel="stylesheet" href="' . esc_url($ver ? add_query_arg('ver', $ver, $url) : $url) . '">';
        }
        self::style($nonce);
    }
    private static function blockParts(): array {
        $slugs = ['header'=>'header', 'footer'=>'footer'];
        // Use the header/footer variants referenced by the page template where possible.
        $template = get_block_template(get_stylesheet() . '//page', 'wp_template');
        $scan = function (array $blocks) use (&$scan, &$slugs): void {
            foreach ($blocks as $block) {
                if (($block['blockName'] ?? '') === 'core/template-part') {
                    $a = $block['attrs']; $area = $a['area'] ?? '';
                    if (isset($slugs[$area]) && !empty($a['slug'])) { $slugs[$area] = $a['slug']; }
                }
                $scan($block['innerBlocks'] ?? []);
            }
        };
        if ($template) { $scan(parse_blocks($template->content)); }
        $parts = [];
        foreach ($slugs as $area=>$slug) {
            $parts[$area] = do_blocks('<!-- wp:template-part ' . wp_json_encode(['slug'=>$slug,'theme'=>get_stylesheet(),'tagName'=>$area]) . ' /-->');
        }
        return $parts;
    }
    private static $blockFooter = null;
    public static function header(string $title, string $nonce): void {
        add_filter('pre_get_document_title', static function () use ($title) { return $title . ' · Wissen'; });
        [$url, $ver] = self::asset('wiki-on-page.css');
        wp_enqueue_style('kornsw-kr-on-page', $url, [], $ver);
        [$url, $ver] = self::asset('wiki-themed.css');
        wp_enqueue_style('kornsw-kr-themed', $url, ['kornsw-kr-on-page'], $ver);
        // Lets theme CSS target the wiki page without relying on :has().
        add_filter('body_class', static function ($classes) { $classes[] = 'kornsw-wiki-page'; return $classes; });
        add_action('wp_head', static function () use ($nonce) { self::style($nonce); }, 100);
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $parts = self::blockParts(); self::$blockFooter = $parts['footer'];
            echo '<!doctype html><html '; language_attributes(); echo '><head><meta charset="'; bloginfo('charset'); echo '"><meta name="viewport" content="width=device-width, initial-scale=1">';
            wp_head(); echo '</head><body '; body_class(); echo '>'; wp_body_open(); echo '<div class="wp-site-blocks">' . $parts['header'];
        } else { get_header(); }
    }
    public static function footer(): void {
        if (self::$blockFooter !== null) {
            echo self::$blockFooter . '</div>'; wp_footer(); echo '</body></html>';
        } else { get_footer(); }
    }
}
