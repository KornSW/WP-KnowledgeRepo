<?php
error_reporting(E_ALL);
set_error_handler(static function ($s, $m, $f, $l) { if (error_reporting() & $s) { throw new ErrorException($m, 0, $s, $f, $l); } });
function get_terms($args) { return [(object) ['term_id' => 1, 'parent' => 0, 'name' => 'Parent'], (object) ['term_id' => 2, 'parent' => 1, 'name' => 'Child']]; }
function is_wp_error($v) { return false; }
function get_posts($args) {
    if ($args['post_status'] !== 'publish' || $args['has_password'] !== false || $args['tax_query'][0]['include_children'] !== false) { throw new Exception('Unsafe post query'); }
    return [(object) ['ID' => 5, 'post_title' => 'Shared', 'post_content' => '<h2>Heading</h2><p>Hello <strong>world</strong>.</p><script>alert(1)</script>']];
}
function attachment_url_to_postid($url) { return 0; }
require __DIR__ . '/../kornsw-knowledgerepo/includes/Contract.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/WordPressRepository.php';
$r = new KornSW\KnowledgeRepo\WordPressRepository(['categories' => [1, 2]]);
$areas = $r->call('GetAreas', ['recurse' => true])['return'];
if ($areas !== ['/Parent~1', '/Parent~1/Shared~5', '/Parent~1/Child~2', '/Parent~1/Child~2/Shared~5']) { throw new Exception('Category hierarchy or multiple assignment broken'); }
$text = $r->call('GetDirectContent', ['area' => '/Parent~1/Shared~5'])['return'];
if (!str_contains($text, '**world**') || str_contains($text, 'alert')) { throw new Exception('HTML export unsafe'); }
if ($r->call('TryReplace', ['area' => '/Parent~1/Shared~5', 'newContent' => 'changed'])['return'] !== false) { throw new Exception('WordPress write allowed'); }
echo "PASS WordPress category hierarchy, multiple assignment, published-only query, HTML export and read-only behavior\n";
