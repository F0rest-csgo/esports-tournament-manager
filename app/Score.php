<?php
declare(strict_types=1);
namespace App;
final class Score {
    public static function game(int $a,int $b,int $target,array $overtime): int {
        if ($a<0 || $b<0 || max($a,$b)>10000 || $a===$b) throw new Problem('小局比分无效或平局。');
        $hi=max($a,$b);$lo=min($a,$b);$enabled=(bool)($overtime['enabled']??false);
        if (!$enabled) $valid=$hi===$target && $lo<$target;
        elseif (($overtime['mode']??'win_by')==='blocks') {
            $block=(int)($overtime['block_size']??3);
            $valid=($hi===$target && $lo<=$target-2);
            if (!$valid && $hi>$target) { $base=$target-1; $k=intdiv($hi-$base-1,$block);$limit=$base+$k*$block+1;$previous=$base+($k-1)*$block; $valid=$k>=1 && $hi===$limit && $lo>=$previous && $lo<=$limit-2; }
        } else {
            $margin=(int)($overtime['win_by']??2);
            $valid=($hi===$target && $hi-$lo>=$margin) || ($hi>$target && $hi-$lo===$margin);
        }
        if (!$valid) throw new Problem('小局比分不符合获胜分数或加时规则。');
        return $a>$b ? 0 : 1;
    }
    public static function series(array $games,int $bo,int $target,array $overtime): array {
        if (!in_array($bo,[1,3,5],true) || count($games)>$bo) throw new Problem('BO 或小局数量不合法。');
        $wins=[0,0];$needed=intdiv($bo,2)+1;$results=[];
        foreach ($games as $g) {
            if (max($wins)>=$needed) throw new Problem('比赛已经决出胜者，不能继续录入小局。');
            foreach (['a','b'] as $key) if (!isset($g[$key]) || filter_var($g[$key],FILTER_VALIDATE_INT)===false) throw new Problem('小分必须为整数。');
            $w=self::game((int)$g['a'],(int)$g['b'],$target,$overtime);$wins[$w]++;$results[]=$w;
        }
        return ['a'=>$wins[0],'b'=>$wins[1],'winner'=>max($wins)>=$needed?($wins[0]>$wins[1]?0:1):null,'games'=>$results];
    }
}
