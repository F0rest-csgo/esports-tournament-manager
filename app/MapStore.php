<?php
declare(strict_types=1);
namespace App;
final class MapStore {
    public static function pool(array $tournament): array {
        $rules=is_array($tournament['overtime']??null)?$tournament['overtime']:json_decode($tournament['overtime_rules'],true);
        return array_key_exists('map_pool',$rules)?Input::mapPool($rules['map_pool']):GameRules::defaultMapPool($rules['game']??'');
    }
    public static function forMatch(Database $db,int $tid,int $mid): array {
        return $db->all('SELECT game_number,map_name FROM match_maps WHERE tournament_id=? AND match_id=? ORDER BY game_number',[$tid,$mid]);
    }
    public static function validate(mixed $submitted,array $old,array $pool,int $bo): array {
        if (!is_array($submitted) || count($submitted)>5) throw new Problem('每场地图数量不能超过本场 BO。');
        $oldByNumber=[];foreach ($old as $map) $oldByNumber[(int)$map['game_number']]=$map['map_name'];
        $result=[];
        foreach ($submitted as $map) {
            if (!is_array($map)) throw new Problem('地图设置格式无效。');
            $number=Input::int($map['game_number']??0,1,$bo);
            $name=Input::text($map['map_name']??'',100,false);if ($name==='') continue;
            if (isset($result[$number])) throw new Problem('同一局不能重复设置地图。');
            if (!in_array($name,$pool,true) && ($oldByNumber[$number]??null)!==$name) throw new Problem('请选择本赛事地图池中的地图；新地图请先在赛事设置中添加。');
            $result[$number]=['game_number'=>$number,'map_name'=>$name];
        }
        ksort($result);return array_values($result);
    }
    public static function save(Database $db,int $tid,int $mid,array $maps): void {
        $db->run('DELETE FROM match_maps WHERE tournament_id=? AND match_id=?',[$tid,$mid]);
        foreach ($maps as $map) $db->run('INSERT INTO match_maps (tournament_id,match_id,game_number,map_name) VALUES (?,?,?,?)',[$tid,$mid,$map['game_number'],$map['map_name']]);
    }
}
