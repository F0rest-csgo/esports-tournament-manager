<?php
declare(strict_types=1);
namespace App;
final class MatchService {
    public function __construct(private Database $db) {}
    public static function resolved(array $m): bool {
        return $m['status']==='completed' || ($m['status']==='cancelled' && !empty($m['forfeit_team_id']) && !empty($m['winner_id']));
    }
    public static function effectiveStatus(array $m): string {
        if (!$m['is_active']) return 'inactive';
        if ($m['status']==='scheduled' && $m['scheduled_at'] && strtotime($m['scheduled_at'].' UTC')<=time() && $m['team_a_id'] && $m['team_b_id']) return 'live';
        return $m['status'];
    }
    public function insertNodes(int $tid,array $stage,array $nodes,?int $gid=null): void {
        $t=$this->db->one('SELECT default_bo FROM tournaments WHERE id=?',[$tid]);
        $c=BoPolicy::config(json_decode($stage['configuration_json'],true),(int)$t['default_bo'],$stage['format_type']);$records=[];
        if ($stage['format_type']==='swiss') {
            $teams=$this->db->all('SELECT team_id,seed FROM stage_teams WHERE tournament_id=? AND stage_id=?',[$tid,$stage['id']]);
            $history=$this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$stage['id']]);
            $records=BoPolicy::records($teams,$history,(int)($nodes[0]['round']??1),$c);
        }
        $number=(int)($this->db->one('SELECT MAX(match_number) AS n FROM matches WHERE tournament_id=?',[$tid])['n']??0);$ids=[];
        foreach ($nodes as $node) {
            $key=($gid?'G'.$gid.'-':'').$node['key'];
            $bo=BoPolicy::resolve($stage['format_type'],$node,$c,$records);
            $this->db->run('INSERT INTO matches (tournament_id,stage_id,group_id,round_number,match_number,bracket,node_key,team_a_id,team_b_id,best_of,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$tid,$stage['id'],$gid,$node['round'],++$number,$node['bracket'],$key,is_int($node['a'])?$node['a']:null,is_int($node['b'])?$node['b']:null,$bo,$node['key']==='RESET'?0:1]);
            $ids[$node['key']]=$this->db->id();
        }
        foreach ($nodes as $node) foreach (['a','b'] as $slot) if (is_string($node[$slot])) {
            [$type,$key]=explode(':',$node[$slot],2);
            $this->db->run('INSERT INTO match_dependencies (tournament_id,source_match_id,target_match_id,source_result_type,target_slot) VALUES (?,?,?,?,?)',[$tid,$ids[$key],$ids[$node['key']],$type,$slot]);
        }
        $this->propagate($tid,(int)$stage['id']);
    }
    public function propagate(int $tid,int $sid): void {
        $matches=$this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=? ORDER BY id',[$tid,$sid]);$map=[];foreach ($matches as $m) $map[(int)$m['id']]=$m;
        $dependencies=$this->db->all('SELECT d.* FROM match_dependencies d JOIN matches m ON m.id=d.target_match_id WHERE d.tournament_id=? AND m.stage_id=?',[$tid,$sid]);$deps=[];foreach ($dependencies as $d) $deps[$d['target_match_id']][$d['target_slot']]=$d;
        foreach ($map as $id=>&$m) {
            $old=$m;$ready=true;
            foreach ($deps[$id]??[] as $slot=>$d) {
                $source=$map[(int)$d['source_match_id']];$value=null;
                if (!self::resolved($source)) $ready=false;
                else { $value=$source['winner_id']; if ($d['source_result_type']==='loser') $value=$source['is_bye']?null:($source['winner_id']==$source['team_a_id']?$source['team_b_id']:$source['team_a_id']); }
                $m['team_'.$slot.'_id']=$value;
            }
            if ($m['node_key']==='RESET') {
                $gf=null;foreach ($map as $candidate) if ($candidate['node_key']==='GF') $gf=$candidate;
                $m['is_active']=$gf && self::resolved($gf) && !$gf['is_bye'] && $gf['winner_id']==$gf['team_b_id'] ? 1 : 0;
                if (!$m['is_active']) { $m['team_a_id']=null;$m['team_b_id']=null;$ready=false; }
            }
            $changed=(int)$old['team_a_id']!==(int)$m['team_a_id'] || (int)$old['team_b_id']!==(int)$m['team_b_id'] || (int)$old['is_active']!==(int)$m['is_active'];
            if ($changed && !$old['is_bye'] && (self::effectiveStatus($old)==='live' || self::resolved($old))) throw new Problem('下游比赛 #'.$old['match_number'].' 已开始或结束，对阵变更冲突。请先撤回下游结果并调整其状态和时间。',409);
            if ($changed) { $m['winner_id']=null;$m['score_a']=null;$m['score_b']=null;$m['is_bye']=0;$m['status']='scheduled';$this->db->run('DELETE FROM match_games WHERE match_id=?',[$id]);$this->db->run('DELETE FROM match_maps WHERE tournament_id=? AND match_id=?',[$tid,$id]); }
            if ($ready && $m['is_active'] && (!$m['team_a_id'] || !$m['team_b_id'])) { $m['is_bye']=1;$m['status']='completed';$m['winner_id']=$m['team_a_id']?:$m['team_b_id']; }
            elseif ($m['is_bye']) { $m['is_bye']=0;$m['status']='scheduled';$m['winner_id']=null; }
            if ($m!==$old) $this->db->run('UPDATE matches SET team_a_id=?,team_b_id=?,winner_id=?,score_a=?,score_b=?,status=?,is_bye=?,is_active=?,version=version+1 WHERE id=? AND tournament_id=?',[$m['team_a_id'],$m['team_b_id'],$m['winner_id'],$m['score_a'],$m['score_b'],$m['status'],$m['is_bye'],$m['is_active'],$id,$tid]);
        }unset($m);
    }
    public function edit(int $tid,array $t,array $input): void {
        $id=Input::int($input['id']??null,1,PHP_INT_MAX);$m=$this->db->one('SELECT * FROM matches WHERE tournament_id=? AND id=? FOR UPDATE',[$tid,$id]);if (!$m) throw new Problem('比赛不存在。',404);
        if ((int)$m['version']!==Input::int($input['version']??null,1,PHP_INT_MAX)) throw new Problem('比赛已被其他操作修改，请刷新后再编辑。',409);
        if (!$m['is_active'] || $m['is_bye']) {
            $description=Input::text($input['description']??$m['description']??'',10000,false);
            $this->db->run('UPDATE matches SET description=?,version=version+1 WHERE tournament_id=? AND id=?',[$description,$tid,$id]);return;
        }
        $bo=Input::bo($input['best_of']??$m['best_of']);$time=Input::time($input['scheduled_at']??null,$t['timezone']);$status=Input::choice($input['status']??'scheduled',['scheduled','live','completed','postponed','cancelled']);
        $description=Input::text($input['description']??$m['description']??'',10000,false);
        $forfeit=empty($input['forfeit_team_id'])?null:Input::int($input['forfeit_team_id'],1,PHP_INT_MAX);
        $oldMaps=MapStore::forMatch($this->db,$tid,$id);
        $submittedMaps=$input['maps']??array_values(array_filter($oldMaps,fn($map)=>(int)$map['game_number']<=$bo));
        $maps=MapStore::validate($submittedMaps,$oldMaps,MapStore::pool($t),$bo);
        $a=empty($input['team_a_id'])?null:Input::int($input['team_a_id'],1,PHP_INT_MAX);$b=empty($input['team_b_id'])?null:Input::int($input['team_b_id'],1,PHP_INT_MAX);
        if ($a && $a===$b) throw new Problem('双方不能是同一支队伍。');
        foreach (['a'=>$a,'b'=>$b] as $slot=>$team) {
            if ($team && !$this->db->one('SELECT team_id FROM stage_teams WHERE tournament_id=? AND stage_id=? AND team_id=?',[$tid,$m['stage_id'],$team])) throw new Problem('所选队伍不属于此赛事阶段。');
            if ($m['group_id'] && $team && !$this->db->one('SELECT id FROM group_members WHERE tournament_id=? AND group_id=? AND team_id=?',[$tid,$m['group_id'],$team])) throw new Problem('所选队伍不属于此小组。');
            if ((int)$team!==(int)$m['team_'.$slot.'_id'] && $this->db->one('SELECT id FROM match_dependencies WHERE target_match_id=? AND target_slot=? AND tournament_id=?',[$id,$slot,$tid])) throw new Problem('此位置由上游赛果决定，请修改上游比赛。');
            if ((int)$team!==(int)$m['team_'.$slot.'_id'] && $team && $this->db->one('SELECT id FROM matches WHERE tournament_id=? AND stage_id=? AND round_number=? AND bracket=? AND id<>? AND (team_a_id=? OR team_b_id=?)',[$tid,$m['stage_id'],$m['round_number'],$m['bracket'],$id,$team,$team])) throw new Problem('此队伍在相同轮次已有比赛，请先清空相关对阵后再调整。');
        }
        $winner=null;$sa=null;$sb=null;$games=$input['games']??[];if (!is_array($games)) throw new Problem('小局格式无效。');$results=[];
        if ($forfeit!==null) {
            if (!$a || !$b || !in_array($forfeit,[$a,$b],true)) throw new Problem('判负队伍必须是双方已确定的参赛队伍之一。');
            $winner=$forfeit===$a?$b:$a;$status='cancelled';$needed=intdiv($bo,2)+1;
            $sa=$winner===$a?$needed:0;$sb=$winner===$b?$needed:0;
            $games=array_fill(0,$needed,['a'=>$winner===$a?13:0,'b'=>$winner===$b?13:0]);$results=array_fill(0,$needed,$winner===$a?0:1);
        } elseif ($t['score_mode']==='detailed') {
            $score=Score::series($games,$bo,(int)$t['game_win_score'],json_decode($t['overtime_rules'],true));$sa=$score['a'];$sb=$score['b'];$results=$score['games'];
            if ($score['winner']!==null) { $winner=$score['winner']===0?$a:$b;$status='completed'; }
            elseif ($status==='completed') throw new Problem('详细比分尚未达到本场获胜所需局数。');
        } else {
            $winner=empty($input['winner_id'])?null:Input::int($input['winner_id'],1,PHP_INT_MAX);
            if ($winner!==null && !in_array($winner,[$a,$b],true)) throw new Problem('胜者必须是本场比赛的一方。');
            if ($winner) $status='completed';elseif ($status==='completed') throw new Problem('请选择获胜队伍。');
        }
        if (($winner || $games || $status==='live') && (!$a || !$b)) throw new Problem('双方队伍尚未确定，不能录入比赛结果或开始比赛。');
        $oldGames=$this->db->all('SELECT team_a_score,team_b_score FROM match_games WHERE match_id=? ORDER BY game_number',[$id]);
        $oldScores=array_map(fn($g)=>[(int)$g['team_a_score'],(int)$g['team_b_score']],$oldGames);
        $newScores=($forfeit || $t['score_mode']==='detailed')?array_map(fn($g)=>[(int)$g['a'],(int)$g['b']],$games):[];
        $resultChanged=$oldScores!==$newScores || (int)$forfeit!==(int)($m['forfeit_team_id']??null) || (int)$winner!==(int)$m['winner_id'] || $sa!==($m['score_a']===null?null:(int)$m['score_a']) || $sb!==($m['score_b']===null?null:(int)$m['score_b']) || (int)$a!==(int)$m['team_a_id'] || (int)$b!==(int)$m['team_b_id'];
        if ($resultChanged) {
            $stage=$this->db->one('SELECT stage_order,format_type FROM stages WHERE tournament_id=? AND id=?',[$tid,$m['stage_id']]);
            if ($this->db->one('SELECT id FROM stages WHERE tournament_id=? AND stage_order>? AND locked=1',[$tid,$stage['stage_order']])) throw new Problem('后续阶段已锁定，不能修改本阶段结果。请先重置后续阶段。',409);
            if ($stage['format_type']==='swiss' && $this->db->one('SELECT id FROM matches WHERE tournament_id=? AND stage_id=? AND round_number>?',[$tid,$m['stage_id'],$m['round_number']])) throw new Problem('后续瑞士轮已经生成。请先从最后一轮撤回后续轮次，再更正本轮。',409);
        }
        $this->db->run('UPDATE matches SET team_a_id=?,team_b_id=?,best_of=?,scheduled_at=?,status=?,winner_id=?,score_a=?,score_b=?,description=?,forfeit_team_id=?,version=version+1 WHERE id=? AND tournament_id=?',[$a,$b,$bo,$time,$status,$winner,$sa,$sb,$description,$forfeit,$id,$tid]);
        $this->db->run('DELETE FROM match_games WHERE match_id=?',[$id]);
        if ($forfeit || $t['score_mode']==='detailed') foreach ($games as $i=>$g) $this->db->run('INSERT INTO match_games (match_id,game_number,team_a_score,team_b_score,winner_id) VALUES (?,?,?,?,?)',[$id,$i+1,(int)$g['a'],(int)$g['b'],$results[$i]===0?$a:$b]);
        MapStore::save($this->db,$tid,$id,$maps);
        $this->propagate($tid,(int)$m['stage_id']);
    }
}
