<?php
declare(strict_types=1);
namespace App\Formats;
use App\Problem;
final class Swiss {
    /** Bounded minimum-cost matching; repeated opponents require explicit consent. */
    public static function pair(array $rows,array $history,int $round,bool $allowRematches=false,bool $randomFirst=false): array {
        if ($randomFirst && $round===1) shuffle($rows);
        else usort($rows,fn($a,$b)=>($b['wins']<=>$a['wins']) ?: ($a['losses']<=>$b['losses']) ?: (($b['buchholz']??0)<=>($a['buchholz']??0)) ?: ($a['seed']<=>$b['seed']));
        $seen=[];$byes=[];
        foreach ($history as $m) {
            if ($m['is_bye']) { if ($m['winner_id']) $byes[(int)$m['winner_id']]=true; continue; }
            if ($m['team_a_id'] && $m['team_b_id']) $seen[self::key((int)$m['team_a_id'],(int)$m['team_b_id'])]=true;
        }
        $bye=null;
        if (count($rows)%2) {
            for ($i=count($rows)-1;$i>=0;$i--) if (!isset($byes[$rows[$i]['team_id']])) { $bye=$rows[$i]['team_id']; array_splice($rows,$i,1); break; }
            if ($bye===null) { $bye=array_pop($rows)['team_id']; }
        }
        $best=null;$bestCost=PHP_INT_MAX;$visits=0;
        $search=function(array $left,array $pairs,int $cost) use (&$search,&$best,&$bestCost,&$visits,$seen,$round,$allowRematches): void {
            if (++$visits>100000 || $cost>=$bestCost) return;
            if (!$left) { $best=$pairs;$bestCost=$cost;return; }
            $a=array_shift($left);$choices=[];
            foreach ($left as $i=>$b) {
                $repeat=isset($seen[self::key((int)$a['team_id'],(int)$b['team_id'])]);
                if ($repeat && !$allowRematches) continue;
                $weight=($repeat?100000:0)+abs($a['wins']-$b['wins'])*100+abs($a['losses']-$b['losses'])*50;
                if ($round===1) $weight+=abs($i-(int)(count($left)/2));
                $choices[]=[$i,$weight];
            }
            usort($choices,fn($x,$y)=>$x[1]<=>$y[1]);
            foreach ($choices as [$i,$weight]) { $rest=$left;$b=$rest[$i];array_splice($rest,$i,1);$search($rest,[...$pairs,[$a['team_id'],$b['team_id']]],$cost+$weight); }
        };
        $search($rows,[],0);
        if ($best===null) throw new Problem('瑞士轮配对冲突：无法在搜索范围内找到无重复对阵。可勾选“允许重复交手”重试，或手动调整本轮对阵。',409);
        $nodes=[];foreach ($best as $i=>[$a,$b]) $nodes[]=['key'=>"S$round-$i",'bracket'=>'swiss','round'=>$round,'a'=>$a,'b'=>$b];
        if ($bye!==null) $nodes[]=['key'=>"S$round-BYE",'bracket'=>'swiss','round'=>$round,'a'=>$bye,'b'=>null];
        return ['nodes'=>$nodes,'rematches'=>$bestCost>=100000];
    }
    private static function key(int $a,int $b): string { return min($a,$b).':'.max($a,$b); }
}
