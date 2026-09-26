<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$feature = file_get_contents($root . '/src/Features/ProcessDbBackup/ProcessDbBackupFeature.php');
$context = file_get_contents($root . '/src/Features/Context/src/JigsawContextMetadataExporter.php');
if ($feature === false || $context === false) throw new RuntimeException('Unable to read Jigsaw database contracts.');

foreach (["'version'  => 230", "\$database->backups()", "\$database->getTables(false)", "\$database->dialect()->name()", "'tables' => \$tables"] as $expected) {
	if (!str_contains($feature, $expected)) throw new RuntimeException("Missing DB Backup portability contract: {$expected}");
}
foreach (['new \\PDO', 'SHOW CREATE TABLE', 'mysql:host=', 'SET FOREIGN_KEY_CHECKS'] as $forbidden) {
	if (str_contains($feature, $forbidden)) throw new RuntimeException("MySQL-only DB Backup fallback remains: {$forbidden}");
}
if (!str_contains($context, "dialect()->name() === 'mysql'")) throw new RuntimeException('Context database-size query is not dialect-gated.');

echo "Jigsaw database portability contract passed.\n";
