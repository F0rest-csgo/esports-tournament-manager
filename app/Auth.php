<?php
declare(strict_types=1);
namespace App;
final class Auth {
    public function __construct(private Database $db, bool $secure) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
            session_name('tournament_session');
            session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']); session_start();
        }
        if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 7200) $_SESSION=[];
        $_SESSION['last_seen']=time();
    }
    public function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public function verifyCsrf(): void { if (!hash_equals($this->csrf(), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? ''))) throw new Problem('安全令牌失效，请刷新页面重试。',419); }
    public function global(): bool { return isset($_SESSION['admin_id']); }
    public function can(int $tid): bool {
        if ($this->global()) return true;
        $session=$_SESSION['tournaments'][$tid] ?? null;
        if (!$session) return false;
        $row=$this->db->one("SELECT c.password_version FROM tournament_credentials c JOIN tournaments t ON t.id=c.tournament_id WHERE c.tournament_id=? AND t.status<>'disabled'",[$tid]);
        return $row && (int)$row['password_version']===$session['version'];
    }
    public function requireGlobal(): void { if (!$this->global()) throw new Problem('请先登录总管理后台。',403); }
    public function requireTournament(int $tid): void { if (!$this->can($tid)) throw new Problem('无权管理此赛事，请重新登录。',403); }
    public function actor(): string { return $this->global() ? 'global_admin:'.$_SESSION['admin_id'] : 'tournament_admin'; }
    public function login(?int $tid, string $username, string $password): void {
        $ip=$_SERVER['REMOTE_ADDR'] ?? 'cli';
        $scope=$tid===null ? 'global' : 'tournament:'.$tid;
        $keys=[hash('sha256',$scope.'|'.$ip),hash('sha256','all|'.$ip)];
        $success=false;
        $this->db->transaction(function () use ($tid,$username,$password,$keys,&$success): void {
            foreach ($keys as $key) {
                $this->db->run('INSERT IGNORE INTO login_attempts (attempt_key, failures, window_start) VALUES (?,0,UTC_TIMESTAMP())',[$key]);
                $a=$this->db->one('SELECT * FROM login_attempts WHERE attempt_key=? FOR UPDATE',[$key]);
                if (strtotime($a['window_start'].' UTC') < time()-900) { $this->db->run('UPDATE login_attempts SET failures=0,window_start=UTC_TIMESTAMP() WHERE attempt_key=?',[$key]); $a['failures']=0; }
                if ((int)$a['failures']>=10) throw new Problem('登录尝试过多，请 15 分钟后重试。',429);
            }
            $row=$tid===null ? $this->db->one('SELECT id,password_hash FROM admin_users WHERE username=?',[$username]) : $this->db->one("SELECT c.* FROM tournament_credentials c JOIN tournaments t ON t.id=c.tournament_id WHERE c.tournament_id=? AND t.status<>'disabled'",[$tid]);
            $hash=$row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            if (!password_verify($password,$hash) || !$row) {
                foreach ($keys as $key) $this->db->run('UPDATE login_attempts SET failures=failures+1 WHERE attempt_key=?',[$key]);
                return;
            }
            foreach ($keys as $key) $this->db->run('UPDATE login_attempts SET failures=0 WHERE attempt_key=?',[$key]);
            session_regenerate_id(true);
            if ($tid===null) $_SESSION['admin_id']=(int)$row['id'];
            else $_SESSION['tournaments'][$tid]=['version'=>(int)$row['password_version']];
            $_SESSION['csrf']=bin2hex(random_bytes(32));
            $success=true;
        });
        if (!$success) throw new Problem('账号或密码不正确。',401);
    }
    public function logout(?int $tid): void { if ($tid===null) unset($_SESSION['admin_id']); else unset($_SESSION['tournaments'][$tid]); session_regenerate_id(true); }
}
