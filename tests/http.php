<?php
declare(strict_types=1);
// Run only against an explicitly disposable local preview (see README).
$base=rtrim(getenv('TEST_BASE_URL')?:'http://127.0.0.1:8086','/');
if (getenv('TEST_DISPOSABLE')!=='1' || !preg_match('~^http://(127\.0\.0\.1|localhost):[0-9]+$~',$base)) {fwrite(STDERR,"Set TEST_DISPOSABLE=1 and a loopback TEST_BASE_URL.\n");exit(1);}
$cookies=[];$checks=0;
function ok(bool $v,string $m): void {global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
function req(string $path,?array $data=null,?string $csrf=null): array {global $base,$cookies;
    $headers=['Accept: application/json'];if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
    $o=['method'=>$data===null?'GET':'POST','ignore_errors'=>true,'timeout'=>15];
    if($data!==null){$headers[]='Content-Type: application/json';if($csrf!==null)$headers[]='X-CSRF-Token: '.$csrf;$o['content']=json_encode($data,JSON_THROW_ON_ERROR);}$o['header']=implode("\r\n",$headers);
    $f=fopen($base.$path,'r',false,stream_context_create(['http'=>$o]));if(!$f)throw new RuntimeException('HTTP connection failed');$body=stream_get_contents($f);$meta=stream_get_meta_data($f);fclose($f);$hs=$meta['wrapper_data'];preg_match('~HTTP/\S+ (\d+)~',$hs[0],$m);
    foreach($hs as$h)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$h,$c))$cookies[$c[1]]=$c[2];return [(int)$m[1],$body,json_decode($body,true),$hs];
}
function csrf(string $path): string {[$s,$html]=req($path);ok($s===200,'HTML page loads');preg_match('/name="csrf-token" content="([a-f0-9]+)"/',$html,$m);return $m[1]??throw new RuntimeException('CSRF missing');}
function upload(string $path,string $csrf,string $name,string $content): array {global $base,$cookies;
    $boundary='qa'.bin2hex(random_bytes(8));$body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"logo\"; filename=\"".$name."\"\r\nContent-Type: image/png\r\n\r\n".$content."\r\n--".$boundary."--\r\n";
    $cookie=implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));$f=fopen($base.$path,'r',false,stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>15,'header'=>'Content-Type: multipart/form-data; boundary='.$boundary."\r\nX-CSRF-Token: ".$csrf."\r\nCookie: ".$cookie,'content'=>$body]]));$text=stream_get_contents($f);$meta=stream_get_meta_data($f);fclose($f);preg_match('~HTTP/\S+ (\d+)~',$meta['wrapper_data'][0],$m);return [(int)$m[1],$text];
}
try{
    [$status,$html]=req('/');ok($status===200&&!str_contains($html,'qa-invitational'),'homepage has no public listing');
    ok(req('/tournaments/does-not-exist')[0]===404,'proper missing tournament 404');ok(in_array(req('/.env')[0],[403,404],true),'private env inaccessible');ok(req('/app/Auth.php')[0]===404,'private source inaccessible');
    $token=csrf('/admin');$login=['username'=>getenv('TEST_ADMIN_USERNAME')?:'qa','password'=>getenv('TEST_ADMIN_PASSWORD')?:''];
    ok(req('/api/admin/login',$login)[0]===419,'login CSRF enforced');ok(req('/api/admin/login',$login,$token)[0]===200,'global admin login');$token=csrf('/admin');
    $slug='http-test-'.bin2hex(random_bytes(4));$password=bin2hex(random_bytes(15));
    [$s,,$r]=req('/api/admin/create',['name'=>'HTTP 验收测试','slug'=>$slug,'password'=>$password,'score_mode'=>'winner','game'=>'valorant','default_bo'=>5,'game_win_score'=>1,'overtime'=>['enabled'=>false]],$token);ok($s===201,'create real tournament');$tid=$r['id'];$api='/api/tournaments/'.$slug;
    $otherSlug=$slug.'-other';[$s,,$other]=req('/api/admin/create',['name'=>'HTTP 隔离对照','slug'=>$otherSlug,'password'=>bin2hex(random_bytes(15)),'score_mode'=>'winner'],$token);ok($s===201,'create isolation target');$otherApi='/api/tournaments/'.$otherSlug;
    [,,$otherState]=req($otherApi.'/state');req($otherApi.'/teams_add',['names'=>"X\nY"],$token);req($otherApi.'/generate',['stage_id'=>$otherState['stages'][0]['id']],$token);[,,$otherState]=req($otherApi.'/state');$foreignMatch=$otherState['matches'][0]['id'];
    [$s,,$state]=req($api.'/state');ok($s===200,'public state');ok(!str_contains(json_encode($state),'password_hash')&&!str_contains(json_encode($state),$password),'no secret serialization');$sid=$state['stages'][0]['id'];
    ok($state['tournament']['game']==='valorant'&&$state['tournament']['game_win_score']===13&&$state['tournament']['overtime']['mode']==='win_by'&&!isset($state['tournament']['overtime']['block_size']),'server canonical preset ignores forged custom scoring');
    ok(req('/api/admin/create',['name'=>'Unsupported game','slug'=>$slug.'-invalid','password'=>$password,'game'=>'other'],$token)[0]===422,'only two supported game presets');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZ9kAAAAASUVORK5CYII=');ok(upload($api.'/logo',$token,'logo.png',$png)[0]===200,'valid image upload');ok(upload($api.'/logo',$token,'fake.png','<?php echo 123;')[0]===422,'fake image content rejected');[,,$withLogo]=req($api.'/state');ok((bool)preg_match('~^/uploads/[a-f0-9]{40}\.png$~',$withLogo['tournament']['logo_path']),'random image path');ok(req($withLogo['tournament']['logo_path'])[0]===200,'uploaded logo served');ok(req('/uploads/attack.php')[0]===404,'upload PHP execution blocked');ok(req($api.'/logo_delete',[],$token)[0]===200,'remove shared logo');
    ok(req($api.'/teams_add',['names'=>"A\nB\nC\nD"],$token)[0]===200,'batch add persisted teams');ok(req($api.'/generate',['stage_id'=>$sid],$token)[0]===200,'schedule via HTTP');[,,$state]=req($api.'/state');ok(count($state['matches'])===3,'matches persisted');ok(count(array_filter($state['matches'],fn($m)=>$m['best_of']!==1))===0,'new matches use stage BO, not tournament-level request');$match=$state['matches'][0];
    [$s,,$batch]=req($api.'/batch',['ids'=>[$match['id']],'scheduled_at'=>'2000-01-01T08:00','best_of'=>1,'interval'=>60],$token);ok($s===200,'batch time + BO');[,,$state]=req($api.'/state');$match=$state['matches'][0];ok($match['scheduled_at']==='2000-01-01 00:00:00'&&$match['effective_status']==='live','UTC storage and automatic live');
    ok(req($api.'/match_edit',['id'=>$match['id'],'version'=>$match['version'],'team_a_id'=>$match['team_a_id'],'team_b_id'=>$match['team_b_id'],'best_of'=>1,'status'=>'completed','winner_id'=>$match['team_a_id']],$token)[0]===200,'winner-only result');
    [,,$state]=req($api.'/state');ok($state['matches'][0]['winner_id']===$match['team_a_id']&&$state['matches'][0]['score_a']===null,'winner has no fabricated scores');
    ok(req('/api/admin/logout',[],$token)[0]===200,'global logout');$token=csrf('/tournaments/'.$slug.'/admin');
    ok(req($api.'/login',['password'=>$password],$token)[0]===200,'independent tournament login');$token=csrf('/tournaments/'.$slug.'/admin');
    ok(req('/api/admin/state')[0]===403,'event admin cannot enter global API');ok(req($otherApi.'/logs')[0]===403,'event admin cannot read other audit logs');ok(req($api.'/password',['password'=>'blocked-long-password'],$token)[0]===403,'event admin cannot change credential');
    ok(req($api.'/match_edit',['id'=>$foreignMatch,'version'=>1],$token)[0]===404,'foreign object ID rejected');ok(req($api.'/teams_add',['names'=>'FORGED'],'bad-token')[0]===419,'write CSRF enforced');
    [$s,,$logs]=req($api.'/logs');ok($s===200&&count($logs['logs'])>=5,'audit persisted all mutations');
    [,,$state]=req($api.'/state');ok(count($state['teams'])===4,'failed writes leave data unchanged');
    $token=csrf('/admin');ok(req('/api/admin/login',$login,$token)[0]===200,'global re-login');$token=csrf('/admin');ok(req('/api/admin/verify-logs')[0]===200,'HTTP audit verification');
    ok(req($api.'/status',['status'=>'disabled'],$token)[0]===200,'disable tournament');req($otherApi.'/status',['status'=>'disabled'],$token);req('/api/admin/logout',[],$token);ok(req('/tournaments/'.$slug)[0]===404,'disabled tournament hidden');ok(req($api.'/logs')[0]===404,'disabled session denied');
    echo "PASS $checks HTTP assertions\n";
}catch(Throwable $e){fwrite(STDERR,"FAIL: ".$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
