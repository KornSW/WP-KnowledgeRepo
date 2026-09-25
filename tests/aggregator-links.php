<?php
/** Standalone suite: PHP 8.1+, no WordPress needed. */
error_reporting(E_ALL);
set_error_handler(static function ($s, $m, $f, $l) { throw new ErrorException($m, 0, $s, $f, $l); });
require __DIR__ . '/../kornsw-knowledgerepo/includes/Contract.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/Aggregator.php';
require __DIR__ . '/../kornsw-knowledgerepo/includes/ConfiguredRepository.php';
use KornSW\KnowledgeRepo\{Aggregator, ConfiguredRepository, TreeRepository, Failure};
function expect($c, $m) { if (!$c) { throw new Exception($m); } }
final class Fake extends TreeRepository {
    public $calls = [];
    public function __construct(array $tree) { $this->nodes = ['/' => ['name'=>'', 'level'=>1, 'text'=>'']] + $tree; }
    protected function load(): void {}
    public function call(string $m, array $a = []): array { $this->calls[] = $m . ':' . json_encode($a); return parent::call($m, $a); }
}
$n = static function (string $name, int $level = 2) { return ['name'=>$name, 'level'=>$level, 'text'=>'Text ' . $name]; };
// Provider at /Docs with unsorted children, a deep mount /Docs/Mid and root mounts Zeta, Alpha.
$docs = new Fake(['/Zulu'=>$n('Zulu'), '/Bravo'=>$n('Bravo'), '/Bravo/Deep'=>$n('Deep'), '/Lima'=>$n('Lima')]);
$mid = new Fake(['/X'=>$n('X')]); $zeta = new Fake(['/Z1'=>$n('Z1')]); $alpha = new Fake(['/A1'=>$n('A1')]);
$agg = new Aggregator(['d'=>['mount'=>'/Docs','repo'=>$docs], 'm'=>['mount'=>'/Docs/Mid','repo'=>$mid], 'z'=>['mount'=>'/Zeta','repo'=>$zeta], 'a'=>['mount'=>'/Alpha','repo'=>$alpha]], true);
expect($agg->call('GetAreas', ['startArea'=>'/'])['return'] === ['/Alpha', '/Docs', '/Zeta'], 'Root not alphabetical');
// Unsorted provider list: mount goes before the first sibling sorting after it (Zulu).
$children = $agg->call('GetAreas', ['startArea'=>'/Docs'])['return'];
expect($children === ['/Docs/Mid', '/Docs/Zulu', '/Docs/Bravo', '/Docs/Lima'], 'Deep mount insertion: ' . json_encode($children));
expect($agg->call('GetDirectContent', ['area'=>'/Docs/Bravo/Deep'])['return'] === 'Text Deep', 'Deep read');
expect(!array_filter($docs->calls, static function ($c) { return strpos($c, '"recurse":true') !== false; }), 'Non-recursive navigation used a recursive provider call');
expect(!$zeta->calls || !array_filter($zeta->calls, static function ($c) { return strpos($c, 'GetAreas') === 0 && strpos($c, '"startArea":"\/"') === false; }), 'Unrelated source walked');
try { $agg->call('GetAreaName', ['area'=>'/Docs/Nope']); expect(false, 'Missing area accepted'); } catch (Failure $e) { expect($e->status === 404, 'Missing area status'); }
$all = $agg->call('GetAreas', ['recurse'=>true])['return'];
expect($all[0] === '/Alpha' && in_array('/Docs/Mid/X', $all, true) && in_array('/Docs/Bravo/Deep', $all, true), 'Recursive walk');
expect(count(array_filter($docs->calls, static function ($c) { return strpos($c, '"recurse":true') !== false; })) === 1, 'Recursive walk should use one provider call');
expect($agg->call('GetAreasByKeyword', ['keyword'=>'Deep'])['return'] === ['/Docs/Bravo/Deep'], 'Keyword search');
// Failing source: tolerant listing keeps the other sources.
$broken = new class implements KornSW\KnowledgeRepo\Repository { public function call(string $m, array $a = []): array { throw new Failure('down', 503); } };
$tol = new Aggregator(['a'=>['mount'=>'/Alpha','repo'=>$alpha], 'b'=>['mount'=>'/Broken','repo'=>$broken]], true);
expect($tol->call('GetAreas', ['startArea'=>'/'])['return'] === ['/Alpha', '/Broken'], 'Tolerant root');
expect($tol->call('GetAreas', ['startArea'=>'/Broken'])['return'] === [] && $tol->errors(), 'Tolerant error');
$strict = new Aggregator(['a'=>['mount'=>'/Alpha','repo'=>$alpha], 'b'=>['mount'=>'/Broken','repo'=>$broken]]);
try { $strict->call('GetAreas', ['recurse'=>true]); expect(false, 'Strict partial tree'); } catch (Failure $e) { expect($e->status === 503, 'Strict status'); }
// Tagged link list.
$links = ['type'=>'links', 'label'=>'', 'alias'=>'Links', 'links'=>[
    ['url'=>'https://a.example/', 'title'=>'A', 'icon'=>'', 'tags'=>['Tools', '2024']],
    ['url'=>'https://b.example/', 'title'=>'B', 'icon'=>'', 'tags'=>['Tools']],
    ['url'=>'https://c.example/', 'title'=>'C', 'icon'=>'', 'tags'=>[]]]];
$r = new ConfiguredRepository($links);
expect($r->call('GetAreas', ['recurse'=>true])['return'] === ['/Links', '/Links/Links', '/Links/2024', '/Links/Tools'], 'Tag areas: ' . json_encode($r->call('GetAreas', ['recurse'=>true])['return']));
expect($r->call('GetAreaCapabilities', ['area'=>'/Links'])['contentLevel'] === 1, 'Folder level');
$tools = $r->call('GetDirectContent', ['area'=>'/Links/Tools'])['return'];
expect(strpos($tools, '[A]') !== false && strpos($tools, '[B]') !== false && strpos($tools, '[C]') === false, 'Tag filter');
expect(strpos($r->call('GetDirectContent', ['area'=>'/Links/Links'])['return'], '[C]') !== false, 'Untagged page');
$plain = new ConfiguredRepository(['type'=>'links', 'label'=>'', 'alias'=>'Links', 'links'=>[['url'=>'https://c.example/', 'title'=>'C', 'icon'=>'']]]);
expect($plain->call('GetAreas', ['recurse'=>true])['return'] === ['/Links'] && $plain->call('GetAreaCapabilities', ['area'=>'/Links'])['contentLevel'] === 2, 'Untagged list shape changed');
echo "PASS Lazy aggregator, root/mount ordering, fault isolation and tagged link lists\n";
