<?php
declare(strict_types=1);
require __DIR__.'/upgrade-match-details.php';

try {
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/upgrade-attachments.sql'));
    $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON '.$database.'.attachments TO '.$account);
    fwrite(STDOUT,"Tournament and match attachment storage is ready.\n");
} catch (Throwable $e) {
    fwrite(STDERR,'Attachment upgrade failed: '.$e->getMessage()."\n");
    exit(1);
}
