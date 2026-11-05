<?php
declare(strict_types=1);
namespace App;
final class StandingsService {
    public static function calculate(array $teams,array $matches,array $config): array {
        $rows=[];$played=[];$head=[];$headRounds=[];
        foreach ($teams as $t) $rows[(int)$t['team_id']]=['team_id'=>(int)$t['team_id'],'name'=>$t['name']??'','seed'=>(int)$t['seed'],'wins'=>0,'losses'=>0,'points'=>0,'game_difference'=>0,'buchholz'=>0,'byes'=>0];
        foreach ($matches as $m) {
            if (!MatchService::resolved($m) || !$m['winner_id'] || !$m['is_active']) continue;
            $w=(int)$m['winner_id'];$a=(int)$m['team_a_id'];$b=(int)$m['team_b_id'];if (!isset($rows[$w])) continue;
            $loser=$w===$a?$b:$a;
            $rows[$w]['wins']++;$rows[$w]['points']+=(int)($config['win_points']??1);
            if ($m['is_bye']) { $rows[$w]['byes']++;continue; }
            if (!isset($rows[$loser])) continue;
            $rows[$loser]['losses']++;$rows[$loser]['points']+=(int)($config['loss_points']??0);
            $diff=(int)($m['score_a']??0)-(int)($m['score_b']??0);$rows[$a]['game_difference']+=$diff;$rows[$b]['game_difference']-=$diff;
            $played[$a][]=$b;$played[$b][]=$a;$head[$w][$loser]=($head[$w][$loser]??0)+1;
            $roundDiff=0;
            foreach ($m['games']??[] as $game) $roundDiff+=(int)$game['team_a_score']-(int)$game['team_b_score'];
            $headRounds[$a][$b]=($headRounds[$a][$b]??0)+$roundDiff;
            $headRounds[$b][$a]=($headRounds[$b][$a]??0)-$roundDiff;
        }
        foreach ($played as $id=>$opponents) foreach ($opponents as $opponent) $rows[$id]['buchholz']+=$rows[$opponent]['wins'];
        // Tie groups use a mini-league, avoiding a non-transitive pairwise comparator.
        $ordered=array_values($rows);$rules=array_values(array_unique([...Input::ranking($config['ranking']??['points','wins','game_difference','buchholz']),'seed']));
        $sort=function(array $bucket,int $index) use (&$sort,$rules,$head,$headRounds): array {
            if (count($bucket)<2 || $index>=count($rules)) { usort($bucket,fn($a,$b)=>$a['team_id']<=>$b['team_id']);return $bucket; }
            $rule=$rules[$index];$values=[];
            foreach ($bucket as $row) {
                $v=$row[$rule]??0;
                if ($rule==='head_to_head_wins' || $rule==='head_to_head_round') {
                    $matrix=$rule==='head_to_head_wins'?$head:$headRounds;
                    $v=0;foreach ($bucket as $other) $v+=$matrix[$row['team_id']][$other['team_id']]??0;
                }
                $values[$v][]=$row;
            }
            if ($rule==='seed') ksort($values,SORT_NUMERIC);else krsort($values,SORT_NUMERIC);
            $out=[];foreach ($values as $same) array_push($out,...$sort($same,$index+1));return $out;
        };
        $ordered=$sort($ordered,0);foreach ($ordered as $i=>&$row) { $row['rank_position']=$i+1;$row['qualified']=$row['wins']>=($config['qualify_wins']??PHP_INT_MAX);$row['eliminated']=$row['losses']>=($config['eliminate_losses']??PHP_INT_MAX); }unset($row);
        return $ordered;
    }
    public function __construct(private Database $db) {}
    public static function withGames(Database $db,array $matches,int $tid,int $sid): array {
        $games=$db->all('SELECT g.match_id,g.game_number,g.team_a_score,g.team_b_score FROM match_games g JOIN matches m ON m.id=g.match_id WHERE m.tournament_id=? AND m.stage_id=? ORDER BY g.game_number',[$tid,$sid]);
        $byMatch=[];foreach ($games as $g) $byMatch[$g['match_id']][]=$g;
        foreach ($matches as &$match) $match['games']=$byMatch[$match['id']]??[];unset($match);
        return $matches;
    }
    public function recalculate(int $tid,int $sid): array {
        $stage=$this->db->one('SELECT * FROM stages WHERE id=? AND tournament_id=?',[$sid,$tid]);$c=json_decode($stage['configuration_json'],true);
        $groups=$this->db->all('SELECT id FROM `groups` WHERE stage_id=? AND tournament_id=? ORDER BY id',[$sid,$tid]);
        if (!$groups) $groups=[['id'=>null]];
        $all=[];$this->db->run('DELETE FROM standings WHERE tournament_id=? AND stage_id=?',[$tid,$sid]);
        foreach ($groups as $g) {
            $teams=$g['id'] ? $this->db->all('SELECT st.team_id,st.seed,t.name FROM stage_teams st JOIN teams t ON t.id=st.team_id JOIN group_members gm ON gm.team_id=st.team_id AND gm.stage_id=st.stage_id WHERE st.stage_id=? AND st.tournament_id=? AND gm.group_id=?',[$sid,$tid,$g['id']]) : $this->db->all('SELECT st.team_id,st.seed,t.name FROM stage_teams st JOIN teams t ON t.id=st.team_id WHERE st.stage_id=? AND st.tournament_id=?',[$sid,$tid]);
            $matches=$g['id'] ? $this->db->all('SELECT * FROM matches WHERE stage_id=? AND tournament_id=? AND group_id=?',[$sid,$tid,$g['id']]) : $this->db->all('SELECT * FROM matches WHERE stage_id=? AND tournament_id=?',[$sid,$tid]);
            $matches=self::withGames($this->db,$matches,$tid,$sid);
            foreach (self::calculate($teams,$matches,$c) as $r) {
                $this->db->run('INSERT INTO standings (tournament_id,stage_id,group_id,team_id,wins,losses,points,game_difference,buchholz,rank_position) VALUES (?,?,?,?,?,?,?,?,?,?)',[$tid,$sid,$g['id'],$r['team_id'],$r['wins'],$r['losses'],$r['points'],$r['game_difference'],$r['buchholz'],$r['rank_position']]);$r['group_id']=$g['id'];$all[]=$r;
            }
        }
        return $all;
    }
}
