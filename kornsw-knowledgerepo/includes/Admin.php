<?php
namespace KornSW\KnowledgeRepo;

final class Admin {
    public static function boot(): void {
        add_action('admin_menu', static function () {
            // Pages uses position 20; 21 keeps KnowledgeRepo directly below it.
            add_menu_page('KnowledgeRepo (KornSW)', 'KnowledgeRepo', 'manage_options', 'kornsw-knowledgerepo', [self::class, 'page'], 'dashicons-book-alt', 21);
        });
        add_action('admin_post_kornsw_kr_save', [self::class, 'save']);
        add_filter('plugin_action_links_' . plugin_basename(KORNSW_KR_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=kornsw-knowledgerepo')) . '">Einstellungen</a>'); return $links;
        });
    }
    public static function save(): void {
        if (!current_user_can('manage_options')) { wp_die('Nicht erlaubt.', '', ['response' => 403]); }
        check_admin_referer('kornsw_kr_save');
        $input = wp_unslash($_POST); $old = Auth::settings();
        $settings = ['jwt_ttl' => max(300, min(31536000, (int) ($input['jwt_ttl'] ?? 86400))),
            'auth_cache_ttl' => max(0, min(300, (int) ($input['auth_cache_ttl'] ?? 30))), 'permissions' => [], 'sources' => []];
        foreach (array_merge(['anonymous'], array_keys(wp_roles()->roles)) as $role) {
            foreach (['page', 'joplin', 'ujmw'] as $channel) {
                $settings['permissions'][$role][$channel] = max(0, min($role === 'anonymous' ? 1 : 2, (int) ($input['permissions'][$role][$channel] ?? 0)));
            }
        }
        $settings['cache_ttl'] = (int) round(max(0, min(168, (float) ($input['cache_hours'] ?? 4))) * 3600);
        $settings['display_mode'] = in_array($input['display_mode'] ?? '', ['neutral','themed','on_page'], true) ? $input['display_mode'] : 'neutral';
        $settings['accent_color'] = sanitize_hex_color(trim($input['accent_color'] ?? '')) ?? '';
        try {
            foreach ($input['sources'] ?? [] as $source) {
                if (!empty($source['remove'])) { continue; }
                $id = $source['id'] ?? '';
                if (!preg_match('/^[a-f0-9]{32}$/', $id)) { $id = bin2hex(random_bytes(16)); }
                $type = $source['type'] ?? '';
                if (!in_array($type, ['wordpress', 'github', 'github_multi', 'ujmw', 'urls', 'links'], true)) { throw new Failure('Unbekannter Quellentyp.', 400); }
                $entry = ['id' => $id, 'type' => $type, 'label' => sanitize_text_field($source['label'] ?? ''),
                    'mount' => Path::normalize($source['mount'] ?? '/'), 'readonly' => !empty($source['readonly']),
                    'url' => esc_url_raw(trim($source['url'] ?? '')), 'root' => Path::normalize($source['root'] ?? '/'),
                    'branch' => sanitize_text_field($source['branch'] ?? ''), 'category_levels' => !empty($source['category_levels']), 'categories' => array_values(array_filter(array_map('intval', $source['categories'] ?? []))),
                    'token' => $old['sources'][$id]['token'] ?? ''];
                if ($type === 'wordpress') { $entry['readonly'] = true; }
                if (in_array($type, ['github', 'ujmw'], true)) {
                    if (parse_url($entry['url'], PHP_URL_SCHEME) !== 'https' || parse_url($entry['url'], PHP_URL_USER) || parse_url($entry['url'], PHP_URL_PASS)) { throw new Failure('Remote-URL muss HTTPS ohne Zugangsdaten verwenden.', 400); }
                }
                if ($type === 'github') { new GitHubRepository($entry); }
                if ($type === 'github_multi') {
                    $entry['urls'] = implode("\n", array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $source['urls'] ?? '')), 'strlen'));
                    GitHubRepository::multiEntries($entry);
                }
                if (in_array($type, ['urls','links'], true)) {
                    $entry['readonly'] = true; $entry['token'] = ''; $entry['entries'] = []; $entry['links'] = [];
                    if ($type === 'urls') {
                        $entry['show_source'] = !empty($source['show_source']);
                        foreach ($source['entries'] ?? [] as $row) {
                            if (trim($row['url'] ?? '') === '' && trim($row['alias'] ?? '') === '') { continue; }
                            $format = $row['format'] ?? 'auto';
                            if (!in_array($format, ['auto','md','text','html'], true)) { throw new Failure('Ungültiges Dokumentformat.', 400); }
                            $entry['entries'][] = ['url'=>ConfiguredRepository::url($row['url'] ?? ''), 'mount'=>Path::normalize($row['mount'] ?? '/'), 'alias'=>Path::name($row['alias'] ?? ''), 'format'=>$format];
                        }
                    } else {
                        $entry['alias'] = Path::name($source['alias'] ?? 'Links');
                        $entry['mode'] = ($source['mode'] ?? 'list') === 'tiles' ? 'tiles' : 'list';
                        foreach ($source['links'] ?? [] as $row) {
                            if (trim($row['url'] ?? '') === '' && trim($row['title'] ?? '') === '') { continue; }
                            $url = ConfiguredRepository::url($row['url'] ?? '');
                            $entry['links'][] = ['url'=>$url, 'title'=>sanitize_text_field(trim($row['title'] ?? '') ?: $url), 'icon'=>trim($row['icon'] ?? '') !== '' ? ConfiguredRepository::url($row['icon']) : ''];
                        }
                    }
                    (new ConfiguredRepository($entry))->validate();
                }
                if (!empty($source['clear_token'])) { $entry['token'] = ''; }
                if (trim($source['token'] ?? '') !== '') { $entry['token'] = Auth::protectSecret(trim($source['token'])); }
                $settings['sources'][$id] = $entry;
            }
            update_option('kornsw_kr_settings', $settings, false);
            FileCache::invalidate();
            Auth::invalidateIntrospection();
            if (!empty($input['rotate'])) { update_option('kornsw_kr_jwt_secret', Path::b64(random_bytes(48)), true); }
        } catch (Failure $e) { wp_die(esc_html($e->getMessage()), 'Einstellungen nicht gespeichert', ['back_link' => true]); }
        wp_safe_redirect(admin_url('admin.php?page=kornsw-knowledgerepo&saved=1')); exit;
    }
    private static function source(array $source, string $index): void {
        $base = 'sources[' . $index . ']'; $type = $source['type'] ?? 'wordpress';
        $field = static function (string $key, string $label, string $default = '', string $providers = '') use ($source, $base, $type) {
            $hidden = $providers !== '' && !in_array($type, explode(' ', $providers), true);
            echo '<p data-providers="' . esc_attr($providers) . '"' . ($hidden ? ' hidden' : '') . '><label><span data-label="' . esc_attr($key) . '">' . esc_html($label) . '</span><br><input class="regular-text" name="' . esc_attr($base . '[' . $key . ']') . '" value="' . esc_attr($source[$key] ?? $default) . '"></label></p>';
        };
        echo '<fieldset class="kr-source"><legend>Quelle</legend><input type="hidden" name="' . esc_attr($base . '[id]') . '" value="' . esc_attr($source['id'] ?? '') . '">';
        echo '<p><label>Provider <select class="kr-provider" name="' . esc_attr($base . '[type]') . '">';
        foreach (['wordpress' => 'WordPress-Beiträge', 'github' => 'GitHub', 'github_multi'=>'GitHub (multi)', 'ujmw' => 'UJMW-Client', 'urls'=>'URL-Dokumente', 'links'=>'Linkliste'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($type, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        $field('label', 'Bezeichnung'); $field('mount', 'Mountpunkt', '/');
        echo '<p data-providers="github github_multi ujmw"' . ($type === 'wordpress' ? ' hidden' : '') . '><label><input type="checkbox" name="' . esc_attr($base . '[readonly]') . '" value="1" ' . checked(!empty($source['readonly']), true, false) . '> Quelle nur lesen</label></p>';
        $field('url', $type === 'github' ? 'GitHub-Repository-URL' : 'UJMW-Vertragsbasis-URL', '', 'github ujmw');
        echo '<p data-providers="github_multi"' . ($type !== 'github_multi' ? ' hidden' : '') . '><label>Repository-URLs (eine pro Zeile)<br><textarea class="large-text" rows="6" name="' . esc_attr($base . '[urls]') . '">' . esc_textarea($source['urls'] ?? '') . '</textarea></label><span class="description">Mountpunkt/RepoName; Einstieg, Branch und PAT gelten für alle.</span></p>';
        $field('root', 'GitHub-Einstiegsverzeichnis', '/', 'github github_multi');
        $field('branch', 'GitHub-Branch (leer: Default)', '', 'github github_multi');
        echo '<p data-providers="urls"' . ($type !== 'urls' ? ' hidden' : '') . '><label><input type="checkbox" name="' . esc_attr($base . '[show_source]') . '" value="1" ' . checked($source['show_source'] ?? true, true, false) . '> Quellverweis anzeigen</label></p>';
        $field('alias', 'Dateialias der Linkliste', 'Links', 'links');
        echo '<p data-providers="links"' . ($type !== 'links' ? ' hidden' : '') . '><label>Darstellung <select name="' . esc_attr($base . '[mode]') . '"><option value="list" ' . selected($source['mode'] ?? 'list', 'list', false) . '>Liste</option><option value="tiles" ' . selected($source['mode'] ?? 'list', 'tiles', false) . '>Kacheln</option></select></label></p>';
        foreach (['urls'=>'entries', 'links'=>'links'] as $provider=>$key) {
            echo '<div data-providers="' . $provider . '"' . ($type !== $provider ? ' hidden' : '') . '><p class="description">' . ($provider === 'urls' ? 'Dokumente werden erst bei Bedarf geladen. Mountpunkte gelten relativ zum Mountpunkt dieser Quelle. Dateialias ist der sichtbare Dokumentname.' : 'Genau eine Dokumentseite. Leere Icon-URL verwendet /favicon.ico der Zielseite.') . '</p><div class="kr-rows">';
            foreach ($source[$key] ?? [[]] as $number=>$row) { self::entryRow($base, $key, (string) $number, $row); }
            echo '</div><template class="kr-row-template">'; self::entryRow($base, $key, '__ROW__', []);
            echo '</template><p><button type="button" class="button kr-add-row">Eintrag hinzufügen</button></p></div>';
        }
        echo '<p data-providers="github github_multi ujmw"' . ($type === 'wordpress' ? ' hidden' : '') . '><label><span data-label="token">' . (in_array($type, ['github','github_multi'], true) ? 'GitHub Personal Access Token (PAT)' : 'JWT oder [PASS-TROUGH]') . '</span><br><input type="password" autocomplete="new-password" class="regular-text" name="' . esc_attr($base . '[token]') . '" value="" placeholder="' . (!empty($source['token']) ? 'Gespeichert – leer lassen zum Beibehalten' : '') . '"></label> <label><input type="checkbox" name="' . esc_attr($base . '[clear_token]') . '" value="1"> Entfernen</label></p>';
        echo '<div data-providers="wordpress"' . ($type !== 'wordpress' ? ' hidden' : '') . '><p><label><input type="checkbox" name="' . esc_attr($base . '[category_levels]') . '" value="1" ' . checked($source['category_levels'] ?? !empty($source['id']), true, false) . '> Kategorien als Navigationsebenen anzeigen</label><br><span class="description">Ohne Haken filtern die Kategorien nur die Beiträge. Beiträge stehen direkt am Mountpunkt; Mehrfachtreffer erscheinen dort einmal.</span></p><p>Kategorien auswählen</p><div class="kr-categories">';
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                echo '<label><input type="checkbox" name="' . esc_attr($base . '[categories][]') . '" value="' . (int) $term->term_id . '" ' . checked(in_array((int) $term->term_id, $source['categories'] ?? [], true), true, false) . '> ' . esc_html($term->name) . ' (#' . (int) $term->term_id . ')</label><br>';
            }
        }
        echo '</div></div><p><label><input type="checkbox" name="' . esc_attr($base . '[remove]') . '" value="1"> Diese Quelle beim Speichern entfernen</label></p></fieldset>';
    }
    private static function entryRow(string $base, string $key, string $index, array $row): void {
        echo '<fieldset class="kr-entry" style="border:1px solid #ccd0d4;padding:10px;margin:8px 0">';
        $fields = $key === 'entries' ? ['url'=>'URL', 'mount'=>'Relativer Mountpunkt', 'alias'=>'Dateialias'] : ['url'=>'URL', 'title'=>'Titel', 'icon'=>'Icon-URL (optional)'];
        foreach ($fields as $name=>$label) {
            echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" name="' . esc_attr($base . '[' . $key . '][' . $index . '][' . $name . ']') . '" value="' . esc_attr($row[$name] ?? ($name === 'mount' ? '/' : '')) . '"></label></p>';
        }
        if ($key === 'entries') {
            echo '<label>Format <select name="' . esc_attr($base . '[' . $key . '][' . $index . '][format]') . '">';
            foreach (['auto'=>'Automatisch', 'md'=>'Markdown', 'text'=>'Text', 'html'=>'HTML'] as $value=>$label) { echo '<option value="' . $value . '" ' . selected($row['format'] ?? 'auto', $value, false) . '>' . $label . '</option>'; }
            echo '</select></label> ';
        }
        echo '<button class="button kr-remove-row" type="button">Eintrag entfernen</button></fieldset>';
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $s = Auth::settings();
        echo '<div class="wrap"><h1>KnowledgeRepo (KornSW)</h1>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>'; }
        echo '<p><a href="' . esc_url(home_url('/wiki/')) . '">Wiki öffnen</a> · Joplin: <code>' . esc_html(home_url('/wiki/joplin/MEIN-PROFIL/')) . '</code> · UJMW: <code>' . esc_html(home_url('/wiki/ujmw/')) . '</code></p>';
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post"><input type="hidden" name="action" value="kornsw_kr_save">'; wp_nonce_field('kornsw_kr_save');
        echo '<h2>Wiki-Darstellung</h2><label>Display mode <select name="display_mode">';
        foreach (['neutral'=>'Neutral (Standard)', 'themed'=>'Themed', 'on_page'=>'On-Page'] as $value=>$label) { echo '<option value="' . $value . '" ' . selected($s['display_mode'] ?? 'neutral', $value, false) . '>' . $label . '</option>'; }
        echo '</select></label><p>Neutral: unverändert. Themed: Theme-Akzentfarbe. On-Page: zusätzlich WordPress-Header und -Footer.</p>';
        echo '<label>Akzentfarbe <input type="text" name="accent_color" value="' . esc_attr($s['accent_color'] ?? '') . '" placeholder="' . esc_attr(WikiPresentation::detectAccent()) . '" pattern="#([0-9a-fA-F]{3}){1,2}" size="9"></label> <span class="description">Nur für Themed/On-Page. Leer = automatisch aus dem Theme (derzeit erkannt: <code>' . esc_html(WikiPresentation::detectAccent()) . '</code>).</span>';
        echo '<p>API-Beschreibung: <a href="' . esc_url(home_url('/wiki/ujmw/swagger.json')) . '">swagger.json</a></p>';
        echo '<h2>Zugriff</h2><table class="widefat striped"><thead><tr><th>Rolle</th><th>Seite</th><th>Joplin</th><th>UJMW</th></tr></thead><tbody>';
        $roles = ['anonymous' => ['name' => 'Anonymous']] + wp_roles()->roles;
        foreach ($roles as $role => $info) {
            echo '<tr><td>' . esc_html(translate_user_role($info['name'])) . '</td>';
            foreach (['page', 'joplin', 'ujmw'] as $channel) {
                echo '<td>';
                if ($role === 'anonymous') {
                    echo '<input aria-label="Anonymous ' . esc_attr($channel) . '" type="checkbox" name="permissions[anonymous][' . esc_attr($channel) . ']" value="1" ' . checked(!empty($s['permissions'][$role][$channel]), true, false) . '> Nur lesen';
                } else {
                    echo '<select aria-label="' . esc_attr($info['name'] . ' ' . $channel) . '" name="permissions[' . esc_attr($role) . '][' . esc_attr($channel) . ']">';
                    foreach ([0 => 'Kein Zugriff', 1 => 'Nur lesen', 2 => 'Schreibzugriff'] as $value => $label) { echo '<option value="' . $value . '" ' . selected((int) ($s['permissions'][$role][$channel] ?? 0), $value, false) . '>' . esc_html($label) . '</option>'; }
                    echo '</select>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table><p>Anonymous verwendet bei Joplin <code>anonymous</code> / <code>anonymous</code>. Bei freigegebenem anonymem UJMW-Lesezugriff darf der Authorization-Header leer bleiben. Ungültige Tokens werden weiterhin abgelehnt.</p>';
        echo '<h2>Cache</h2><p><label>Lebensdauer (Stunden) <input type="number" min="0" max="168" step="0.25" name="cache_hours" value="' . esc_attr((string) (($s['cache_ttl'] ?? 14400) / 3600)) . '"></label></p><p>Standard: 4 Stunden. 0 deaktiviert den Cache. Angemeldete Benutzer können ihn im Wiki über Aktualisieren verwerfen.</p>';
        echo '<h2>Tokens</h2><p><label>Gültigkeit (Sekunden) <input type="number" min="300" max="31536000" name="jwt_ttl" value="' . (int) ($s['jwt_ttl'] ?? 86400) . '"></label></p><p><label>Benutzer-/Rollenprüfung zwischenspeichern (Sekunden) <input type="number" min="0" max="300" name="auth_cache_ttl" value="' . (int) ($s['auth_cache_ttl'] ?? 30) . '"></label><br><span class="description">Standard: 30 Sekunden. 0 prüft Benutzer, Rollen und Widerruf bei jedem UJMW-Aufruf erneut. Signatur und Ablauf werden immer geprüft.</span></p><p><label><input type="checkbox" name="rotate" value="1"> Alle bisher ausgestellten Tokens widerrufen</label></p>';
        echo '<h2>Quellen und Mountpunkte</h2><p>Reihenfolge entspricht der Lesereihenfolge im Aggregator. Mehrfachbelegungen sind als Overlay lesbar; mehrdeutige Schreibziele werden abgelehnt.</p><div id="kr-sources">';
        foreach ($s['sources'] ?? [] as $id => $source) { self::source($source, $id); }
        echo '</div><button type="button" class="button" id="kr-add">Quelle hinzufügen</button><template id="kr-template">'; self::source([], '__INDEX__'); echo '</template>';
        submit_button(); echo '</form></div>';
        echo '<style>.kr-source{border:1px solid #ccd0d4;padding:12px 18px;margin:16px 0;background:white;max-width:760px}.kr-source legend{font-weight:600}.kr-categories{max-height:220px;overflow:auto;padding:10px}</style>';
        echo <<<'JS'
<script>
(function () {
    function refresh(fieldset) {
        const type = fieldset.querySelector('.kr-provider').value;
        fieldset.querySelectorAll('[data-providers]').forEach(group => {
            const types = group.dataset.providers.split(' ').filter(Boolean);
            group.hidden = types.length > 0 && !types.includes(type);
            group.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = group.hidden; });
        });
        fieldset.querySelector('[data-label="url"]').textContent = type === 'github' ? 'GitHub-Repository-URL' : 'UJMW-Vertragsbasis-URL';
        fieldset.querySelector('[data-label="token"]').textContent = ['github','github_multi'].includes(type) ? 'GitHub Personal Access Token (PAT)' : 'JWT oder [PASS-TROUGH]';
    }
    const sources = document.getElementById('kr-sources');
    sources.querySelectorAll('.kr-source').forEach(refresh);
    sources.addEventListener('click', event => {
        if (event.target.matches('.kr-remove-row')) event.target.closest('.kr-entry').remove();
        if (event.target.matches('.kr-add-row')) {
            const group = event.target.closest('[data-providers]');
            const html = group.querySelector('template').innerHTML.replaceAll('__ROW__', 'r' + Date.now() + Math.random().toString(16).slice(2));
            group.querySelector('.kr-rows').insertAdjacentHTML('beforeend', html);
            refresh(event.target.closest('.kr-source'));
        }
    });
    sources.addEventListener('change', event => { if (event.target.matches('.kr-provider')) refresh(event.target.closest('.kr-source')); });
    document.getElementById('kr-add').addEventListener('click', () => {
        const html = document.getElementById('kr-template').innerHTML.replaceAll('__INDEX__', 'new' + Date.now() + Math.random().toString(16).slice(2));
        sources.insertAdjacentHTML('beforeend', html); refresh(sources.lastElementChild);
    });
})();
</script>
JS;
    }
}
