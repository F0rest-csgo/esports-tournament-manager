<?php
declare(strict_types=1);
namespace App;
final class AuditLogger {
    private static function canonical(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key=>$item) $value[$key]=self::canonical($item);
        return $value;
    }
    public function __construct(private Database $db, private ?Auth $auth = null) {}
    public function append(?int $tid,string $action,string $type,?int $id,mixed $before,mixed $after): void {
        $head=$this->db->one('SELECT entry_hash FROM audit_head WHERE id=1 FOR UPDATE');
        $data=['tournament_id'=>$tid,'actor_type'=>$this->auth?->actor() ?? 'cli','action'=>$action,'target_type'=>$type,'target_id'=>$id,'old_data'=>$before,'new_data'=>$after,'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? 'cli','created_at'=>gmdate('Y-m-d H:i:s')];
        $payload=json_encode_safe($data); $previous=$head['entry_hash']; $hash=hash('sha256',$previous.$payload);
        $this->db->run('INSERT INTO audit_logs (tournament_id,actor_type,action,target_type,target_id,old_data,new_data,ip_address,created_at,previous_hash,entry_hash,payload) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$tid,$data['actor_type'],$action,$type,$id,$before===null?null:json_encode_safe($before),$after===null?null:json_encode_safe($after),$data['ip_address'],$data['created_at'],$previous,$hash,$payload]);
        $this->db->run('UPDATE audit_head SET entry_hash=? WHERE id=1',[$hash]);
    }
    public function verify(): array {
        $previous=str_repeat('0',64); $count=0; $last=0;
        do {
            $rows=$this->db->all('SELECT * FROM audit_logs WHERE id>? ORDER BY id LIMIT 500',[$last]);
            foreach ($rows as $r) {
                if (!hash_equals($previous,$r['previous_hash']) || !hash_equals(hash('sha256',$previous.$r['payload']),$r['entry_hash'])) throw new Problem('日志哈希链校验失败，记录 #'.$r['id'],409);
                $p=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);
                foreach (['tournament_id','actor_type','action','target_type','target_id','ip_address','created_at'] as $field) if ((string)$p[$field] !== (string)$r[$field]) throw new Problem('日志字段校验失败 #'.$r['id'],409);
                foreach (['old_data','new_data'] as $field) if (self::canonical($p[$field]) !== self::canonical($r[$field]===null?null:json_decode($r[$field],true,512,JSON_THROW_ON_ERROR))) throw new Problem('日志内容校验失败 #'.$r['id'],409);
                $previous=$r['entry_hash']; $last=(int)$r['id']; $count++;
            }
        } while (count($rows)===500);
        if (!hash_equals($previous,$this->db->one('SELECT entry_hash FROM audit_head WHERE id=1')['entry_hash'])) throw new Problem('日志链尾校验失败',409);
        return ['count'=>$count,'head'=>$previous];
    }
}
