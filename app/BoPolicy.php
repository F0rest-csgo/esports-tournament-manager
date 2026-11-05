<?php
declare(strict_types=1);
namespace App;
final class BoPolicy {
    public static function config(array $raw,int $legacyDefault=1,?string $format=null): array {
        $c=Input::stageConfig($raw+['base_bo'=>$legacyDefault]);
        if ($format==='single' && $c['bo_policy']==='decisive') { $c['base_bo']=$c['decisive_bo'];$c['bo_policy']='uniform'; }
        return $c;
    }
    /** Records must be from BEFORE this Swiss round, never the current standings. */
    public static function reasons(string $format,array $match,array $config,array $records=[]): array {
        if ($format==='swiss') {
            $advance=false;$eliminate=false;
            foreach (['a','b'] as $slot) {
                $id=$match['team_'.$slot.'_id']??$match[$slot]??null;
                if (!$id || !isset($records[(int)$id])) continue;
                $r=$records[(int)$id];
                $advance=$advance || (int)$r['wins']===(int)$config['qualify_wins']-1;
                $eliminate=$eliminate || (int)$r['losses']===(int)$config['eliminate_losses']-1;
            }
            return array_values(array_filter([$advance?'晋级战':null,$eliminate?'淘汰战':null]));
        }
        $bracket=$match['bracket']??'main';
        if ($format==='double' && in_array($bracket,['lower','final'],true)) return ['淘汰战'];
        return [];
    }
    public static function resolve(string $format,array $match,array $config,array $records=[]): int {
        $round=(int)($match['round']??$match['round_number']??1);
        $key=($match['bracket']??'main').':'.$round;
        if (isset($config['round_bo'][$key])) return Input::bo($config['round_bo'][$key]);
        return $format!=='single' && ($config['bo_policy']??'uniform')==='decisive' && self::reasons($format,$match,$config,$records)
            ? (int)$config['decisive_bo'] : (int)$config['base_bo'];
    }
    public static function records(array $teams,array $history,int $round,array $config): array {
        $prior=array_values(array_filter($history,fn($m)=>(int)$m['round_number']<$round));
        $records=[];foreach (StandingsService::calculate($teams,$prior,$config) as $r) $records[(int)$r['team_id']]=$r;
        return $records;
    }
}
