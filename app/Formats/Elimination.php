<?php
declare(strict_types=1);
namespace App\Formats;
final class Elimination {
    public static function seeds(int $size): array {
        $seeds=[1,2];
        while (count($seeds)<$size) { $sum=count($seeds)*2+1; $next=[]; foreach ($seeds as $seed) { $next[]=$seed; $next[]=$sum-$seed; } $seeds=$next; }
        return $seeds;
    }
    public static function generate(array $teams,bool $double=false,bool $third=false,bool $reset=true): array {
        $size=2; while ($size<count($teams)) $size*=2;
        $nodes=[]; $upper=[]; $levels=(int)log($size,2);
        $add=function(string $key,string $bracket,int $round,mixed $a,mixed $b) use (&$nodes): void { $nodes[]=['key'=>$key,'bracket'=>$bracket,'round'=>$round,'a'=>$a,'b'=>$b]; };
        $order=self::seeds($size);
        for ($r=1;$r<=$levels;$r++) {
            for ($i=0;$i<$size/(2**$r);$i++) {
                $key="U$r-$i"; $upper[$r][]=$key;
                $add($key,'upper',$r,$r===1 ? ($teams[$order[$i*2]-1] ?? null) : 'winner:'.$upper[$r-1][$i*2],$r===1 ? ($teams[$order[$i*2+1]-1] ?? null) : 'winner:'.$upper[$r-1][$i*2+1]);
            }
        }
        if (!$double) {
            if ($third && $levels>=2) $add('THIRD','third',1,'loser:'.$upper[$levels-1][0],'loser:'.$upper[$levels-1][1]);
            return $nodes;
        }
        $lower=[];
        if ($levels>1) {
            for ($i=0;$i<$size/4;$i++) { $key="L1-$i"; $lower[]=$key; $add($key,'lower',1,'loser:'.$upper[1][$i*2],'loser:'.$upper[1][$i*2+1]); }
            for ($u=2;$u<=$levels;$u++) {
                $cross=[]; $round=2*$u-2;
                foreach ($lower as $i=>$previous) { $key="L$round-$i"; $cross[]=$key; $opponent=count($lower)>1 ? ($i^1) : 0; $add($key,'lower',$round,'winner:'.$previous,'loser:'.$upper[$u][$opponent]); }
                $lower=$cross;
                if ($u<$levels) { $next=[]; for ($i=0;$i<count($lower)/2;$i++) { $key='L'.($round+1)."-$i"; $next[]=$key; $add($key,'lower',$round+1,'winner:'.$lower[$i*2],'winner:'.$lower[$i*2+1]); } $lower=$next; }
            }
        }
        $add('GF','final',1,'winner:'.$upper[$levels][0],$levels===1 ? 'loser:'.$upper[1][0] : 'winner:'.$lower[0]);
        if ($reset) $add('RESET','final',2,'winner:GF','loser:GF');
        return $nodes;
    }
}
