<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\{Score,Problem,StandingsService,MatchService};
use App\Formats\{Elimination,RoundRobin,Swiss};
$checks=0;
function ok(bool $condition,string $message): void { global $checks;$checks++;if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn,string $message): void { try { $fn(); } catch (Problem $e) { ok(true,$message);return; }ok(false,$message); }
function simulate(array $nodes,bool $lowerWins=false): array {
    $played=[];$losses=[];$count=0;
    foreach ($nodes as $n) {
        $resolve=function ($slot) use (&$played) { if (!is_string($slot)) return $slot;[$type,$key]=explode(':',$slot);return $played[$key][$type]; };
        $a=$resolve($n['a']);$b=$resolve($n['b']);
        if ($n['key']==='RESET' && !$lowerWins) continue;
        $w=$n['key']==='GF'&&$lowerWins ? $b : ($a?:$b);$l=$a&&$b ? ($w===$a?$b:$a) : null;
        if ($l) { $losses[$l]=($losses[$l]??0)+1;$count++; }
        $played[$n['key']]=['winner'=>$w,'loser'=>$l];
    }
    return [$played,$losses,$count];
}
try {
    foreach ([8,16] as $n) { [$played,$losses,$count]=simulate(Elimination::generate(range(1,$n)));ok($count===$n-1,"$n single match count");ok(count($losses)===$n-1,"$n single eliminated"); }
    for ($n=2;$n<=32;$n++) {
        [$p,$l,$c]=simulate(Elimination::generate(range(1,$n)));ok($c===$n-1,"byes $n");ok(end($p)['winner']===1,"seed #1 champion $n");
        foreach ([false,true] as $resetLoss) {
            [$p,$l,$c]=simulate(Elimination::generate(range(1,$n),true,false,true),$resetLoss);$champion=end($p)['winner'];ok(count($l)===$n-1+($resetLoss?1:0),"double loss population $n");
            foreach ($l as $id=>$loss) ok($id===$champion?$loss<2:$loss===2,"double elimination loss invariant n=$n team=$id losses=$loss");
            ok($c===$n*2-2+($resetLoss?1:0),"double number of real matches $n");
        }
    }
    foreach ([4,5,8] as $n) foreach ([false,true] as $double) {
        $nodes=RoundRobin::generate(range(1,$n),$double);ok(count($nodes)===$n*($n-1)/2*($double?2:1),'round robin count');$pairs=[];$rounds=[];
        foreach ($nodes as $m) { $key=min($m['a'],$m['b']).':'.max($m['a'],$m['b']);$pairs[$key]=($pairs[$key]??0)+1;foreach (['a','b'] as $slot) { ok(!isset($rounds[$m['round']][$m[$slot]]),'no duplicate team in round');$rounds[$m['round']][$m[$slot]]=true; } }
        foreach ($pairs as $count) ok($count===($double?2:1),'each pair frequency');
    }
    $target=13;$winBy=['enabled'=>true,'mode'=>'win_by','win_by'=>2];$blocks=['enabled'=>true,'mode'=>'blocks','block_size'=>3];
    foreach ([[13,0],[13,11],[14,12],[16,14]] as [$a,$b])ok(Score::game($a,$b,13,$winBy)===0,'win by overtime');
    foreach ([[13,11],[16,12],[16,14],[19,15],[19,17],[22,20]] as [$a,$b])ok(Score::game($a,$b,13,$blocks)===0,'CS2 MR3');
    foreach ([[13,12],[14,12],[16,11],[16,15],[19,14],[20,18]] as [$a,$b])rejects(fn()=>Score::game($a,$b,13,$blocks),'reject invalid MR3');
    rejects(fn()=>Score::game(14,3,13,['enabled'=>false]),'no score after regular finish');
    $s=Score::series([['a'=>13,'b'=>4],['a'=>8,'b'=>13],['a'=>13,'b'=>11]],3,13,$blocks);ok($s['a']===2&&$s['b']===1&&$s['winner']===0,'BO3 2-1');
    rejects(fn()=>Score::series([['a'=>13,'b'=>1],['a'=>13,'b'=>1],['a'=>13,'b'=>1]],3,13,$blocks),'stop series after winner');
    $history=[];$rows=[];for($i=1;$i<=16;$i++)$rows[]=['team_id'=>$i,'seed'=>$i,'name'=>'T'.$i];$config=['qualify_wins'=>3,'eliminate_losses'=>3,'win_points'=>1,'loss_points'=>0];$counts=[];
    for ($round=1;$round<=5;$round++) {
        $rank=StandingsService::calculate($rows,$history,$config);$active=array_values(array_filter($rank,fn($r)=>!$r['qualified']&&!$r['eliminated']));$pair=Swiss::pair($active,$history,$round);
        foreach($pair['nodes'] as $i=>$m){$a=$m['a'];$b=$m['b'];$key=min($a,$b).':'.max($a,$b);ok(!isset($counts[$key]),'Swiss no repeat');$counts[$key]=1;$history[]=['team_a_id'=>$a,'team_b_id'=>$b,'winner_id'=>($round+$i)%2?$a:$b,'is_bye'=>0,'is_active'=>1,'status'=>'completed','score_a'=>null,'score_b'=>null];}
    }
    $rank=StandingsService::calculate($rows,$history,$config);ok(count(array_filter($rank,fn($r)=>$r['qualified']))===8,'16 Swiss produces eight qualifiers');ok(count(array_filter($rank,fn($r)=>$r['eliminated']))===8,'16 Swiss eight eliminated');
    $odd=array_map(fn($i)=>['team_id'=>$i,'seed'=>$i,'wins'=>0,'losses'=>0],range(1,5));$p=Swiss::pair($odd,[],1);ok(count($p['nodes'])===3,'odd Swiss bye');
    $two=[['team_id'=>1,'seed'=>1,'wins'=>0,'losses'=>0],['team_id'=>2,'seed'=>2,'wins'=>0,'losses'=>0]];$history=[['team_a_id'=>1,'team_b_id'=>2,'winner_id'=>1,'is_bye'=>0]];
    rejects(fn()=>Swiss::pair($two,$history,2),'Swiss conflict reported');ok(Swiss::pair($two,$history,2,true)['rematches'],'Swiss rematch explicit');
    $m=['is_active'=>1,'status'=>'scheduled','scheduled_at'=>'2000-01-01 00:00:00','team_a_id'=>1,'team_b_id'=>2];ok(MatchService::effectiveStatus($m)==='live','scheduled auto live');$m['status']='postponed';ok(MatchService::effectiveStatus($m)==='postponed','manual postponed wins');
    echo "PASS $checks assertions\n";
} catch(Throwable $e) { fwrite(STDERR,"FAIL: ".$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1); }
