<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\{Database,Auth,AuditLogger,TournamentService,AttachmentService,Problem,Input};
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';$method=$_SERVER['REQUEST_METHOD']??'GET';$api=str_starts_with($path,'/api/');
function respond(mixed $data,int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode_safe($data);exit; }
try {
    if ($path==='/' && $method==='GET') { view('home',['title'=>'赛事中心']);exit; }
    $db=new Database($config);$auth=new Auth($db,$config['secure']);$audit=new AuditLogger($db,$auth);$service=new TournamentService($db,$auth,$audit);
    if ($method==='POST') {
        $auth->verifyCsrf();
        $isLogoUpload=(bool)preg_match('~^/api/tournaments/[a-z0-9-]+/logo$~',$path);
        $isAttachmentUpload=(bool)preg_match('~^/api/tournaments/[a-z0-9-]+/attachment_upload$~',$path);
        $requestLimit=(($isLogoUpload || $isAttachmentUpload)?12:3)*1024*1024;
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>$requestLimit) {
            $message=$isLogoUpload?'上传请求过大，请选择不超过 10 MB 的 Logo。':'请求过大。';
            if ($isAttachmentUpload) $message='上传请求过大，单份附件不能超过 10 MB。';
            throw new Problem($message,413);
        }
        if (str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) { $input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);if (!is_array($input)) throw new Problem('请求数据无效。'); }else $input=$_POST;
    }
    if ($path==='/admin' && $method==='GET') { view($auth->global()?'shell':'login',['title'=>'总管理后台','mode'=>'global','csrf'=>$auth->csrf(),'slug'=>null,'name'=>'网站总管理后台']);exit; }
    if ($path==='/api/admin/login' && $method==='POST') { $auth->login(null,Input::text($input['username']??'',100),Input::text($input['password']??'',200));respond(['redirect'=>'/admin']); }
    if ($path==='/api/admin/logout' && $method==='POST') { $auth->logout(null);respond(['redirect'=>'/admin']); }
    if (str_starts_with($path,'/api/admin/')) {
        $auth->requireGlobal();
        if ($path==='/api/admin/state' && $method==='GET') respond(['tournaments'=>$db->all('SELECT t.*, (SELECT COUNT(*) FROM teams te WHERE te.tournament_id=t.id) AS team_count FROM tournaments t ORDER BY t.id DESC')]);
        if ($path==='/api/admin/create' && $method==='POST') respond($service->create($input),201);
        if ($path==='/api/admin/tournament_copy' && $method==='POST') {
            respond($service->copyTournament(Input::int($input['id']??null,1,PHP_INT_MAX),$input),201);
        }
        if ($path==='/api/admin/tournament_delete' && $method==='POST') {
            respond($service->deleteTournament(Input::int($input['id']??null,1,PHP_INT_MAX),$input['confirmation']??null));
        }
        if ($path==='/api/admin/verify-logs' && $method==='GET') respond($db->transaction(fn()=>$audit->verify()));
        if ($path==='/api/admin/logs' && $method==='GET') { $cursor=Input::int($_GET['before']??PHP_INT_MAX,1,PHP_INT_MAX);respond(['logs'=>$db->all('SELECT id,tournament_id,actor_type,action,target_type,target_id,old_data,new_data,ip_address,created_at,previous_hash,entry_hash FROM audit_logs WHERE id<? ORDER BY id DESC LIMIT 50',[$cursor])]); }
    }
    if (preg_match('~^/tournaments/([a-z0-9-]+)(/admin)?/?$~',$path,$m) && $method==='GET') {
        $t=$service->bySlug($m[1]);$admin=isset($m[2]);if ($t['status']==='disabled' && !$auth->global()) throw new Problem('赛事不存在或已停用。',404);
        view($admin && !$auth->can((int)$t['id'])?'login':'shell',['title'=>$t['name'].($admin?' · 管理后台':''),'mode'=>$admin?'tournament':'public','csrf'=>$auth->csrf(),'slug'=>$t['slug'],'name'=>$t['name'],'isGlobal'=>$auth->global()]);exit;
    }
    if (preg_match('~^/api/tournaments/([a-z0-9-]+)(?:/([a-z_-]+))?$~',$path,$m)) {
        $t=$service->bySlug($m[1]);$tid=(int)$t['id'];$op=$m[2]??'state';
        if ($t['status']==='disabled' && !$auth->global()) throw new Problem('赛事不存在或已停用。',404);
        if ($op==='login' && $method==='POST') { $auth->login($tid,'',Input::text($input['password']??'',200));respond(['redirect'=>'/tournaments/'.$t['slug'].'/admin']); }
        if ($op==='logout' && $method==='POST') { $auth->logout($tid);respond(['redirect'=>'/tournaments/'.$t['slug'].'/admin']); }
        $attachments=new AttachmentService($db,$auth,$audit);
        if ($op==='state' && $method==='GET') {
            $state=$service->state($tid);
            $state['public_attachments']=$attachments->listFiles($tid,null,false);
            respond($state);
        }
        if ($op==='attachments' && $method==='GET') {
            $matchId=isset($_GET['match_id'])?Input::int($_GET['match_id'],1,PHP_INT_MAX):null;
            respond(['attachments'=>$attachments->listFiles($tid,$matchId,($_GET['admin']??null)==='1')]);
        }
        if ($op==='attachment_download' && $method==='GET') {
            $attachments->download($tid,Input::int($_GET['id']??null,1,PHP_INT_MAX));
        }
        $auth->requireTournament($tid);
        if ($op==='attachment_upload' && $method==='POST') {
            $matchId=isset($input['match_id'])?Input::int($input['match_id'],1,PHP_INT_MAX):null;
            $isPublic=Input::choice($input['visibility']??'private',['private','public'])==='public';
            $attachments->upload($tid,$matchId,$isPublic,$_FILES['attachment']??[]);
            respond(['message'=>'附件已上传。'],201);
        }
        if ($op==='attachment_visibility' && $method==='POST') {
            $isPublic=Input::choice($input['visibility']??null,['private','public'])==='public';
            $attachments->changeVisibility($tid,Input::int($input['id']??null,1,PHP_INT_MAX),$isPublic);
            respond(['message'=>'附件可见范围已更新。']);
        }
        if ($op==='attachment_delete' && $method==='POST') {
            $attachments->delete($tid,Input::int($input['id']??null,1,PHP_INT_MAX));
            respond(['message'=>'附件已删除。']);
        }
        if ($op==='logs' && $method==='GET') { $cursor=Input::int($_GET['before']??PHP_INT_MAX,1,PHP_INT_MAX);respond(['logs'=>$db->all('SELECT id,tournament_id,actor_type,action,target_type,target_id,old_data,new_data,ip_address,created_at,previous_hash,entry_hash FROM audit_logs WHERE tournament_id=? AND id<? ORDER BY id DESC LIMIT 50',[$tid,$cursor])]); }
        if ($op==='advance-preview' && $method==='GET') respond($service->advancement($tid));
        if ($op==='logo' && $method==='POST') respond($service->upload($tid,$_FILES['logo']??[]));
        if ($method==='POST') respond($service->mutate($tid,$op,$input));
    }
    throw new Problem('页面不存在。',404);
} catch (Problem $e) {
    if ($api) respond(['error'=>$e->getMessage()],$e->status);
    http_response_code($e->status);view('error',['title'=>'访问提示','message'=>$e->getMessage(),'code'=>$e->status]);
} catch (\JsonException $e) {
    if ($api) respond(['error'=>'JSON 数据格式无效。'],422);
    http_response_code(422);view('error',['title'=>'请求无效','message'=>'请求数据格式无效。','code'=>422]);
} catch (\PDOException $e) {
    error_log((string)$e);
    $duplicate=$e->getCode()==='23000';$message=$duplicate?'名称或 Slug 已存在，或数据仍被其他记录引用。':'数据库服务暂不可用，请检查部署配置或稍后重试。';
    if ($api) respond(['error'=>$message],$duplicate?409:503);
    http_response_code(503);view('error',['title'=>'服务暂不可用','message'=>$message,'code'=>503]);
} catch (\Throwable $e) {
    error_log((string)$e);if ($api) respond(['error'=>'操作失败，请稍后重试并查看服务器日志。'],500);
    http_response_code(500);view('error',['title'=>'服务错误','message'=>'服务暂不可用，请稍后重试。','code'=>500]);
}
