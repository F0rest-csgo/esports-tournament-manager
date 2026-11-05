<?php
declare(strict_types=1);
namespace App\Formats;
final class RoundRobin {
    public static function generate(array $teams,bool $double=false): array {
        if (count($teams)%2) $teams[]=null;
        $n=count($teams); if ($n<2) return [];
        $nodes=[]; $rotation=$teams;
        for ($r=1;$r<$n;$r++) {
            for ($i=0;$i<$n/2;$i++) {
                $a=$rotation[$i];$b=$rotation[$n-1-$i]; if (!$a || !$b) continue;
                if (($r+$i)%2===0) [$a,$b]=[$b,$a];
                $nodes[]=['key'=>"R$r-$i",'bracket'=>'group','round'=>$r,'a'=>$a,'b'=>$b];
                if ($double) $nodes[]=['key'=>'R'.($r+$n-1)."-$i",'bracket'=>'group','round'=>$r+$n-1,'a'=>$b,'b'=>$a];
            }
            $last=array_pop($rotation); array_splice($rotation,1,0,[$last]);
        }
        usort($nodes,fn($a,$b)=>$a['round']<=>$b['round']); return $nodes;
    }
}
