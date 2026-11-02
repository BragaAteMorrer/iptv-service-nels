<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use IptvService\Controller\IdentityController;
use IptvService\Security\RequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"SKIP: pdo_sqlite unavailable\n"); exit(2); }
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE app_users (id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT UNIQUE,password_hash TEXT)');
$db->executeStatement('CREATE TABLE app_sessions (user_id INTEGER,token_hash TEXT,expires_at TEXT,revoked_at TEXT)');
$db->executeStatement('CREATE TABLE app_devices (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,device_name TEXT,device_type TEXT,device_token_hash TEXT UNIQUE,pairing_code_hash TEXT UNIQUE,pairing_expires_at TEXT,paired_at TEXT,last_seen_at TEXT)');
$email='test@nelstv.invalid';$password='test-password-12345';
$db->executeStatement('INSERT INTO app_users(email,password_hash) VALUES (?,?)',[$email,password_hash($password,PASSWORD_DEFAULT)]);
$controller=new IdentityController($db,new RequestRateLimiter());
$req=static function(string $uri,array $body=[],string $token=''): Request {
    $headers=['REMOTE_ADDR'=>'198.51.100.23','CONTENT_TYPE'=>'application/json'];
    if ($token!=='') $headers['HTTP_AUTHORIZATION']='Bearer '.$token;
    return Request::create($uri,'POST',[],[],[],$headers,json_encode($body,JSON_THROW_ON_ERROR));
};
$assert=static function(bool $value,string $message): void {
    if (!$value) throw new RuntimeException('FAIL: '.$message);
    echo 'PASS: '.$message."\n";
};
$fail=$controller->login($req('/api/identity/login',['email'=>$email,'password'=>'wrong']));
$assert($fail->getStatusCode()===401,'Bad credentials denied');
$login=$controller->login($req('/api/identity/login',['email'=>$email,'password'=>$password]));
$body=json_decode($login->getContent(),true);
$token=$body['access_token']??'';
$assert($login->getStatusCode()===200 && preg_match('/^[a-f0-9]{64}$/D',$token)===1,'Valid login creates bearer token');
$assert($db->fetchOne('SELECT COUNT(*) FROM app_sessions WHERE token_hash=?',[hash('sha256',$token)])==1,'Session token stored hashed');
$start=$controller->start($req('/api/devices/pair/start',['name'=>'Test Android TV']));
$pair=json_decode($start->getContent(),true);
$assert($start->getStatusCode()===201 && preg_match('/^[0-9]{6}$/D',(string)($pair['code']??''))===1,'Device pairing starts with six-digit code');
$pending=$controller->status($req('/api/devices/pair/status',['pairing_id'=>$pair['pairing_id']]));
$assert(json_decode($pending->getContent(),true)['status']==='pending','Device pairing is pending');
$approve=$controller->approve($req('/api/devices/pair/approve',['code'=>$pair['code']],$token));
$assert($approve->getStatusCode()===200,'Authenticated user approves pairing');
$claim=$controller->status($req('/api/devices/pair/status',['pairing_id'=>$pair['pairing_id']]));
$deviceToken=json_decode($claim->getContent(),true)['device_token']??'';
$assert($claim->getStatusCode()===200 && strlen($deviceToken)===64,'TV claims one-time device token');
$again=$controller->status($req('/api/devices/pair/status',['pairing_id'=>$pair['pairing_id']]));
$assert($again->getStatusCode()===404,'Pairing claim cannot be replayed');
$devices=$controller->devices($req('/api/devices',[],$token));
$items=json_decode($devices->getContent(),true)['items']??[];
$assert(count($items)===1,'Account lists its paired device');
$revoke=$controller->revoke($req('/api/devices/'.$items[0]['id'],[],$token),(int)$items[0]['id']);
$assert($revoke->getStatusCode()===200,'Account revokes its device');
$logout=$controller->logout($req('/api/identity/logout',[],$token));
$assert($logout->getStatusCode()===200,'Logout revokes session');
$after=$controller->devices($req('/api/devices',[],$token));
$assert($after->getStatusCode()===401,'Revoked login cannot list devices');
echo "PASS: full isolated identity and Android TV pairing workflow\n";
