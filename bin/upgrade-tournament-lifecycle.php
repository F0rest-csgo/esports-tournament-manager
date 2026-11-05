<?php
declare(strict_types=1);
require __DIR__.'/upgrade-attachments.php';

try {
    // Keep all historical IDs and hashes. Only remove the dependency on the live tournament row.
    $constraints = $pdo->prepare(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA=? AND TABLE_NAME='audit_logs' AND COLUMN_NAME='tournament_id'
           AND REFERENCED_TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME='tournaments' AND REFERENCED_COLUMN_NAME='id'"
    );
    $constraints->execute([$config['db_name'], $config['db_name']]);
    foreach ($constraints->fetchAll(PDO::FETCH_COLUMN) as $constraint) {
        $name = str_replace('`', '``', $constraint);
        $pdo->exec('ALTER TABLE audit_logs DROP FOREIGN KEY `'.$name.'`');
    }
    fwrite(STDOUT, "Tournament copy and deletion are ready. Audit history was preserved.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Tournament lifecycle upgrade failed: '.$e->getMessage()."\n");
    exit(1);
}
