<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "SKIP: pdo_sqlite manquant\n"); exit(2); }
$db=\Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE user_source_access (user_id INTEGER, source_id INTEGER, PRIMARY KEY(user_id,source_id))');
$db->executeStatement('INSERT INTO user_source_access VALUES (1,10),(2,20)');
$access=new \IptvService\Security\SourceAccess($db);
$cases=[[1,10,true],[1,20,false],[2,20,true],[2,10,false],[3,10,false],[0,10,false]];
foreach($cases as [$user,$source,$expected]) { if ($access->allows($user,$source)!==$expected) { fwrite(STDERR, "FAIL: source isolation\n"); exit(1); } }
$db->executeStatement('DELETE FROM user_source_access WHERE user_id=1 AND source_id=10');
if ($access->allows(1,10)) { fwrite(STDERR, "FAIL: revoked grant\n"); exit(1); }
echo "PASS: source isolation, default-deny and revocation (isolated SQLite)\n";
