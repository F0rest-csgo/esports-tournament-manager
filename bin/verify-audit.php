<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli') exit(1);
try { $db=new App\Database($config);$result=$db->transaction(fn()=>(new App\AuditLogger($db))->verify());echo json_encode_safe($result)."\n"; }
catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
