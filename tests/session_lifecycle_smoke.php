<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
if (!extension_loaded('pdo_sqlite')) { echo "SKIP: pdo_sqlite absent\n"; exit(2); }
$db=\Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE app_sessions (user_id INTEGER, token_hash TEXT, revoked_at TEXT, expires_at TEXT)');
$db->executeStatement('CREATE TABLE app_devices (user_id INTEGER, device_token_hash TEXT, paired_at TEXT)');
echo "PASS: isolated token schema ready\n";
