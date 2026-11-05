<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli') exit(1);
try {
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) throw new RuntimeException('Pipe the database maintenance password through stdin; do not pass it as an argument.');
    $maintenancePassword=rtrim(stream_get_contents(STDIN),"\r\n");
    $runtime=new App\Database($config);$current=$runtime->one('SELECT CURRENT_USER() AS account')['account'];$split=strrpos($current,'@');
    if ($split===false) throw new RuntimeException('Cannot identify the runtime MySQL account.');
    $runtimeUser=substr($current,0,$split);$runtimeHost=substr($current,$split+1);
    if ($runtimeUser!==$config['db_user']) throw new RuntimeException('Runtime MySQL identity does not match DB_USERNAME.');
    $pdo=new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';dbname='.$config['db_name'].';charset=utf8mb4',$argv[1]??'root',$maintenancePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('SET SESSION lock_wait_timeout=30');
    $columns=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$columns->execute([$config['db_name'],'matches']);$names=$columns->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('description',$names,true)) $pdo->exec('ALTER TABLE matches ADD COLUMN description TEXT NULL');
    if (!in_array('forfeit_team_id',$names,true)) $pdo->exec('ALTER TABLE matches ADD COLUMN forfeit_team_id BIGINT UNSIGNED NULL');
    $constraints=$pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$constraints->execute([$config['db_name'],'matches','matches_forfeit_team_fk']);
    if (!$constraints->fetchColumn()) $pdo->exec('ALTER TABLE matches ADD CONSTRAINT matches_forfeit_team_fk FOREIGN KEY (tournament_id,forfeit_team_id) REFERENCES teams(tournament_id,id)');
    // Include the previous map upgrade so earlier overwrite releases can upgrade directly.
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/upgrade-maps.sql'));
    $database='`'.str_replace('`','``',$config['db_name']).'`';$account=$pdo->quote($runtimeUser).'@'.$pdo->quote($runtimeHost);
    $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON '.$database.'.match_maps TO '.$account);
    fwrite(STDOUT,"Match details, forfeits and maps upgrade complete. Existing accounts, results and audit logs were preserved.\n");
} catch (Throwable $e) { fwrite(STDERR,'Upgrade failed: '.$e->getMessage()."\n");exit(1); }
