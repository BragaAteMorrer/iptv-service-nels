<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
if (!extension_loaded('pdo_sqlite')) { echo "SKIP: pdo_sqlite absent\n"; exit(2); }
$db=\Doctrine\DBAL\DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE app_sessions (user_id INTEGER, token_hash TEXT, revoked_at TEXT, expires_at TEXT)');
$db->executeStatement('CREATE TABLE app_devices (user_id INTEGER, device_token_hash TEXT, paired_at TEXT)');
$tokens=['valid'=>str_repeat('a',64),'revoked'=>str_repeat('b',64),'expired'=>str_repeat('c',64),'device'=>str_repeat('d',64)];
$db->executeStatement('INSERT INTO app_sessions VALUES (?,?,?,?)',[1,hash('sha256',$tokens['valid']),null,'2099-01-01 00:00:00']);
$db->executeStatement('INSERT INTO app_sessions VALUES (?,?,?,?)',[1,hash('sha256',$tokens['revoked']),'2026-01-01 00:00:00','2099-01-01 00:00:00']);
$db->executeStatement('INSERT INTO app_sessions VALUES (?,?,?,?)',[2,hash('sha256',$tokens['expired']),null,'2020-01-01 00:00:00']);
$db->executeStatement('INSERT INTO app_devices VALUES (?,?,?)',[2,hash('sha256',$tokens['device']),'2026-01-01 00:00:00']);
$viewer=new \IptvService\Security\ViewerAccess($db);
$check=static function(string $token) use ($viewer): ?int {
    $req=\Symfony\Component\HttpFoundation\Request::create('/','GET',[],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$token]);
    return $viewer->userId($req);
};
if ($check($tokens['valid'])!==1 || $check($tokens['revoked'])!==null || $check($tokens['expired'])!==null || $check($tokens['device'])!==2) {
    fwrite(STDERR,"FAIL: session lifecycle\n"); exit(1);
}
$db->executeStatement('DELETE FROM app_devices WHERE device_token_hash=?',[hash('sha256',$tokens['device'])]);
if ($check($tokens['device'])!==null) { fwrite(STDERR,"FAIL: revoked device\n"); exit(1); }
echo "PASS: valid, expired, revoked sessions and revoked device (SQLite memory)\n";
