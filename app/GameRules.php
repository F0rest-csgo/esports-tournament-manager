<?php
declare(strict_types=1);
namespace App;
final class GameRules {
    public static function defaultMapPool(string $game): array {
        return $game==='cs2'?['Ancient','Anubis','Cache','Dust 2','Inferno','Mirage','Nuke']:[];
    }
    /** Scoring presets only; series lengths belong to stages, not games. */
    public static function preset(string $game): array {
        $rules=match ($game) {
            'valorant'=>['game'=>'valorant','name'=>'VALORANT','target'=>13,'enabled'=>true,'mode'=>'win_by','win_by'=>2,'rules_version'=>'2026-11-03'],
            'cs2'=>['game'=>'cs2','name'=>'Counter-Strike 2','target'=>13,'enabled'=>true,'mode'=>'blocks','block_size'=>3,'rules_version'=>'2026-11-03'],
            default=>throw new Problem('请选择 VALORANT 或 CS2。'),
        };
        $rules['map_pool']=self::defaultMapPool($game);
        return $rules;
    }
    public static function signature(int $target,array $rules): array {
        $enabled=(bool)($rules['enabled']??false);$mode=$rules['mode']??'win_by';
        return [$target,$enabled,$enabled?$mode:null,$enabled?($mode==='blocks'?(int)($rules['block_size']??3):(int)($rules['win_by']??2)):null];
    }
}
