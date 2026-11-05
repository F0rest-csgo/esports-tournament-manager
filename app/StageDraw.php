<?php
declare(strict_types=1);
namespace App;
use App\Formats\Elimination;
final class StageDraw {
    /** Match the stronger half to the weaker half, avoiding former group opponents where possible. */
    public static function pairs(array $teams,bool $elimination=true): array {
        $size=$elimination?2:count($teams)+(count($teams)%2);
        if ($elimination) while ($size<count($teams)) $size*=2;
        $slots=array_pad(array_values($teams),$size,null);$half=intdiv($size,2);$byes=$size-count($teams);$cost=[];
        for ($i=0;$i<$half;$i++) for ($j=0;$j<$half;$j++) {
            $a=$slots[$i];$b=$slots[$half+$j];
            $same=$a && $b && !empty($a['source_group_id']) && $a['source_group_id']===$b['source_group_id'];
            $wrongBye=($i<$byes)!==($b===null);
            $cost[$i][$j]=($wrongBye?1000000000000:0)+($same?100000000:0)+($j-($half-1-$i))**2;
        }
        // Hungarian assignment: deterministic O(n^3), including a full 128-team field.
        $u=$v=$p=$way=array_fill(0,$half+1,0);
        for ($i=1;$i<=$half;$i++) {
            $p[0]=$i;$j0=0;$min=array_fill(0,$half+1,PHP_INT_MAX);$used=array_fill(0,$half+1,false);
            do {
                $used[$j0]=true;$i0=$p[$j0];$delta=PHP_INT_MAX;$j1=0;
                for ($j=1;$j<=$half;$j++) if (!$used[$j]) {
                    $cur=$cost[$i0-1][$j-1]-$u[$i0]-$v[$j];
                    if ($cur<$min[$j]) { $min[$j]=$cur;$way[$j]=$j0; }
                    if ($min[$j]<$delta) { $delta=$min[$j];$j1=$j; }
                }
                for ($j=0;$j<=$half;$j++) if ($used[$j]) { $u[$p[$j]]+=$delta;$v[$j]-=$delta; } else $min[$j]-=$delta;
                $j0=$j1;
            } while ($p[$j0]!==0);
            do { $j1=$way[$j0];$p[$j0]=$p[$j1];$j0=$j1; } while ($j0!==0);
        }
        $opponents=[];for ($j=1;$j<=$half;$j++) $opponents[$p[$j]-1]=$half+$j-1;
        $order=$elimination?array_values(array_filter(Elimination::seeds($size),fn($seed)=>$seed<=$half)):range(1,$half);
        $pairs=[];foreach ($order as $seed) $pairs[]=['a'=>$slots[$seed-1]['team_id']??null,'b'=>$slots[$opponents[$seed-1]]['team_id']??null];
        return $pairs;
    }
    public static function validatePairs(mixed $input,array $ids,bool $elimination): array {
        $size=$elimination?2:count($ids)+(count($ids)%2);if ($elimination) while ($size<count($ids)) $size*=2;
        if (!is_array($input) || count($input)!==intdiv($size,2)) throw new Problem('首轮对阵场数与参赛队伍数不符。');
        $pairs=[];$used=[];
        foreach ($input as $pair) {
            if (!is_array($pair)) throw new Problem('首轮对阵格式无效。');$row=[];
            foreach (['a','b'] as $slot) {
                $id=empty($pair[$slot])?null:Input::int($pair[$slot],1,PHP_INT_MAX);
                if ($id!==null && (!in_array($id,$ids,true) || isset($used[$id]))) throw new Problem('首轮对阵必须包含所有参赛队伍，每队只能出现一次。');
                if ($id!==null) $used[$id]=true;$row[$slot]=$id;
            }
            if (!$row['a'] && !$row['b']) throw new Problem('同一场比赛不能双方都轮空。');$pairs[]=$row;
        }
        if (count($used)!==count($ids)) throw new Problem('首轮对阵必须包含全部参赛队伍。');return $pairs;
    }
    public static function seedOrder(array $pairs): array {
        $size=count($pairs)*2;$order=Elimination::seeds($size);$seeds=array_fill(0,$size,null);
        foreach ($pairs as $i=>$pair) { $seeds[$order[$i*2]-1]=$pair['a'];$seeds[$order[$i*2+1]-1]=$pair['b']; }return $seeds;
    }
    public static function groups(array $teams,int $count): array {
        if ($count<1 || $count>intdiv(count($teams),2)) throw new Problem('小组数量过多，每组至少两队。');
        $groups=[];for ($g=0;$g<$count;$g++) $groups[]=['name'=>'第 '.($g+1).' 组','teams'=>[]];
        $origins=[];
        foreach (array_chunk($teams,$count) as $row=>$tier) {
            $available=range(0,$count-1);if ($row%2) $available=array_reverse($available);
            foreach ($tier as $team) {
                $chosen=$available[0];$best=PHP_INT_MAX;
                foreach ($available as $index) { $cost=count($groups[$index]['teams'])*1000+($origins[$index][$team['source_group_id']??0]??0);if ($cost<$best) { $best=$cost;$chosen=$index; } }
                $groups[$chosen]['teams'][]=(int)$team['team_id'];$origin=$team['source_group_id']??0;if ($origin) $origins[$chosen][$origin]=($origins[$chosen][$origin]??0)+1;
                $available=array_values(array_diff($available,[$chosen]));
            }
        }
        return $groups;
    }
}
