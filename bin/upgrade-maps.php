<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli') exit(1);
try {
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) throw new RuntimeException('Pipe the database maintenance password through stdin; do not pass it as an argument.');
    $maintenancePassword=rtrim(stream_get_contents(STDIN),"\r\n");
    $runtime=new App\Database($config);
    $current=$runtime->one('SELECT CURRENT_USER() AS account')['account'];
    $split=strrpos($current,'@');
    if ($split===false) throw new RuntimeException('Cannot identify the runtime MySQL account.');
    $runtimeUser=substr($current,0,$split);$runtimeHost=substr($current,$split+1);
    if ($runtimeUser!==$config['db_user']) throw new RuntimeException('Runtime MySQL identity does not match DB_USERNAME; check its account before granting permissions.');
    $pdo=new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';dbname='.$config['db_name'].';charset=utf8mb4',$argv[1]??'root',$maintenancePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->prepare(file_get_contents(dirname(__DIR__).'/database/upgrade-maps.sql'))->execute();
    $database='`'.str_replace('`','``',$config['db_name']).'`';
    $account=$pdo->quote($runtimeUser).'@'.$pdo->quote($runtimeHost);
    $pdo->prepare('GRANT SELECT, INSERT, UPDATE, DELETE ON '.$database.'.match_maps TO '.$account)->execute();
    fwrite(STDOUT,"Map table and runtime permissions are ready. Existing accounts, scores and logs were preserved.\n");
}catch(Throwable $e){fwrite(STDERR,'Upgrade failed: '.$e->getMessage()."\n");exit(1);}
