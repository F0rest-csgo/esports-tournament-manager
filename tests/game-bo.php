<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\{GameRules,BoPolicy,Input,Score,Problem};
$checks=0;
function ok(bool $v,string $message): void {global $checks;$checks++;if (!$v) throw new RuntimeException($message);}
function rejected(callable $fn,string $message): void {try {$fn();}catch (Problem $e){ok(true,$message);return;}ok(false,$message);}
try {
    $val=GameRules::preset('valorant');$cs=GameRules::preset('cs2');
    ok(!isset($val['block_size']),'VALORANT has no irrelevant half-block parameter');
    foreach ([[13,0],[13,11],[14,12],[15,13],[16,14],[22,20]] as [$a,$b]) ok(Score::game($a,$b,$val['target'],$val)===0,'VALORANT official terminal score');
    foreach ([[13,12],[14,13],[16,13],[16,15],[12,12]] as [$a,$b]) rejected(fn()=>Score::game($a,$b,$val['target'],$val),'VALORANT invalid terminal score');
    foreach ([[13,0],[13,11],[16,12],[16,13],[16,14],[19,15],[19,17],[22,20]] as [$a,$b]) ok(Score::game($a,$b,$cs['target'],$cs)===0,'CS2 MR3 terminal score');
    foreach ([[13,12],[14,12],[15,13],[16,15],[17,15],[19,14],[19,18]] as [$a,$b]) rejected(fn()=>Score::game($a,$b,$cs['target'],$cs),'CS2 invalid terminal score');
    rejected(fn()=>GameRules::preset('other'),'unsupported game rejected');
    $c=Input::stageConfig(['base_bo'=>1,'bo_policy'=>'decisive','decisive_bo'=>3]);
    $node=['a'=>1,'b'=>2,'bracket'=>'swiss','round'=>3];
    foreach ([[0,0,0,0,1],[1,0,1,0,1],[1,1,1,1,1],[2,0,2,0,3],[2,1,1,1,3],[0,2,0,2,3],[1,2,1,1,3],[2,2,2,2,3]] as [$aw,$al,$bw,$bl,$bo]) {
        $r=[1=>['wins'=>$aw,'losses'=>$al],2=>['wins'=>$bw,'losses'=>$bl]];
        ok(BoPolicy::resolve('swiss',$node,$c,$r)===$bo,'Swiss boundary uses either participant before the match');
    }
    $records=[1=>['wins'=>2,'losses'=>0],2=>['wins'=>2,'losses'=>0]];
    $override=$c;$override['round_bo']=['swiss:3'=>5];ok(BoPolicy::resolve('swiss',$node,$override,$records)===5,'round override takes precedence');
    $uniform=Input::stageConfig(['base_bo'=>5,'bo_policy'=>'uniform']);ok(BoPolicy::resolve('swiss',$node,$uniform,$records)===5,'stage uniform BO5');
    ok(BoPolicy::resolve('single',['bracket'=>'upper','round'=>1],$c)===3,'single elimination games BO3');
    ok(BoPolicy::resolve('single',['bracket'=>'third','round'=>1],$c)===1,'third place is a placement game');
    foreach (['upper'=>1,'lower'=>3,'final'=>3] as $bracket=>$bo) ok(BoPolicy::resolve('double',['bracket'=>$bracket,'round'=>1],$c)===$bo,'double elimination boundary');
    ok(BoPolicy::config([],5)['base_bo']===5,'legacy tournament BO fallback retained');
    ok(BoPolicy::config(['base_bo'=>1],5)['base_bo']===1,'explicit stage BO wins over legacy fallback');
    $teams=[['team_id'=>1,'seed'=>1],['team_id'=>2,'seed'=>2]];
    $history=[['team_a_id'=>1,'team_b_id'=>2,'winner_id'=>1,'round_number'=>1,'is_active'=>1,'is_bye'=>0,'status'=>'completed'],['team_a_id'=>1,'team_b_id'=>2,'winner_id'=>1,'round_number'=>2,'is_active'=>1,'is_bye'=>0,'status'=>'completed']];
    $before=BoPolicy::records($teams,$history,2,$c);ok($before[1]['wins']===1&&$before[2]['losses']===1,'historical BO reasons do not use current-round or future results');
    rejected(fn()=>Input::stageConfig(['base_bo'=>2]),'invalid stage BO rejected');
    echo "PASS $checks game/BO assertions\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");exit(1);}
