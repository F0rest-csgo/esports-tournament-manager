<?php
declare(strict_types=1);
namespace App;
use App\Formats\{Elimination,RoundRobin,Swiss};
final class TournamentService {
    private MatchService $matches;
    private StandingsService $standings;
    public function __construct(private Database $db,private Auth $auth,private AuditLogger $audit) { $this->matches=new MatchService($db);$this->standings=new StandingsService($db); }
    public function bySlug(string $slug): array { return $this->db->one('SELECT * FROM tournaments WHERE slug=?',[$slug]) ?? throw new Problem('赛事不存在。',404); }
    public function stage(int $tid,int $id): array { return $this->db->one('SELECT * FROM stages WHERE tournament_id=? AND id=?',[$tid,$id]) ?? throw new Problem('阶段不存在。',404); }
    public function state(int $tid): array {
        $t=$this->db->one('SELECT * FROM tournaments WHERE id=?',[$tid]) ?? throw new Problem('赛事不存在。',404);
        $stages=$this->db->all('SELECT * FROM stages WHERE tournament_id=? ORDER BY stage_order',[$tid]);
        foreach ($stages as &$s) $s['configuration']=BoPolicy::config(json_decode($s['configuration_json'],true),(int)$t['default_bo'],$s['format_type']);unset($s);
        $matches=$this->db->all('SELECT * FROM matches WHERE tournament_id=? ORDER BY match_number',[$tid]);
        foreach ($stages as &$s) if ((int)$s['stage_order']===2 && in_array($s['format_type'],['single','double'],true) && !array_filter($matches,fn($m)=>(int)$m['stage_id']===(int)$s['id'])) {
            $firstConfig=$stages[0]['configuration'];$c=$s['configuration'];
            $s['preview_nodes']=Elimination::generate(array_fill(0,$firstConfig['advance_count'],null),$s['format_type']==='double',$c['third'],$c['reset']);
        }unset($s);
        $mapRows=$this->db->all('SELECT match_id,game_number,map_name FROM match_maps WHERE tournament_id=? ORDER BY game_number',[$tid]);$mapsByMatch=[];$mapNames=[];
        foreach ($mapRows as $map) { $mapsByMatch[$map['match_id']][]=$map;$mapNames[$map['match_id']][$map['game_number']]=$map['map_name']; }
        $games=$this->db->all('SELECT g.* FROM match_games g JOIN matches m ON m.id=g.match_id WHERE m.tournament_id=? ORDER BY g.game_number',[$tid]);$byMatch=[];foreach ($games as $g) { $g['map_name']=$mapNames[$g['match_id']][$g['game_number']]??null;$byMatch[$g['match_id']][]=$g; }
        $teamsByStage=[];$stageMap=[];$recordCache=[];
        foreach ($stages as $s) $stageMap[$s['id']]=$s;
        foreach ($matches as &$m) {
            $m['effective_status']=MatchService::effectiveStatus($m);$m['games']=$byMatch[$m['id']]??[];$m['maps']=$mapsByMatch[$m['id']]??[];
            $s=$stageMap[$m['stage_id']];$c=$s['configuration'];$records=[];
            if ($s['format_type']==='swiss') {
                $sid=(int)$s['id'];$round=(int)$m['round_number'];
                $teamsByStage[$sid]??=$this->db->all('SELECT team_id,seed FROM stage_teams WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);
                $recordCache[$sid][$round]??=BoPolicy::records($teamsByStage[$sid],array_values(array_filter($matches,fn($row)=>(int)$row['stage_id']===$sid)),$round,$c);
                $records=$recordCache[$sid][$round];
            }
            $m['bo_reason']=implode(' / ',BoPolicy::reasons($s['format_type'],$m,$c,$records));
        }unset($m);
        $t['overtime']=json_decode($t['overtime_rules'],true);
        $t['game']=$t['overtime']['game']??null;
        $t['map_pool']=MapStore::pool($t);
        return ['tournament'=>$t,'stages'=>$stages,'teams'=>$this->db->all('SELECT * FROM teams WHERE tournament_id=? ORDER BY seed,id',[$tid]),'stage_teams'=>$this->db->all('SELECT * FROM stage_teams WHERE tournament_id=? ORDER BY stage_id,seed',[$tid]),'groups'=>$this->db->all('SELECT * FROM `groups` WHERE tournament_id=? ORDER BY id',[$tid]),'group_members'=>$this->db->all('SELECT * FROM group_members WHERE tournament_id=?',[$tid]),'matches'=>$matches,'dependencies'=>$this->db->all('SELECT * FROM match_dependencies WHERE tournament_id=?',[$tid]),'standings'=>$this->db->all('SELECT * FROM standings WHERE tournament_id=? ORDER BY stage_id,group_key,rank_position',[$tid]),'server_time'=>gmdate('c')];
    }
    private function settings(array $d,?array $existing=null): array {
        $slug=Input::text($d['slug']??'',100);if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) throw new Problem('Slug 只能包含小写字母、数字和单个连接符。');
        $rules=GameRules::preset(Input::choice($d['game']??'cs2',['valorant','cs2']));
        $oldRules=$existing?json_decode($existing['overtime_rules'],true):[];
        $defaultPool=$existing && ($oldRules['game']??null)===$rules['game']?MapStore::pool($existing):$rules['map_pool'];
        $rules['map_pool']=Input::mapPool($d['map_pool']??$defaultPool);
        return ['name'=>Input::text($d['name']??''),'slug'=>$slug,'description'=>Input::text($d['description']??'',10000,false),'score_mode'=>Input::choice($d['score_mode']??'detailed',['detailed','winner']),'game_win_score'=>$rules['target'],'overtime_rules'=>json_encode_safe($rules),'timezone'=>Input::timezone($d['timezone']??'Asia/Shanghai'),'stage_count'=>Input::int($d['stage_count']??1,1,2)];
    }
    private function password(mixed $p): string { $p=Input::text($p,200);if (mb_strlen($p)<12 || strlen($p)>72) throw new Problem('管理密码至少需要 12 个字符，且不超过 72 字节。');return password_hash($p,PASSWORD_BCRYPT); }
    public function create(array $d): array {
        $this->auth->requireGlobal();$s=$this->settings($d);$hash=$this->password($d['password']??'');
        return $this->db->transaction(function () use ($s,$hash): array {
            $this->db->run('INSERT INTO tournaments (name,slug,description,score_mode,game_win_score,overtime_rules,timezone,stage_count) VALUES (?,?,?,?,?,?,?,?)',array_values($s));$tid=$this->db->id();
            $this->db->run('INSERT INTO tournament_credentials (tournament_id,password_hash) VALUES (?,?)',[$tid,$hash]);
            for ($i=1;$i<=$s['stage_count'];$i++) $this->db->run('INSERT INTO stages (tournament_id,stage_order,name,format_type,configuration_json) VALUES (?,?,?,?,?)',[$tid,$i,$i===1?'第一阶段':'第二阶段','single',json_encode_safe(Input::stageConfig([]))]);
            $this->audit->append($tid,'create','tournament',$tid,null,$s);return ['id'=>$tid,'slug'=>$s['slug']];
        });
    }
    public function mutate(int $tid,string $action,array $d): array {
        $this->auth->requireTournament($tid);
        return $this->db->transaction(function () use ($tid,$action,$d): array {
            $t=$this->db->one('SELECT * FROM tournaments WHERE id=? FOR UPDATE',[$tid]) ?? throw new Problem('赛事不存在。',404);
            $this->auth->requireTournament($tid);
            $before=$this->state($tid);unset($before['server_time']);$result=[];
            switch ($action) {
                case 'settings':
                    $s=$this->settings($d,$t);
                    if ($s['score_mode']!==$t['score_mode'] || GameRules::signature($s['game_win_score'],json_decode($s['overtime_rules'],true))!==GameRules::signature((int)$t['game_win_score'],json_decode($t['overtime_rules'],true))) {
                        if ($this->db->one('SELECT id FROM matches WHERE tournament_id=? AND is_bye=0 AND (winner_id IS NOT NULL OR score_a>0 OR score_b>0)',[$tid])) throw new Problem('已有比赛结果，不能修改比分模式或获胜规则。');
                    }
                    if ($s['stage_count']!==(int)$t['stage_count']) {
                        if ($s['stage_count']===2) $this->db->run('INSERT INTO stages (tournament_id,stage_order,name,format_type,configuration_json) VALUES (?,2,?,?,?)',[$tid,'第二阶段','single',json_encode_safe(Input::stageConfig([]))]);
                        else { $second=$this->db->one('SELECT id FROM stages WHERE tournament_id=? AND stage_order=2',[$tid]);$this->clearStage($tid,(int)$second['id']);$this->db->run('DELETE FROM stages WHERE tournament_id=? AND stage_order=2',[$tid]); }
                    }
                    $this->db->run('UPDATE tournaments SET name=?,slug=?,description=?,score_mode=?,game_win_score=?,overtime_rules=?,timezone=?,stage_count=? WHERE id=?',[...array_values($s),$tid]);break;
                case 'status':
                    $this->auth->requireGlobal();$status=Input::choice($d['status']??'',['active','disabled','archived']);$this->db->run('UPDATE tournaments SET status=? WHERE id=?',[$status,$tid]);break;
                case 'password':
                    $this->auth->requireGlobal();$this->db->run('UPDATE tournament_credentials SET password_hash=?,password_version=password_version+1 WHERE tournament_id=?',[$this->password($d['password']??''),$tid]);$result=['message'=>'密码已重置，原赛事管理员会话已失效。'];break;
                case 'teams_add':
                    if ($this->db->one('SELECT id FROM matches WHERE tournament_id=?',[$tid])) throw new Problem('已有赛程，请先重置赛程再添加队伍。');
                    $names=preg_split('/\R/u',Input::text($d['names']??'',15000));$seed=(int)($this->db->one('SELECT MAX(seed) AS n FROM teams WHERE tournament_id=?',[$tid])['n']??0);
                    foreach ($names as $name) { $name=trim($name);if ($name==='') continue;$this->db->run('INSERT INTO teams (tournament_id,name,seed) VALUES (?,?,?)',[$tid,Input::text($name,100),++$seed]); }
                    if ((int)$this->db->one('SELECT COUNT(*) AS n FROM teams WHERE tournament_id=?',[$tid])['n']>128) throw new Problem('每个赛事最多支持 128 支队伍。');break;
                case 'team_edit':
                    $id=Input::int($d['id']??0,1,PHP_INT_MAX);$team=$this->db->one('SELECT * FROM teams WHERE id=? AND tournament_id=?',[$id,$tid]) ?? throw new Problem('队伍不存在。',404);
                    $seed=Input::int($d['seed']??1,1,128);
                    if ($seed!==(int)$team['seed'] && $this->db->one('SELECT id FROM matches WHERE tournament_id=?',[$tid])) throw new Problem('赛程生成后不能修改初始种子，请先重置赛程。');
                    $this->db->run('UPDATE teams SET name=?,seed=? WHERE id=? AND tournament_id=?',[Input::text($d['name']??'',100),$seed,$id,$tid]);break;
                case 'team_delete':
                    $id=Input::int($d['id']??0,1,PHP_INT_MAX);if ($this->db->one('SELECT team_id FROM stage_teams WHERE tournament_id=? AND team_id=?',[$tid,$id])) throw new Problem('队伍已参与阶段，请先重置对应阶段。');$this->db->run('DELETE FROM teams WHERE tournament_id=? AND id=?',[$tid,$id]);break;
                case 'stage_save':
                    $stage=$this->stage($tid,Input::int($d['id']??0,1,PHP_INT_MAX));
                    $format=Input::choice($d['format_type']??'',['single','double','round_robin','double_round_robin','swiss']);$c=Input::stageConfig($d['configuration']??[]);
                    if ($format==='single') $c['bo_policy']='uniform';
                    if ($c['bo_policy']==='decisive' && in_array($format,['round_robin','double_round_robin'],true)) throw new Problem('循环赛请使用阶段统一 BO，或按轮次、单场设置 BO；积分榜出线形势不采用瑞士轮胜负阈值。');
                    if ($this->db->one('SELECT id FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$stage['id']])) {
                        $old=BoPolicy::config(json_decode($stage['configuration_json'],true),(int)$t['default_bo'],$stage['format_type']);
                        $fixedBefore=$old;$fixedAfter=$c;unset($fixedBefore['ranking'],$fixedAfter['ranking']);
                        if ($format!==$stage['format_type'] || $fixedBefore!=$fixedAfter) throw new Problem('已有赛程时可修改阶段名称和排名比较顺序；更改赛制、分组或 BO 规则前请先重置本阶段。');
                        if ($old['ranking']!==$c['ranking']) $this->assertNoNext($tid,$stage);
                    }
                    $this->db->run('UPDATE stages SET name=?,format_type=?,configuration_json=? WHERE id=? AND tournament_id=?',[Input::text($d['name']??'',100),$format,json_encode_safe($c),$stage['id'],$tid]);break;
                case 'generate':
                    $stage=$this->stage($tid,Input::int($d['stage_id']??0,1,PHP_INT_MAX));
                    if ((int)$stage['stage_order']!==1) throw new Problem('第二阶段请通过“预览晋级 → 锁定晋级”生成。');
                    $this->generate($tid,$stage,$d);break;
                case 'stage_reset':
                    $stage=$this->stage($tid,Input::int($d['stage_id']??0,1,PHP_INT_MAX));$this->clearStage($tid,(int)$stage['id']);break;
                case 'swiss_next':
                    $stage=$this->stage($tid,Input::int($d['stage_id']??0,1,PHP_INT_MAX));if ($stage['format_type']!=='swiss') throw new Problem('此阶段不是瑞士轮。');
                    $history=$this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=? ORDER BY round_number,id',[$tid,$stage['id']]);if (!$history) throw new Problem('请先生成首轮赛程。');
                    foreach ($history as $m) if (!MatchService::resolved($m)) throw new Problem('当前轮次尚未全部结束。');
                    $round=max(array_column($history,'round_number'))+1;$c=json_decode($stage['configuration_json'],true);if ($round>$c['rounds']) throw new Problem('已达到最大轮数。');
                    $rows=$this->ranked($tid,$stage);$active=array_values(array_filter($rows,fn($r)=>$r['wins']<$c['qualify_wins'] && $r['losses']<$c['eliminate_losses']));
                    if (count($active)<1) throw new Problem('瑞士轮已结束，无需继续配对。');
                    $pair=Swiss::pair($active,$history,$round,(bool)($d['allow_rematches']??false),false);$this->matches->insertNodes($tid,$stage,$pair['nodes']);$result=['message'=>$pair['rematches']?'新轮次已生成（包含管理员允许的重复对阵）。':'新轮次已生成。'];break;
                case 'swiss_undo':
                    $stage=$this->stage($tid,Input::int($d['stage_id']??0,1,PHP_INT_MAX));if ($stage['format_type']!=='swiss') throw new Problem('此阶段不是瑞士轮。');$this->assertNoNext($tid,$stage);
                    $last=(int)($this->db->one('SELECT MAX(round_number) AS n FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$stage['id']])['n']??0);if ($last<=1) throw new Problem('首轮请使用重置阶段。');
                    $rows=$this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=? AND round_number=?',[$tid,$stage['id'],$last]);foreach ($rows as $m) if (!$m['is_bye'] && (MatchService::effectiveStatus($m)==='live' || MatchService::resolved($m))) throw new Problem('最后一轮已开始或结束，请先撤回结果并将时间调整到未来。',409);
                    $this->db->run('DELETE mm FROM match_maps mm JOIN matches m ON m.id=mm.match_id WHERE mm.tournament_id=? AND m.stage_id=? AND m.round_number=?',[$tid,$stage['id'],$last]);
                    $this->deleteMatchAttachments($tid,(int)$stage['id'],$last);
                    $this->db->run('DELETE FROM matches WHERE tournament_id=? AND stage_id=? AND round_number=?',[$tid,$stage['id'],$last]);break;
                case 'stage_draw': $this->redraw($tid,$this->stage($tid,Input::int($d['stage_id']??0,1,PHP_INT_MAX)),$d);break;
                case 'match_edit': $this->matches->edit($tid,$t,$d);break;
                case 'batch':
                    $ids=$d['ids']??[];if (!is_array($ids) || !$ids || count($ids)>1000) throw new Problem('请选择 1 至 1000 场比赛。');$ids=array_values(array_unique(array_map(fn($id)=>Input::int($id,1,PHP_INT_MAX),$ids)));sort($ids,SORT_NUMERIC);
                    $start=Input::time($d['scheduled_at']??null,$t['timezone']);$interval=Input::int($d['interval']??0,0,10080);$bo=empty($d['best_of'])?null:Input::bo($d['best_of']);
                    foreach ($ids as $i=>$id) {
                        $m=$this->db->one('SELECT * FROM matches WHERE tournament_id=? AND id=?',[$tid,$id]);if (!$m) throw new Problem('所选比赛不存在或不属于此赛事。',403);
                        if ($m['is_bye'] || !$m['is_active'] || MatchService::resolved($m)) throw new Problem('批量编辑仅支持已启用且未结束的非轮空比赛。');
                        $at=$start?gmdate('Y-m-d H:i:s',strtotime($start.' UTC')+$i*$interval*60):null;
                        $this->db->run('UPDATE matches SET scheduled_at=?,best_of=?,version=version+1 WHERE tournament_id=? AND id=?',[$at,$bo??$m['best_of'],$tid,$id]);
                    }break;
                case 'advance':
                    $preview=$this->advancement($tid);if (($d['fingerprint']??'')!==$preview['fingerprint']) throw new Problem('晋级数据已变化，请重新预览。',409);
                    $stage=$this->db->one('SELECT * FROM stages WHERE tournament_id=? AND stage_order=2',[$tid]);
                    if ($stage['locked']) throw new Problem('第二阶段已锁定。');
                    foreach ($preview['teams'] as $i=>$team) $this->db->run('INSERT INTO stage_teams (tournament_id,stage_id,team_id,seed) VALUES (?,?,?,?)',[$tid,$stage['id'],$team['team_id'],$i+1]);
                    $this->db->run('UPDATE stages SET locked=1 WHERE id=? AND tournament_id=?',[$stage['id'],$tid]);$draw=$d;if (in_array($stage['format_type'],['single','double','swiss'],true) && !isset($draw['pairings'])) $draw['pairings']=$preview['pairings'];if (in_array($stage['format_type'],['round_robin','double_round_robin'],true) && !isset($draw['groups'])) $draw['groups']=$preview['groups'];$this->generate($tid,$stage,$draw,true);break;
                case 'logo_delete': $this->db->run('UPDATE tournaments SET logo_path=NULL WHERE id=?',[$tid]);break;
                default: throw new Problem('未知操作。',404);
            }
            $this->refresh($tid);$after=$this->state($tid);unset($after['server_time']);
            $this->audit->append($tid,$action,'tournament',$tid,$before,$action==='password'?['password_reset'=>true,'state'=>$after]:$after);
            return $result+['slug'=>$after['tournament']['slug'],'message'=>'操作成功。'];
        });
    }
    private function assertNoNext(int $tid,array $stage): void { if ($this->db->one('SELECT id FROM stages WHERE tournament_id=? AND stage_order>? AND locked=1',[$tid,$stage['stage_order']])) throw new Problem('后续阶段已锁定，请先重置第二阶段。',409); }
    private function clearStage(int $tid,int $sid): void {
        $s=$this->stage($tid,$sid);$this->assertNoNext($tid,$s);
        $this->deleteMatchAttachments($tid,$sid);
        $this->db->run('DELETE mm FROM match_maps mm JOIN matches m ON m.id=mm.match_id WHERE mm.tournament_id=? AND m.stage_id=?',[$tid,$sid]);
        $this->db->run('DELETE g FROM match_games g JOIN matches m ON m.id=g.match_id WHERE m.tournament_id=? AND m.stage_id=?',[$tid,$sid]);
        $this->db->run('DELETE d FROM match_dependencies d JOIN matches m ON m.id=d.target_match_id WHERE d.tournament_id=? AND m.stage_id=?',[$tid,$sid]);
        $this->db->run('DELETE FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run('DELETE FROM standings WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run('DELETE FROM group_members WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run('DELETE FROM `groups` WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run('DELETE FROM stage_teams WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run("UPDATE stages SET status='pending',locked=0 WHERE tournament_id=? AND id=?",[$tid,$sid]);
    }
    private function generate(int $tid,array $stage,array $d,bool $second=false): void {
        $sid=(int)$stage['id'];if ($this->db->one('SELECT id FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$sid])) throw new Problem('赛程已生成，不能重复生成。');
        $c=json_decode($stage['configuration_json'],true);
        if (!$second) {
            $teams=$this->db->all('SELECT id FROM teams WHERE tournament_id=? ORDER BY seed,id',[$tid]);if (count($teams)<2) throw new Problem('至少需要两支队伍。');$ids=array_map('intval',array_column($teams,'id'));if ($c['seed_mode']==='random') shuffle($ids);
            foreach ($ids as $i=>$id) $this->db->run('INSERT INTO stage_teams (tournament_id,stage_id,team_id,seed) VALUES (?,?,?,?)',[$tid,$sid,$id,$i+1]);
        }
        $entries=$this->db->all('SELECT team_id,seed FROM stage_teams WHERE tournament_id=? AND stage_id=? ORDER BY seed',[$tid,$sid]);$ids=array_map('intval',array_column($entries,'team_id'));if (count($ids)<2) throw new Problem('阶段至少需要两支队伍。');
        $format=$stage['format_type'];
        if (in_array($format,['single','double'],true)) {
            if (isset($d['pairings'])) $ids=StageDraw::seedOrder(StageDraw::validatePairs($d['pairings'],$ids,true));
            elseif (!$second && $c['initial_pairing']==='adjacent') { $size=2;while ($size<count($ids)) $size*=2;$slots=array_pad($ids,$size,null);$new=[];foreach (Elimination::seeds($size) as $pos=>$seed) $new[$seed-1]=$slots[$pos];ksort($new);$ids=array_values($new); }
            $this->matches->insertNodes($tid,$stage,Elimination::generate($ids,$format==='double',$c['third'],$c['reset']));
        } elseif (in_array($format,['round_robin','double_round_robin'],true)) {
            $groups=[];
            if (!empty($d['groups'])) {
                $used=[];if (!is_array($d['groups']) || count($d['groups'])!==(int)$c['groups']) throw new Problem('分组数量必须与阶段设置一致。');
                foreach ($d['groups'] as $group) {
                    if (!is_array($group) || !is_array($group['teams']??null)) throw new Problem('分组必须包含队伍列表。');
                    $name=Input::text($group['name']??'',100);$members=array_map(fn($id)=>Input::int($id,1,PHP_INT_MAX),$group['teams']??[]);if (count($members)<2) throw new Problem('每组至少需要两支队伍。');
                    foreach ($members as $id) { if (!in_array($id,$ids,true) || isset($used[$id])) throw new Problem('分组含有重复或无效队伍。');$used[$id]=true; }$groups[]=['name'=>$name,'teams'=>$members];
                }
                if (count($used)!==count($ids)) throw new Problem('手动分组必须包含全部参赛队伍。');
            } else {
                if ($c['groups']>intdiv(count($ids),2)) throw new Problem('小组数量过多，每组至少两队。');
                for ($i=0;$i<$c['groups'];$i++) $groups[$i]=['name'=>'第 '.($i+1).' 组','teams'=>[]];
                foreach ($ids as $i=>$id) { $row=intdiv($i,$c['groups']);$index=$row%2===0?$i%$c['groups']:$c['groups']-1-$i%$c['groups'];$groups[$index]['teams'][]=$id; }
            }
            foreach ($groups as $g) {
                $this->db->run('INSERT INTO `groups` (tournament_id,stage_id,name) VALUES (?,?,?)',[$tid,$sid,$g['name']]);$gid=$this->db->id();foreach ($g['teams'] as $id) $this->db->run('INSERT INTO group_members (tournament_id,stage_id,group_id,team_id) VALUES (?,?,?,?)',[$tid,$sid,$gid,$id]);$this->matches->insertNodes($tid,$stage,RoundRobin::generate($g['teams'],$format==='double_round_robin'),$gid);
            }
        } else {
            if (isset($d['pairings'])) {
                $pairs=StageDraw::validatePairs($d['pairings'],$ids,false);$nodes=[];foreach ($pairs as $i=>$pair) $nodes[]=['key'=>'S1-'.$i,'bracket'=>'swiss','round'=>1,'a'=>$pair['a'],'b'=>$pair['b']];
            } else { $rows=array_map(fn($t)=>['team_id'=>(int)$t['team_id'],'seed'=>(int)$t['seed'],'wins'=>0,'losses'=>0],$entries);$pair=Swiss::pair($rows,[],1,false,$c['seed_mode']==='random');$nodes=$pair['nodes']; }
            $this->matches->insertNodes($tid,$stage,$nodes);
        }
        $this->db->run("UPDATE stages SET status='running' WHERE tournament_id=? AND id=?",[$tid,$sid]);
    }
    public function ranked(int $tid,array $stage): array {
        $rows=$this->db->all('SELECT s.*,t.name,st.seed FROM standings s JOIN teams t ON t.id=s.team_id JOIN stage_teams st ON st.stage_id=s.stage_id AND st.team_id=s.team_id WHERE s.tournament_id=? AND s.stage_id=? ORDER BY s.rank_position,st.seed,t.id',[$tid,$stage['id']]);
        if (in_array($stage['format_type'],['round_robin','double_round_robin'],true)) {
            $teams=$this->db->all('SELECT st.team_id,st.seed,t.name FROM stage_teams st JOIN teams t ON t.id=st.team_id WHERE st.tournament_id=? AND st.stage_id=?',[$tid,$stage['id']]);
            $matches=$this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$stage['id']]);
            $matches=StandingsService::withGames($this->db,$matches,$tid,(int)$stage['id']);
            $global=StandingsService::calculate($teams,$matches,json_decode($stage['configuration_json'],true));$original=[];foreach ($rows as $r) $original[$r['team_id']]=$r;
            $rows=[];foreach ($global as $r) { $r['group_rank']=$original[$r['team_id']]['rank_position'];$r['group_id']=$original[$r['team_id']]['group_id'];$rows[]=$r; }
        }
        if (in_array($stage['format_type'],['single','double'],true)) {
            $matches=$this->db->all("SELECT * FROM matches WHERE tournament_id=? AND stage_id=? AND (status='completed' OR (status='cancelled' AND forfeit_team_id IS NOT NULL AND winner_id IS NOT NULL)) AND is_active=1 ORDER BY id",[$tid,$stage['id']]);$placement=[];$elimination=[];
            foreach ($matches as $m) {
                if (!$m['winner_id'] || $m['is_bye']) continue;$loser=$m['winner_id']==$m['team_a_id']?$m['team_b_id']:$m['team_a_id'];
                $priority=($m['bracket']==='final'?1000:($m['bracket']==='lower'?500:0))+(int)$m['round_number'];$elimination[$loser]=$priority;
                if ($m['bracket']==='third') { $placement[$m['winner_id']]=3;$placement[$loser]=4; }
            }
            $finals=array_values(array_filter($matches,fn($m)=>$stage['format_type']==='double'?$m['bracket']==='final':$m['bracket']==='upper'));
            if ($finals) { $f=end($finals);if ($f['winner_id']) { $placement[$f['winner_id']]=1;$loser=$f['winner_id']==$f['team_a_id']?$f['team_b_id']:$f['team_a_id'];if ($loser) $placement[$loser]=2; } }
            usort($rows,fn($a,$b)=>(($placement[$a['team_id']]??100)<=>($placement[$b['team_id']]??100)) ?: (($elimination[$b['team_id']]??-1)<=>($elimination[$a['team_id']]??-1)) ?: ($a['seed']<=>$b['seed']));
        }
        return $rows;
    }
    private function refresh(int $tid): void {
        foreach ($this->db->all('SELECT * FROM stages WHERE tournament_id=? ORDER BY stage_order',[$tid]) as $s) {
            $sid=(int)$s['id'];$rows=$this->standings->recalculate($tid,$sid);$matches=$this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=? AND is_active=1',[$tid,$sid]);$done=(bool)$matches;
            foreach ($matches as $m) if (!MatchService::resolved($m)) $done=false;
            if ($done && $s['format_type']==='swiss') { $c=json_decode($s['configuration_json'],true);$active=array_filter($rows,fn($r)=>$r['wins']<$c['qualify_wins'] && $r['losses']<$c['eliminate_losses']);if ($active && max(array_column($matches,'round_number'))<$c['rounds']) $done=false; }
            if ($done && in_array($s['format_type'],['single','double'],true)) foreach ($this->ranked($tid,$s) as $i=>$row) $this->db->run('UPDATE standings SET rank_position=? WHERE tournament_id=? AND stage_id=? AND team_id=?',[$i+1,$tid,$sid,$row['team_id']]);
            $this->db->run('UPDATE stages SET status=? WHERE tournament_id=? AND id=?',[$done?'completed':($matches?'running':'pending'),$tid,$sid]);
        }
    }
    public function advancement(int $tid): array {
        $first=$this->db->one('SELECT * FROM stages WHERE tournament_id=? AND stage_order=1',[$tid]);$second=$this->db->one('SELECT * FROM stages WHERE tournament_id=? AND stage_order=2',[$tid]);if (!$second) throw new Problem('此赛事没有第二阶段。');if ($first['status']!=='completed') throw new Problem('第一阶段尚未结束。');
        $c=json_decode($first['configuration_json'],true);$rows=$this->ranked($tid,$first);$groupNames=[];
        foreach ($this->db->all('SELECT id,name FROM `groups` WHERE tournament_id=? AND stage_id=?',[$tid,$first['id']]) as $group) $groupNames[$group['id']]=$group['name'];
        if ($first['format_type']==='swiss') {
            $rows=array_values(array_filter($rows,fn($r)=>(int)$r['wins']>=$c['qualify_wins']));
            usort($rows,fn($a,$b)=>((int)$b['wins']<=>(int)$a['wins']) ?: ((int)$a['losses']<=>(int)$b['losses']) ?: ((int)$a['rank_position']<=>(int)$b['rank_position']) ?: ((int)$a['seed']<=>(int)$b['seed']));
        } elseif (in_array($first['format_type'],['round_robin','double_round_robin'],true)) {
            if ($c['per_group']>0) $rows=array_values(array_filter($rows,fn($r)=>(int)$r['group_rank']<=$c['per_group']));
            // Keep group finish above cross-group statistics, preserving the configured tie-break order within each tier.
            foreach ($rows as $i=>&$r) $r['_strength']=$i;unset($r);
            usort($rows,fn($a,$b)=>((int)$a['group_rank']<=>(int)$b['group_rank']) ?: ($a['_strength']<=>$b['_strength']));
        }
        if (count($rows)<$c['advance_count']) throw new Problem('符合晋级条件的队伍不足，请检查晋级数量和每组名额。');
        $rows=array_slice($rows,0,$c['advance_count']);
        $teams=array_map(fn($r)=>['team_id'=>(int)$r['team_id'],'name'=>$r['name'],'wins'=>(int)$r['wins'],'losses'=>(int)$r['losses'],'points'=>(int)$r['points'],'source_group_id'=>isset($r['group_rank'])?(int)$r['group_id']:null,'source_group_name'=>isset($r['group_rank'])?($groupNames[$r['group_id']]??''):null,'source_rank'=>(int)($r['group_rank']??$r['rank_position']),'source_label'=>isset($r['group_rank'])?($groupNames[$r['group_id']]??'小组').'第 '.$r['group_rank'].' 名':($first['format_type']==='swiss'?$r['wins'].'-'.$r['losses']:'第 '.$r['rank_position'].' 名')],$rows);
        $config=json_decode($second['configuration_json'],true);$isGroups=in_array($second['format_type'],['round_robin','double_round_robin'],true);
        $pairs=$isGroups?[]:StageDraw::pairs($teams,$second['format_type']!=='swiss');$groups=$isGroups?StageDraw::groups($teams,(int)$config['groups']):[];
        return ['teams'=>$teams,'pairings'=>$pairs,'groups'=>$groups,'second_stage_id'=>(int)$second['id'],'second_format'=>$second['format_type'],'fingerprint'=>hash('sha256',json_encode_safe([$teams,$pairs,$groups,$first['configuration_json'],$second['configuration_json']]))];
    }
    private function redraw(int $tid,array $stage,array $input): void {
        $this->assertNoNext($tid,$stage);$sid=(int)$stage['id'];
        $old=$this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=? ORDER BY id',[$tid,$sid]);if (!$old) throw new Problem('请在生成赛程或锁定晋级时设置分组与首轮对阵。');
        $hasAttachments=$this->db->one(
            'SELECT a.id FROM attachments a JOIN matches m ON m.tournament_id=a.tournament_id AND m.id=a.match_id WHERE a.tournament_id=? AND m.stage_id=? LIMIT 1',
            [$tid,$sid]
        );
        if ($hasAttachments) throw new Problem('本阶段已有比赛附件。请先下载并移除附件，再重排赛程。',409);
        foreach ($old as $match) {
            if ((!$match['is_bye'] && (MatchService::resolved($match) || MatchService::effectiveStatus($match)==='live')) || (!empty($match['round_number']) && $stage['format_type']==='swiss' && (int)$match['round_number']>1)) throw new Problem('本阶段已有开赛、比分或后续瑞士轮，不能重排分组与首轮对阵。请先撤回结果并处理后续轮次。',409);
        }
        if ($this->db->one('SELECT g.id FROM match_games g JOIN matches m ON m.id=g.match_id WHERE m.tournament_id=? AND m.stage_id=? LIMIT 1',[$tid,$sid])) throw new Problem('本阶段已有逐局比分，不能重排分组与首轮对阵。',409);
        $entries=$this->db->all('SELECT team_id,seed FROM stage_teams WHERE tournament_id=? AND stage_id=? ORDER BY seed',[$tid,$sid]);$ids=array_map('intval',array_column($entries,'team_id'));
        $rr=in_array($stage['format_type'],['round_robin','double_round_robin'],true);
        if ($rr && empty($input['groups'])) throw new Problem('请提交完整的小组分配。');
        if (!$rr) $input['pairings']=StageDraw::validatePairs($input['pairings']??null,$ids,$stage['format_type']!=='swiss');
        $saved=[];foreach ($old as $match) {
            $a=(int)$match['team_a_id'];$b=(int)$match['team_b_id'];
            $key=(!$a && !$b)?'node:'.$match['node_key']:($stage['format_type']==='double_round_robin'?$a.':'.$b:min($a,$b).':'.max($a,$b));
            $saved[$key]=['match'=>$match,'maps'=>MapStore::forMatch($this->db,$tid,(int)$match['id'])];
        }
        $this->db->run('DELETE mm FROM match_maps mm JOIN matches m ON m.id=mm.match_id WHERE mm.tournament_id=? AND m.stage_id=?',[$tid,$sid]);
        $this->db->run('DELETE d FROM match_dependencies d JOIN matches m ON m.id=d.target_match_id WHERE d.tournament_id=? AND m.stage_id=?',[$tid,$sid]);
        $this->db->run('DELETE FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run('DELETE FROM standings WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run('DELETE FROM group_members WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);$this->db->run('DELETE FROM `groups` WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);
        $this->generate($tid,$stage,$input,true);
        foreach ($this->db->all('SELECT * FROM matches WHERE tournament_id=? AND stage_id=?',[$tid,$sid]) as $match) {
            $a=(int)$match['team_a_id'];$b=(int)$match['team_b_id'];$key=(!$a && !$b)?'node:'.$match['node_key']:($stage['format_type']==='double_round_robin'?$a.':'.$b:min($a,$b).':'.max($a,$b));if (!isset($saved[$key])) continue;
            $prior=$saved[$key]['match'];$this->db->run('UPDATE matches SET scheduled_at=?,description=?,status=? WHERE tournament_id=? AND id=?',[$prior['scheduled_at'],$prior['description'],$match['is_bye']?'completed':($prior['is_bye']?'scheduled':$prior['status']),$tid,$match['id']]);
            MapStore::save($this->db,$tid,(int)$match['id'],array_values(array_filter($saved[$key]['maps'],fn($map)=>(int)$map['game_number']<=(int)$match['best_of'])));
        }
    }
    private function deleteMatchAttachments(int $tid, int $stageId, ?int $round = null): void {
        $sql = 'DELETE a FROM attachments a JOIN matches m ON m.tournament_id=a.tournament_id AND m.id=a.match_id
                WHERE a.tournament_id=? AND m.stage_id=?';
        $params = [$tid, $stageId];
        if ($round !== null) {
            $sql .= ' AND m.round_number=?';
            $params[] = $round;
        }
        $this->db->run($sql, $params);
    }
    public function upload(int $tid,array $file): array {
        $this->auth->requireTournament($tid);
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size']??0)>10*1024*1024 || !is_uploaded_file($file['tmp_name']??'')) throw new Problem('请选择不超过 10 MB 的 PNG、JPEG 或 WebP 图片。');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$types=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];$info=@getimagesize($file['tmp_name']);
        if (!isset($types[$mime]) || !$info || $info['mime']!==$mime || $info[0]>4096 || $info[1]>4096) throw new Problem('图片类型、内容或尺寸无效（最大 4096×4096）。');
        $path='/uploads/'.bin2hex(random_bytes(20)).'.'.$types[$mime];$target=dirname(__DIR__).'/public'.$path;
        if (!move_uploaded_file($file['tmp_name'],$target)) throw new Problem('无法保存图片，请检查 uploads 目录权限。',500);
        try { return $this->db->transaction(function () use ($tid,$path): array { $old=$this->db->one('SELECT logo_path FROM tournaments WHERE id=? FOR UPDATE',[$tid]);$this->auth->requireTournament($tid);$this->db->run('UPDATE tournaments SET logo_path=? WHERE id=?',[$path,$tid]);$this->audit->append($tid,'logo_upload','tournament',$tid,$old,['logo_path'=>$path]);return ['message'=>'Logo 已更新。']; }); }
        catch (\Throwable $e) { unlink($target);throw $e; }
    }
}
