<?php
namespace KornSW\KnowledgeRepo;

final class Admin {
    public static function boot(): void {
        add_action('admin_menu', static function () { add_options_page('KnowledgeRepo (KornSW)', 'KnowledgeRepo (KornSW)', 'manage_options', 'kornsw-knowledgerepo', [self::class, 'page']); });
        add_action('admin_post_kornsw_kr_save', [self::class, 'save']);
        add_filter('plugin_action_links_' . plugin_basename(KORNSW_KR_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=kornsw-knowledgerepo')) . '">Einstellungen</a>'); return $links;
        });
    }
    public static function save(): void {
        if (!current_user_can('manage_options')) { wp_die('Nicht erlaubt.', '', ['response' => 403]); }
        check_admin_referer('kornsw_kr_save');
        $input = wp_unslash($_POST); $old = Auth::settings();
        $settings = ['jwt_ttl' => max(300, min(31536000, (int) ($input['jwt_ttl'] ?? 86400))), 'permissions' => [], 'sources' => []];
        foreach (array_merge(['anonymous'], array_keys(wp_roles()->roles)) as $role) {
            foreach (['page', 'joplin', 'ujmw'] as $channel) {
                $settings['permissions'][$role][$channel] = max(0, min($role === 'anonymous' ? 1 : 2, (int) ($input['permissions'][$role][$channel] ?? 0)));
            }
        }
        // UJMW requires a user-bound JWT in iteration one, even when anonymous is listed.
        $settings['permissions']['anonymous']['ujmw'] = 0;
        try {
            foreach ($input['sources'] ?? [] as $source) {
                if (!empty($source['remove'])) { continue; }
                $id = $source['id'] ?? '';
                if (!preg_match('/^[a-f0-9]{32}$/', $id)) { $id = bin2hex(random_bytes(16)); }
                $type = $source['type'] ?? '';
                if (!in_array($type, ['wordpress', 'github', 'ujmw'], true)) { throw new Failure('Unbekannter Quellentyp.', 400); }
                $entry = ['id' => $id, 'type' => $type, 'label' => sanitize_text_field($source['label'] ?? ''),
                    'mount' => Path::normalize($source['mount'] ?? '/'), 'readonly' => !empty($source['readonly']),
                    'url' => esc_url_raw(trim($source['url'] ?? '')), 'root' => Path::normalize($source['root'] ?? '/'),
                    'branch' => sanitize_text_field($source['branch'] ?? ''), 'categories' => array_values(array_filter(array_map('intval', $source['categories'] ?? []))),
                    'token' => $old['sources'][$id]['token'] ?? ''];
                if ($type === 'wordpress') { $entry['readonly'] = true; }
                if ($type !== 'wordpress') {
                    if (parse_url($entry['url'], PHP_URL_SCHEME) !== 'https' || parse_url($entry['url'], PHP_URL_USER) || parse_url($entry['url'], PHP_URL_PASS)) { throw new Failure('Remote-URL muss HTTPS ohne Zugangsdaten verwenden.', 400); }
                }
                if ($type === 'github') { new GitHubRepository($entry); }
                if (!empty($source['clear_token'])) { $entry['token'] = ''; }
                if (trim($source['token'] ?? '') !== '') { $entry['token'] = Auth::protectSecret(trim($source['token'])); }
                $settings['sources'][$id] = $entry;
            }
            update_option('kornsw_kr_settings', $settings, false);
            if (!empty($input['rotate'])) { update_option('kornsw_kr_jwt_secret', Path::b64(random_bytes(48)), false); }
        } catch (Failure $e) { wp_die(esc_html($e->getMessage()), 'Einstellungen nicht gespeichert', ['back_link' => true]); }
        wp_safe_redirect(admin_url('options-general.php?page=kornsw-knowledgerepo&saved=1')); exit;
    }
    private static function source(array $source, string $index): void {
        $base = 'sources[' . $index . ']';
        echo '<fieldset class="kr-source"><legend>Quelle</legend><input type="hidden" name="' . esc_attr($base . '[id]') . '" value="' . esc_attr($source['id'] ?? '') . '">';
        echo '<p><label>Provider <select name="' . esc_attr($base . '[type]') . '">';
        foreach (['wordpress' => 'WordPress-Beiträge', 'github' => 'GitHub', 'ujmw' => 'UJMW-Client'] as $type => $label) {
            echo '<option value="' . esc_attr($type) . '" ' . selected($source['type'] ?? 'wordpress', $type, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <label><input type="checkbox" name="' . esc_attr($base . '[readonly]') . '" value="1" ' . checked(!empty($source['readonly']), true, false) . '> Quelle nur lesen</label></p>';
        foreach (['label' => ['Bezeichnung', ''], 'mount' => ['Mountpunkt', '/'], 'url' => ['GitHub-Repository-URL / UJMW-Vertragsbasis-URL', ''], 'root' => ['GitHub-Einstiegsverzeichnis', '/'], 'branch' => ['GitHub-Branch (leer: Default)', '']] as $key => [$label, $default]) {
            echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" name="' . esc_attr($base . '[' . $key . ']') . '" value="' . esc_attr($source[$key] ?? $default) . '"></label></p>';
        }
        echo '<p><label>PAT / JWT / [PASS-TROUGH]<br><input type="password" autocomplete="new-password" class="regular-text" name="' . esc_attr($base . '[token]') . '" value="" placeholder="' . (!empty($source['token']) ? 'Gespeichert – leer lassen zum Beibehalten' : '') . '"></label> <label><input type="checkbox" name="' . esc_attr($base . '[clear_token]') . '" value="1"> Entfernen</label></p>';
        echo '<details><summary>WordPress-Kategorien auswählen</summary><div class="kr-categories">';
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                echo '<label><input type="checkbox" name="' . esc_attr($base . '[categories][]') . '" value="' . (int) $term->term_id . '" ' . checked(in_array((int) $term->term_id, $source['categories'] ?? [], true), true, false) . '> ' . esc_html($term->name) . ' (#' . (int) $term->term_id . ')</label><br>';
            }
        }
        echo '</div></details><p><label><input type="checkbox" name="' . esc_attr($base . '[remove]') . '" value="1"> Diese Quelle beim Speichern entfernen</label></p></fieldset>';
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $s = Auth::settings();
        echo '<div class="wrap"><h1>KnowledgeRepo (KornSW)</h1>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>'; }
        echo '<p><a href="' . esc_url(home_url('/wiki/')) . '">Wiki öffnen</a> · Joplin: <code>' . esc_html(home_url('/wiki/joplin/MEIN-PROFIL/')) . '</code> · UJMW: <code>' . esc_html(home_url('/wiki/ujmw/IKnowledgeRepository/')) . '</code></p>';
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post"><input type="hidden" name="action" value="kornsw_kr_save">'; wp_nonce_field('kornsw_kr_save');
        echo '<h2>Zugriff</h2><table class="widefat striped"><thead><tr><th>Rolle</th><th>Seite</th><th>Joplin</th><th>UJMW</th></tr></thead><tbody>';
        $roles = ['anonymous' => ['name' => 'Anonymous']] + wp_roles()->roles;
        foreach ($roles as $role => $info) {
            echo '<tr><td>' . esc_html(translate_user_role($info['name'])) . '</td>';
            foreach (['page', 'joplin', 'ujmw'] as $channel) {
                echo '<td>';
                if ($role === 'anonymous') {
                    echo '<input aria-label="Anonymous ' . esc_attr($channel) . '" type="checkbox" name="permissions[anonymous][' . esc_attr($channel) . ']" value="1" ' . checked(!empty($s['permissions'][$role][$channel]), true, false) . ($channel === 'ujmw' ? ' disabled' : '') . '> Nur lesen';
                } else {
                    echo '<select aria-label="' . esc_attr($info['name'] . ' ' . $channel) . '" name="permissions[' . esc_attr($role) . '][' . esc_attr($channel) . ']">';
                    foreach ([0 => 'Kein Zugriff', 1 => 'Nur lesen', 2 => 'Schreibzugriff'] as $value => $label) { echo '<option value="' . $value . '" ' . selected((int) ($s['permissions'][$role][$channel] ?? 0), $value, false) . '>' . esc_html($label) . '</option>'; }
                    echo '</select>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table><p>Anonymous verwendet bei Joplin <code>anonymous</code> / <code>anonymous</code>. UJMW setzt in dieser Version einen angemeldeten Benutzer mit eigenem Token voraus.</p>';
        echo '<h2>Tokens</h2><p><label>Gültigkeit (Sekunden) <input type="number" min="300" max="31536000" name="jwt_ttl" value="' . (int) ($s['jwt_ttl'] ?? 86400) . '"></label></p><p><label><input type="checkbox" name="rotate" value="1"> Alle bisher ausgestellten Tokens widerrufen</label></p>';
        echo '<h2>Quellen und Mountpunkte</h2><p>Reihenfolge entspricht der Lesereihenfolge im Aggregator. Mehrfachbelegungen sind als Overlay lesbar; mehrdeutige Schreibziele werden abgelehnt.</p><div id="kr-sources">';
        foreach ($s['sources'] ?? [] as $id => $source) { self::source($source, $id); }
        echo '</div><button type="button" class="button" id="kr-add">Quelle hinzufügen</button><template id="kr-template">'; self::source([], '__INDEX__'); echo '</template>';
        submit_button(); echo '</form></div>';
        echo '<style>.kr-source{border:1px solid #ccd0d4;padding:12px 18px;margin:16px 0;background:white;max-width:760px}.kr-source legend{font-weight:600}.kr-categories{max-height:220px;overflow:auto;padding:10px}</style>';
        echo '<script>document.getElementById("kr-add").addEventListener("click",function(){let t=document.getElementById("kr-template").innerHTML.replaceAll("__INDEX__","new"+Date.now()+Math.random().toString(16).slice(2));document.getElementById("kr-sources").insertAdjacentHTML("beforeend",t);});</script>';
    }
}
