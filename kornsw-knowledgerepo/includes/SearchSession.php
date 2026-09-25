<?php
namespace KornSW\KnowledgeRepo;

/** Browser-driven search: one bounded cooperative step per heartbeat, no worker. */
final class SearchSession {
    private static function owner(): string {
        $user = wp_get_current_user();
        return hash_hmac('sha256', wp_json_encode([home_url('/'), $user->ID, $user->roles, function_exists('wp_get_session_token') ? wp_get_session_token() : '', Auth::settings()]), wp_salt('auth'));
    }
    private static function directory(): string {
        $dir = FileCache::root() . '/search-v1/' . self::owner();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) { throw new Failure('Suchstatus kann nicht gespeichert werden.', 503); }
        @chmod($dir, 0700);
        return $dir;
    }
    private static function cleanup(string $dir): void {
        $n = 0;
        foreach (new \DirectoryIterator($dir) as $f) {
            if (++$n > 128) { break; }
            if (!$f->isFile() || $f->getExtension() !== 'state' || $f->getMTime() >= time() - 6) { continue; }
            $h = @fopen($f->getPathname(), 'r+');
            if ($h) { if (flock($h, LOCK_EX | LOCK_NB)) { @unlink($f->getPathname()); flock($h, LOCK_UN); } fclose($h); }
        }
    }
    private static function response(array $state): array {
        return array_intersect_key($state, array_flip(['id','processed','resultCount','results','completed','more','warnings']));
    }
    private static function save($h, array $state): void {
        $data = Auth::protectSecret(wp_json_encode($state));
        rewind($h);
        if (!ftruncate($h, 0) || fwrite($h, $data) !== strlen($data) || !fflush($h)) { throw new Failure('Suchstatus kann nicht gespeichert werden.', 503); }
    }
    public static function start(string $query): array {
        $dir = self::directory(); self::cleanup($dir); $id = bin2hex(random_bytes(32));
        $s = ['id'=>$id, 'owner'=>self::owner(), 'query'=>$query, 'heartbeat'=>microtime(true), 'pending'=>[['area'=>'/', 'document'=>null]], 'seen'=>[], 'processed'=>0, 'resultCount'=>0, 'results'=>[], 'completed'=>$query === '', 'more'=>false, 'warnings'=>[]];
        $h = @fopen($dir . '/' . $id . '.state', 'x+');
        if (!$h) { throw new Failure('Suchstatus kann nicht gespeichert werden.', 503); }
        @chmod($dir . '/' . $id . '.state', 0600);
        try { flock($h, LOCK_EX); self::save($h, $s); } finally { flock($h, LOCK_UN); fclose($h); }
        return self::response($s);
    }
    public static function poll(string $id, callable $factory): array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) { throw new Failure('Ungültige Suchsitzung.', 404); }
        $dir = self::directory(); $file = $dir . '/' . $id . '.state'; $h = @fopen($file, 'r+');
        if (!$h) { throw new Failure('Suche abgelaufen. Bitte erneut suchen.', 410); }
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); throw new Failure('Suchschritt läuft bereits.', 409); }
        try {
            $s = json_decode(Auth::revealSecret(stream_get_contents($h)), true);
            if (!is_array($s) || ($s['owner'] ?? '') !== self::owner() || microtime(true) - ($s['heartbeat'] ?? 0) > 6) { @unlink($file); throw new Failure('Suche abgelaufen. Bitte erneut suchen.', 410); }
            if (!$s['completed']) {
                $repo = $factory(); $until = microtime(true) + .350; $count = 0;
                while ($s['pending'] && $count < 25 && microtime(true) < $until) {
                    $entry = array_pop($s['pending']); $area = $entry['area'];
                    if (isset($s['seen'][$area])) { continue; } $s['seen'][$area] = true; $s['processed']++; $count++;
                    $doc = $entry['document']; $name = basename($area); $text = '';
                    try { $name = $repo->call('GetAreaName', ['area'=>$area])['return']; } catch (\Throwable $e) { FileCache::warn($area, $e); }
                    try { $caps = $repo->call('GetAreaCapabilities', ['area'=>$area]); if ($doc === null && in_array($caps['contentLevel'], [2,'ContentContainer'], true)) { $doc = $area; } } catch (\Throwable $e) { FileCache::warn($area, $e); }
                    try { $text = $repo->call('GetDirectContent', ['area'=>$area])['return']; } catch (\Throwable $e) { FileCache::warn($area, $e); }
                    if ($area !== '/' && mb_stripos($name . "\n" . $text, $s['query']) !== false) {
                        $snippet = preg_replace('/\s+/', ' ', strip_tags($text)); $at = mb_stripos($snippet, $s['query']); $start = $at === false ? 0 : max(0, $at - 70);
                        $s['results'][] = ['title'=>$name, 'path'=>$area, 'url'=>Plugin::link($doc ?? $area) . ($doc !== null && $doc !== $area ? '#section-' . substr(hash('sha256', $area), 0, 16) : ''), 'snippet'=>($start ? '…' : '') . mb_substr($snippet, $start, 220) . (mb_strlen($snippet) > $start + 220 ? '…' : '')];
                        $s['resultCount']++;
                        if ($s['resultCount'] >= 30) { $s['completed'] = true; $s['more'] = true; $s['pending'] = []; break; }
                    }
                    try {
                        foreach (array_reverse($repo->call('GetAreas', ['recurse'=>false, 'startArea'=>$area])['return']) as $child) {
                            if (Path::parent($child) === $area && !isset($s['seen'][$child])) { $s['pending'][] = ['area'=>$child, 'document'=>$doc]; }
                        }
                    } catch (\Throwable $e) { FileCache::warn($area, $e); }
                }
                $s['completed'] = $s['completed'] || !$s['pending'];
                $s['warnings'] = array_values(array_unique(array_merge($s['warnings'], FileCache::warnings(), method_exists($repo, 'errors') ? $repo->errors() : []), SORT_REGULAR));
            }
            // The budget is cooperative: a single synchronous provider call can take longer.
            $s['heartbeat'] = microtime(true); self::save($h, $s); return self::response($s);
        } finally { flock($h, LOCK_UN); fclose($h); }
    }
}
