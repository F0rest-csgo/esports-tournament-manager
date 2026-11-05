<?php
declare(strict_types=1);
// Also run with: php -d disable_functions=putenv tests/config.php
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'tournament-config-'.bin2hex(random_bytes(8));
$checks=0;
try {
    if (!mkdir($root) || !mkdir($root.DIRECTORY_SEPARATOR.'config')) throw new RuntimeException('Cannot create isolated fixture directory.');
    copy(dirname(__DIR__).'/config/app.php',$root.'/config/app.php');
    file_put_contents($root.'/.env',"# Isolated non-secret test values\nDB_HOST=fixture.test\nDB_PORT=33306\nDB_DATABASE=fixture_tournament\nDB_USERNAME=fixture_user\nDB_PASSWORD=\"fixture=value\"\nSESSION_SECURE=0\n");
    $loaded=require $root.'/config/app.php';
    foreach (['DB_HOST'=>['db_host','fixture.test'],'DB_PORT'=>['db_port','33306'],'DB_DATABASE'=>['db_name','fixture_tournament'],'DB_USERNAME'=>['db_user','fixture_user'],'DB_PASSWORD'=>['db_password','fixture=value']] as $key=>[$field,$value]) {
        $actual=getenv($key);$expected=$actual===false?$value:$actual;
        if ($loaded[$field]!==$expected) throw new RuntimeException('Configuration precedence failed for '.$key);
        $checks++;
    }
    $actual=getenv('SESSION_SECURE');
    if ($loaded['secure']!==($actual!==false && $actual!=='0')) throw new RuntimeException('Session flag parsing failed.');
    $checks++;
    unlink($root.'/.env');
    $defaults=require $root.'/config/app.php';
    if ($defaults['db_name']!==(getenv('DB_DATABASE')?:'tournament')) throw new RuntimeException('Default database configuration failed.');
    $checks++;
    echo "PASS $checks configuration assertions\n";
} catch (Throwable $e) { fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");$failed=true; }
finally {
    foreach ([$root.'/.env',$root.'/config/app.php'] as $file) if (is_file($file)) unlink($file);
    if (is_dir($root.'/config')) rmdir($root.'/config');
    if (is_dir($root)) rmdir($root);
}
exit(isset($failed)?1:0);
