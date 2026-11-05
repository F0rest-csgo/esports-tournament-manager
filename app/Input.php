<?php
declare(strict_types=1);
namespace App;
final class Input {
    public static function text(mixed $v,int $max=150,bool $required=true): string {
        if (!is_scalar($v) && $v!==null) throw new Problem('文本格式无效。');
        $s=trim((string)$v); if (($required && $s==='') || mb_strlen($s)>$max) throw new Problem('文本不能为空且长度不能超过 '.$max.' 字。'); return $s;
    }
    public static function int(mixed $v,int $min=0,int $max=100000): int { $n=filter_var($v,FILTER_VALIDATE_INT); if ($n===false || $n<$min || $n>$max) throw new Problem('数值必须为 '.$min.' 至 '.$max.' 之间的整数。'); return $n; }
    public static function choice(mixed $v,array $choices): string { if (!is_string($v) || !in_array($v,$choices,true)) throw new Problem('选项无效。');return $v; }
    public static function bo(mixed $v): int { $n=self::int($v,1,5);if (!in_array($n,[1,3,5],true)) throw new Problem('BO 仅支持 1、3、5。'); return $n; }
    public static function timezone(mixed $v): string { $tz=self::text($v,64);if (!in_array($tz,\DateTimeZone::listIdentifiers(),true) && $tz!=='UTC') throw new Problem('时区无效。');return $tz; }
    public static function time(mixed $v,string $tz): ?string {
        if (!$v) return null;
        $v=self::text($v,25);$d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v,new \DateTimeZone($tz));
        if (!$d || $d->format('Y-m-d\TH:i')!==$v) throw new Problem('日期时间格式无效。');
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public static function ranking(mixed $rules): array {
        if (!is_array($rules) || !$rules || count($rules)>7) throw new Problem('排名比较规则无效。');
        $result=[];
        foreach ($rules as $rule) {
            if ($rule==='head_to_head') $rule='head_to_head_wins';
            $rule=self::choice($rule,['points','wins','head_to_head_wins','head_to_head_round','game_difference','buchholz','seed']);
            if (!in_array($rule,$result,true)) $result[]=$rule;
        }
        return $result;
    }
    public static function mapPool(mixed $value): array {
        if (is_string($value)) $value=preg_split('/[\r\n,]+/',$value);
        if (!is_array($value) || count($value)>100) throw new Problem('地图池应为每行一个英文地图名，最多 100 张地图。');
        $maps=[];$seen=[];
        foreach ($value as $map) {
            $name=self::text($map,100,false);if ($name==='') continue;
            if (!preg_match("/^[A-Za-z0-9][A-Za-z0-9 ._'()-]*$/D",$name)) throw new Problem('地图名请使用英文名称，可包含数字、空格、连接符和下划线。');
            $key=strtolower($name);if (isset($seen[$key])) continue;
            $seen[$key]=true;$maps[]=$name;
        }
        return $maps;
    }
    public static function stageConfig(array $c): array {
        $bo=['base_bo'=>self::bo($c['base_bo']??1),'bo_policy'=>self::choice($c['bo_policy']??'uniform',['uniform','decisive']),'decisive_bo'=>self::bo($c['decisive_bo']??3)];
        $rules=self::ranking($c['ranking']??['points','wins','head_to_head_wins','head_to_head_round','game_difference','buchholz','seed']);
        $roundBo=[];foreach (($c['round_bo']??[]) as $k=>$v) { if (!preg_match('/^(upper|lower|final|third|group|swiss):[1-9][0-9]*$/',(string)$k)) throw new Problem('每轮 BO 的键应为 upper:1 等。'); $roundBo[$k]=self::bo($v); }
        return $bo+['groups'=>self::int($c['groups']??1,1,32),'rounds'=>self::int($c['rounds']??5,1,30),'qualify_wins'=>self::int($c['qualify_wins']??3,1,30),'eliminate_losses'=>self::int($c['eliminate_losses']??3,1,30),'advance_count'=>self::int($c['advance_count']??8,2,128),'per_group'=>self::int($c['per_group']??0,0,128),'win_points'=>self::int($c['win_points']??1,0,100),'loss_points'=>self::int($c['loss_points']??0,0,100),'third'=>(bool)($c['third']??false),'reset'=>(bool)($c['reset']??true),'seed_mode'=>self::choice($c['seed_mode']??'seed',['seed','random']),'initial_pairing'=>self::choice($c['initial_pairing']??'standard',['standard','adjacent']),'ranking'=>array_values(array_unique($rules)),'round_bo'=>$roundBo];
    }
}
