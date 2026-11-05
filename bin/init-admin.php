<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\{Database,AuditLogger,Input};
if (PHP_SAPI!=='cli') exit(1);
try {
    $username=Input::text($argv[1]??'admin',100);
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) throw new RuntimeException('Please pipe a password through stdin; do not put passwords in command arguments.');
    $password=rtrim(stream_get_contents(STDIN),"\r\n");
    if (strlen($password)<12 || strlen($password)>72) throw new RuntimeException('Password must be 12-72 bytes.');
    $db=new Database($config);$audit=new AuditLogger($db);
    $db->transaction(function () use ($db,$audit,$username,$password): void {
        $db->run('INSERT INTO admin_users (username,password_hash) VALUES (?,?)',[$username,password_hash($password,PASSWORD_BCRYPT)]);
        $audit->append(null,'admin_init','admin_user',$db->id(),null,['username'=>$username]);
    });
    fwrite(STDOUT,"Administrator created. No default password is provided.\n");
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
