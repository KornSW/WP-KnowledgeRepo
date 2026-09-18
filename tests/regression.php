<?php
/** Standalone regression suite: PHP 8.1+; no WordPress or remote writes needed. */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); } });
define('ABSPATH', '/test/wordpress/');
define('KORNSW_KR_STATE_DIR', sys_get_temp_dir() . '/kr-tests-' . bin2hex(random_bytes(6)));
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_mkdir_p($p) { return is_dir($p) || mkdir($p, 0700, true); }
function wp_salt($scheme) { return 'test-salt-not-production'; }
function home_url($p = '') { return 'https://test.invalid' . $p; }
function sanitize_file_name($s) { return preg_replace('/[^a-zA-Z0-9_.-]/', '-', $s); }
function wp_check_filetype($p) { return ['type' => str_ends_with($p, '.png') ? 'image/png' : 'application/octet-stream']; }
function is_wp_error($v) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return json_encode($r['body']); }
$GLOBALS['options'] = [];
function get_option($k, $default = false) { return $GLOBALS['options'][$k] ?? $default; }
function add_option($k, $v, $a = '', $b = false) { if (isset($GLOBALS['options'][$k])) { return false; } $GLOBALS['options'][$k] = $v; return true; }
function update_option($k, $v, $a = false) { $GLOBALS['options'][$k] = $v; }
function get_user_meta($id, $k, $single = true) { return $GLOBALS['usermeta'][$id][$k] ?? 0; }
function get_current_user_id() { return 7; }
function wp_get_current_user() { return (object) ['ID' => 7, 'roles' => ['editor']]; }
function get_user_by($key, $value) { return $value === 7 ? wp_get_current_user() : false; }
require __DIR__ . '/../kornsw-knowledgerepo/includes/Contract.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/Http.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/GitHubRepository.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/Aggregator.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/Auth.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/Joplin.php';
use KornSW\KnowledgeRepo\{Contract, Markdown, Path, GitHubRepository, Aggregator, Auth, Joplin, SyncStore, Failure};

/** A GitHub object service simulator with a real blob/tree/commit/ref publication boundary. */
final class GitService {
    public $files = []; public $blobs = []; public $trees = []; public $commits = []; public $head = 'head0'; public $counter = 0; public $published = 0; public $race = false; public $fail = false;
    public function __construct(array $files) { $this->files = $files; }
    public function blob(string $bytes): string { $sha = sha1('blob ' . strlen($bytes) . "\0" . $bytes); $this->blobs[$sha] = $bytes; return $sha; }
    public function request(string $url, array $args): array {
        if ($this->fail) { return ['status' => 503, 'body' => []]; }
        $p = substr(parse_url($url, PHP_URL_PATH), strlen('/repos/test/repo')); $method = $args['method']; $body = json_decode($args['body'] ?? '{}', true); $status = 200; $r = [];
        if ($p === '') { $r = ['default_branch' => 'main']; }
        elseif ($p === '/commits/main') { $r = ['sha' => $this->head, 'commit' => ['tree' => ['sha' => 'current']]]; }
        elseif (str_starts_with($p, '/git/trees/') && $method === 'GET') {
            $treeId = substr($p, strlen('/git/trees/')); $prefix = $treeId === 'current' ? '' : substr($treeId, 5) . '/';
            $recursive = str_contains($url, 'recursive=1'); $items = []; $seen = [];
            foreach ($this->files as $path => $bytes) {
                if (!str_starts_with($path, $prefix)) { continue; }
                $relative = substr($path, strlen($prefix));
                if (!$recursive && str_contains($relative, '/')) {
                    $dir = explode('/', $relative)[0];
                    if (!isset($seen[$dir])) { $items[] = ['path' => $dir, 'type' => 'tree', 'sha' => 'sub__' . $prefix . $dir]; $seen[$dir] = true; }
                } else { $items[] = ['path' => $relative, 'type' => 'blob', 'mode' => '100644', 'size' => strlen($bytes), 'sha' => $this->blob($bytes)]; }
            }
            $r = ['tree' => $items, 'truncated' => false];
        } elseif (strpos($p, '/git/blobs/') === 0) { $r = ['content' => base64_encode($this->blobs[substr($p, 11)]), 'encoding' => 'base64']; }
        elseif ($p === '/git/blobs' && $method === 'POST') { $r = ['sha' => $this->blob(base64_decode($body['content']))]; }
        elseif ($p === '/git/trees' && $method === 'POST') { $id = 'tree' . ++$this->counter; $this->trees[$id] = $body['tree']; $r = ['sha' => $id]; }
        elseif ($p === '/git/commits' && $method === 'POST') { $id = 'commit' . ++$this->counter; $this->commits[$id] = $body; $r = ['sha' => $id]; }
        elseif ($p === '/git/refs/heads/main' && $method === 'PATCH') {
            if ($body['force']) { throw new Exception('Force push forbidden'); }
            if ($this->race) { $this->race = false; $this->head = 'external'; $this->files['doc/External.md'] = 'Concurrent change'; return ['status' => 422, 'body' => []]; }
            $commit = $this->commits[$body['sha']];
            if ($commit['parents'][0] !== $this->head) { return ['status' => 422, 'body' => []]; }
            foreach ($this->trees[$commit['tree']] as $entry) { if ($entry['sha'] === null) { unset($this->files[$entry['path']]); } else { $this->files[$entry['path']] = $this->blobs[$entry['sha']]; } }
            $this->head = $body['sha']; $this->published++; $r = ['object' => ['sha' => $this->head]];
        } else { throw new Exception('Unexpected API request ' . $method . ' ' . $p); }
        return ['status' => $status, 'body' => $r];
    }
}
function wp_safe_remote_request($url, $args) { return $GLOBALS['git']->request($url, $args); }
function expect($condition, $message) { if (!$condition) { throw new Exception($message); } }
function repo($readonly = false) { return new GitHubRepository(['url' => 'https://github.com/test/repo', 'root' => '/doc', 'readonly' => $readonly]); }
function run($name, $fn) { $fn(); echo 'PASS ' . $name . "\n"; }
function resetGit($files = null) { $GLOBALS['git'] = new GitService($files ?? ['doc/One.md' => "Intro\n\n# First\nA\n\n## Nested\nB\n\n# Second\nC\n", 'doc/Other.md' => "Unchanged\r\n", 'outside.md' => 'Outside']); }
function rawItem($id, $name, $parent, $body, $type = 1, $extra = '') { return $name . ($body !== '' ? "\n\n" . $body : '') . "\n\nid: $id\nparent_id: $parent\n" . $extra . "type_: $type"; }
function setupDav($write = true) { $r = repo(); $s = new SyncStore('tester', bin2hex(random_bytes(4))); $h = new Joplin($r, $s, $write, '/wiki/joplin/test'); return [$r, $s, $h]; }

run('Markdown sparse merge preserves missing branches and order; fences are opaque', function () {
    $tree = Markdown::parse("First\n\n# A\nOne\n\n# B\n```md\n# Not a heading\n```\n");
    Markdown::merge($tree, Markdown::parse("# A\nMore\n\n# C\nNew"));
    expect(array_column($tree['children'], 'name') === ['A', 'B', 'C'], 'Wrong children');
    expect($tree['children'][0]['text'] === "One\n\nMore", 'Sparse append lost content');
});
run('Git logical hierarchy and direct/aggregate content', function () {
    resetGit(); $r = repo();
    expect($r->call('GetAreas', ['recurse' => true])['return'] === ['/One', '/One/First', '/One/First/Nested', '/One/Second', '/Other'], 'Wrong tree');
    expect($r->call('GetDirectContent', ['area' => '/One'])['return'] === 'Intro', 'Direct included descendants');
    expect(str_contains($r->call('GetAggregatedContent', ['area' => '/One'])['return'], '## Nested'), 'Aggregation missing hierarchy');
});
run('Atomic multi-branch append creates one commit and leaves unrelated bytes intact', function () {
    resetGit(); $r = repo(); $r->call('TryAppendContent', ['area' => '/One', 'content' => "# First\nExtra\n\n# Third\nNew"]);
    expect($GLOBALS['git']->published === 1, 'Not exactly one commit');
    expect($GLOBALS['git']->files['doc/Other.md'] === "Unchanged\r\n", 'Unrelated normalization');
    expect(str_contains($GLOBALS['git']->files['doc/One.md'], '## Nested'), 'Sparse merge deleted branch');
    expect($GLOBALS['git']->files['outside.md'] === 'Outside', 'Escaped root');
});
run('Git concurrent update replays logical mutation without force push', function () {
    resetGit(); $GLOBALS['git']->race = true; $r = repo(); $r->call('TryAppendContent', ['area' => '/One', 'content' => 'Extra']);
    expect(isset($GLOBALS['git']->files['doc/External.md']), 'Lost concurrent change');
    expect(substr_count($GLOBALS['git']->files['doc/One.md'], 'Extra') === 1, 'Replayed twice');
});
run('Read-only provider rejects writes', function () { resetGit(); expect(repo(true)->call('TryDelete', ['area' => '/One'])['return'] === false, 'Read-only mutated'); expect($GLOBALS['git']->published === 0, 'Published'); });
run('Heading move preserves both parents', function () {
    resetGit(); $r = repo(); expect($r->call('TryMoveContent', ['contentAreaToMove' => '/One/First/Nested', 'newParentArea' => '/One/Second'])['return'], 'Move failed');
    $areas = $r->call('GetAreas', ['recurse' => true])['return']; expect(in_array('/One/First', $areas) && in_array('/One/Second/Nested', $areas), 'Wrong reparent');
});
run('Owned resources move; free resources keep ID and relative references are repaired', function () {
    resetGit(['doc/One.md' => "![a](One.Res123.png)\n\n![b](shared.png)", 'doc/One.Res123.png' => 'a', 'doc/shared.png' => 'b', 'doc/Folder/.gitkeep' => '']);
    $r = repo(); $before = $r->call('GetResources', ['area' => '/One'])['return'];
    $result = $r->call('TryMoveContent', ['contentAreaToMove' => '/One', 'newParentArea' => '/[Folder]']);
    expect($result['return'] && isset($GLOBALS['git']->files['doc/Folder/One.Res123.png']), 'Owned resource not moved');
    expect(str_contains($GLOBALS['git']->files['doc/Folder/One.md'], '../shared.png'), 'Free resource reference not repaired');
    expect(!str_contains($GLOBALS['git']->files['doc/Folder/One.md'], 'knowledge-resource:'), 'Canonical link stored physically');
    expect(count($result['resourceIdChanges']) >= 1, 'Changes absent');
});
run('Aggregator deep mounts, overlays and cross-provider moves', function () {
    resetGit(); $agg = new Aggregator(['a' => ['mount' => '/Team/Docs', 'repo' => repo()], 'b' => ['mount' => '/Other', 'repo' => repo()]]);
    expect(in_array('/Team', $agg->call('GetAreas', ['recurse' => true])['return']), 'Missing synthetic parent');
    expect(!$agg->call('TryMoveContent', ['contentAreaToMove' => '/Team/Docs/One', 'newParentArea' => '/Other'])['return'], 'Cross-provider move allowed');
    $overlay = new Aggregator(['a' => ['mount' => '/', 'repo' => repo()], 'b' => ['mount' => '/', 'repo' => repo()]]);
    expect(!$overlay->call('TryDelete', ['area' => '/One'])['return'], 'Ambiguous write allowed');
});
run('Joplin repeated GET and info.json stay byte stable; resource times are numeric', function () {
    resetGit(['doc/One.md' => '![x](One.Res123.png)', 'doc/One.Res123.png' => 'image']); [$r, $s, $h] = setupDav();
    $id = substr(hash('sha256', '1:/One'), 0, 32);
    $a = $h->handle('GET', '/' . $id . '.md', '', []); $b = $h->handle('GET', '/' . $id . '.md', '', []);
    expect($a[2] === $b[2] && !str_ends_with($a[2], "\n"), 'Unstable note');
    $rid = array_key_first($s->state['resources']); $meta = $h->handle('GET', '/' . $rid . '.md', '', [])[2];
    expect(preg_match('/blob_updated_time: \d{13}/', $meta) === 1, 'Wrong resource time');
    expect($h->handle('GET', '/info.json', '', [])[2] === $h->handle('GET', '/info.json', '', [])[2], 'Unstable info');
});
run('Joplin DELETE suppresses projection without deleting knowledge or resource', function () {
    resetGit(['doc/One.md' => '![x](r.png)', 'doc/r.png' => 'x']); [$r, $s, $h] = setupDav(); $h->project();
    $id = substr(hash('sha256', '1:/One'), 0, 32); $resource = array_key_first($s->state['resources']);
    expect($h->handle('DELETE', '/' . $id . '.md', '', [])[0] === 204, 'Delete failed');
    $h->handle('DELETE', '/.resource/' . $resource, '', []);
    expect(isset($GLOBALS['git']->files['doc/One.md'], $GLOBALS['git']->files['doc/r.png']), 'Source destroyed');
    expect($h->handle('GET', '/' . $id . '.md', '', [])[0] === 404, 'Suppression lost');
});
run('Joplin child before notebook is materialized when parent arrives', function () {
    resetGit(['doc/.gitkeep' => '']); [$r, $s, $h] = setupDav(); $note = str_repeat('a', 32); $folder = str_repeat('b', 32);
    expect($h->handle('PUT', '/' . $note . '.md', rawItem($note, 'Note', $folder, 'Body'), [])[0] === 201, 'Pending not accepted');
    expect(count($s->state['pending']) === 1, 'Pending lost');
    $h->handle('PUT', '/' . $folder . '.md', rawItem($folder, 'Folder', '', '', 2), []);
    expect(isset($GLOBALS['git']->files['doc/Folder/Note.md']) && !$s->state['pending'], 'Pending not materialized');
});
run('Joplin identical PUT is idempotent and note links are not resources', function () {
    resetGit(['doc/One.md' => 'Body', 'doc/Other.md' => 'Other']); [$r, $s, $h] = setupDav(); $h->project();
    $one = substr(hash('sha256', '1:/One'), 0, 32); $other = substr(hash('sha256', '1:/Other'), 0, 32);
    $raw = $h->handle('GET', '/' . $one . '.md', '', [])[2]; $h->handle('PUT', '/' . $one . '.md', $raw, []);
    expect($GLOBALS['git']->published === 0, 'Identical write committed');
    $h->handle('PUT', '/' . $one . '.md', rawItem($one, 'One', '', '[Other](:/' . $other . ')'), []);
    expect(str_contains($GLOBALS['git']->files['doc/One.md'], ':/' . $other), 'Note link converted');
});
run('Joplin resource upload before note uses canonical mapping; failed replacement never succeeds', function () {
    resetGit(['doc/One.md' => 'Body']); [$r, $s, $h] = setupDav(); $id = str_repeat('c', 32); $note = substr(hash('sha256', '1:/One'), 0, 32);
    $h->handle('PUT', '/.resource/' . $id, 'image', []);
    $h->handle('PUT', '/' . $id . '.md', rawItem($id, 'Image', '', '', 4, "mime: image/png\nfilename: \n"), []);
    $h->handle('PUT', '/' . $note . '.md', rawItem($note, 'One', '', '![x](:/' . $id . ')'), []);
    expect(isset($s->state['resources'][$id]), 'Resource mapping absent');
    $names = array_keys($GLOBALS['git']->files); expect((bool) preg_grep('/One\.Res\d+\.png$/', $names), 'Pasted resource is not document owned');
    $GLOBALS['git']->fail = true; $failed = false;
    try { $h->handle('PUT', '/.resource/' . $id, 'updated', []); } catch (Failure $e) { $failed = true; }
    expect($failed, 'False success');
});
run('Joplin conflict rebind preserves source and changes identity', function () {
    resetGit(['doc/One.md' => 'Body']); [$r, $s, $h] = setupDav(); $old = substr(hash('sha256', '1:/One'), 0, 32); $new = str_repeat('d', 32);
    $h->handle('DELETE', '/' . $old . '.md', '', []); $h->handle('PUT', '/' . $new . '.md', rawItem($new, 'One', '', 'Body'), []);
    expect(isset($s->state['items'][$new]) && !isset($s->state['items'][$old]), 'Rebind failed');
    expect(count($GLOBALS['git']->files) === 1, 'Duplicated content');
});
run('JWT validates signature, current permissions and token revocation', function () {
    $GLOBALS['options']['kornsw_kr_settings'] = ['jwt_ttl' => 600, 'permissions' => ['editor' => ['page' => 1, 'ujmw' => 2]]];
    $token = Auth::issue()['token']; $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    expect(Auth::bearer()['user']->ID === 7, 'Valid JWT rejected');
    $GLOBALS['options']['kornsw_kr_settings']['permissions']['editor']['ujmw'] = 0;
    expect(Auth::permission('ujmw', Auth::bearer()['user']) === 0, 'Stale role permission');
    $GLOBALS['usermeta'][7]['kornsw_kr_token_version'] = 1; $failed = false;
    try { Auth::bearer(); } catch (Failure $e) { $failed = true; }
    expect($failed, 'Revoked token accepted');
});
run('Read-only Joplin permits locks and rejects changed source content', function () {
    resetGit(['doc/One.md' => 'Body']); [$r, $s, $h] = setupDav(false); $id = substr(hash('sha256', '1:/One'), 0, 32);
    expect($h->handle('PUT', '/locks/client.json', '{}', [])[0] === 201, 'Lock blocked');
    $failed = false; try { $h->handle('PUT', '/' . $id . '.md', rawItem($id, 'One', '', 'Changed'), []); } catch (Failure $e) { $failed = $e->status === 403; }
    expect($failed && $GLOBALS['git']->published === 0, 'Read-only source changed');
});

run('Joplin owned resource identity survives moving and renaming a note', function () {
    resetGit(['doc/One.md' => '![x](One.Res123.png)', 'doc/One.Res123.png' => 'image', 'doc/Folder/.gitkeep' => '']);
    [$r, $s, $h] = setupDav(); $h->project(); $note = substr(hash('sha256', '1:/One'), 0, 32); $folder = substr(hash('sha256', '2:/[Folder]'), 0, 32);
    $rid = array_key_first($s->state['resources']); $previous = $s->state['resources'][$rid]['rid'];
    $h->handle('PUT', '/' . $note . '.md', rawItem($note, 'Renamed', $folder, '![x](:/' . $rid . ')'), []);
    expect(isset($GLOBALS['git']->files['doc/Folder/Renamed.md'], $GLOBALS['git']->files['doc/Folder/Renamed.Res123.png']), 'Note or owned resource not moved');
    expect(count($s->state['resources']) === 1 && $s->state['resources'][$rid]['rid'] !== $previous, 'Resource identity not rebound');
    expect(str_contains($h->handle('GET', '/' . $note . '.md', '', [])[2], ':/' . $rid), 'Joplin ID changed');
});
run('Joplin folder move preserves all child note identities', function () {
    resetGit(['doc/A/One.md' => 'Body', 'doc/B/.gitkeep' => '']); [$r, $s, $h] = setupDav(); $h->project();
    $a = substr(hash('sha256', '2:/[A]'), 0, 32); $b = substr(hash('sha256', '2:/[B]'), 0, 32); $note = substr(hash('sha256', '1:/[A]/One'), 0, 32);
    $h->handle('PUT', '/' . $a . '.md', rawItem($a, 'A', $b, '', 2), []);
    expect(isset($GLOBALS['git']->files['doc/B/A/One.md']), 'Folder move failed');
    expect($s->state['items'][$note]['area'] === '/[B]/[A]/One', 'Child ID lost');
});
run('Pending resource note retains raw body before metadata/blob arrive', function () {
    resetGit(['doc/One.md' => 'Before']); [$r, $s, $h] = setupDav(); $note = substr(hash('sha256', '1:/One'), 0, 32); $resource = str_repeat('e', 32);
    $raw = rawItem($note, 'One', '', '![Pending](:/' . $resource . ')');
    $h->handle('PUT', '/' . $note . '.md', $raw, []);
    expect($h->handle('GET', '/' . $note . '.md', '', [])[2] === $raw, 'Pending body overwritten');
    $h->handle('PUT', '/' . $resource . '.md', rawItem($resource, 'Image', '', '', 4, "mime: image/png\nfilename: photo.png\n"), []);
    $h->handle('PUT', '/.resource/' . $resource, 'blob', []);
    expect(!$s->state['pending'] && str_contains($GLOBALS['git']->files['doc/One.md'], 'photo.png'), 'Pending resource not applied');
});
run('Sync state survives reopening and separates users and profile IDs', function () {
    $profile = 'unicode-ä'; $one = new SyncStore('üser', $profile); $one->state['pending']['sentinel'] = 'persisted'; $one->save(); unset($one);
    $two = new SyncStore('üser', $profile); expect($two->state['pending']['sentinel'] === 'persisted', 'State lost'); unset($two);
    $three = new SyncStore('other', $profile); expect(!$three->state['pending'], 'Profiles not isolated');
    foreach (glob(KORNSW_KR_STATE_DIR . '/*.state') as $file) { expect(str_starts_with(file_get_contents($file), 'enc:'), 'State unencrypted'); }
});
run('UJMW fault and required field validation; passthrough never silently anonymous', function () {
    $r = new KornSW\KnowledgeRepo\RemoteRepository(['url' => 'https://remote.invalid/IKnowledgeRepository', 'token' => '[PASS-TROUGH]']);
    $failed = false; try { $r->call('GetAreas'); } catch (Failure $e) { $failed = $e->status === 403; }
    expect($failed, 'Pass-through downgraded');
    $failed = false; try { Contract::arguments('GetAreas', ['recurse' => 'false']); } catch (Failure $e) { $failed = true; }
    expect($failed, 'Typed parameter accepted as wrong type');
});
echo "ALL REGRESSIONS PASSED\n";
