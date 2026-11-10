<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use IptvService\Controller\AccountSourcesController;
use IptvService\Security\RequestRateLimiter;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use Symfony\Component\HttpFoundation\Request;

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"SKIP: SQLite absent\n");exit(2); }
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE app_sessions (user_id INTEGER, token_hash TEXT, expires_at TEXT, revoked_at TEXT)');
$db->executeStatement('CREATE TABLE app_devices (user_id INTEGER,device_token_hash TEXT)');
$db->executeStatement('CREATE TABLE app_users (id INTEGER PRIMARY KEY,email TEXT)');
$db->executeStatement('CREATE TABLE sources (id INTEGER PRIMARY KEY,name TEXT,kind TEXT,status TEXT,endpoint TEXT,credentials TEXT,last_sync_at TEXT)');
$db->executeStatement('CREATE TABLE source_owners (source_id INTEGER PRIMARY KEY,user_id INTEGER)');
$db->executeStatement('CREATE TABLE user_source_access (user_id INTEGER,source_id INTEGER)');
$db->executeStatement('CREATE TABLE import_jobs (id INTEGER PRIMARY KEY,source_id INTEGER,status TEXT,method TEXT)');
$db->executeStatement("INSERT INTO app_users VALUES(1,'one@example.invalid'),(2,'two@example.invalid')");
$one=str_repeat('a',64);$two=str_repeat('b',64);$device=str_repeat('c',64);
$db->executeStatement('INSERT INTO app_sessions VALUES(?,?,?,NULL)',[1,hash('sha256',$one),'2099-01-01 00:00:00']);
$db->executeStatement('INSERT INTO app_sessions VALUES(?,?,?,NULL)',[2,hash('sha256',$two),'2099-01-01 00:00:00']);
$db->executeStatement('INSERT INTO app_devices VALUES(?,?)',[1,hash('sha256',$device)]);
$db->executeStatement("INSERT INTO sources VALUES(10,'Personal M3U','m3u','ready','encrypted','encrypted',NULL)");
$db->executeStatement("INSERT INTO sources VALUES(20,'Shared Xtream','xtream','ready','encrypted','encrypted',NULL)");
$db->executeStatement('INSERT INTO source_owners VALUES(10,1),(20,2)');
$db->executeStatement('INSERT INTO user_source_access VALUES(1,10),(1,20),(2,20)');
$db->executeStatement("INSERT INTO import_jobs VALUES(1,10,'completed','remote')");
$controller=new AccountSourcesController($db,new SecretBox(),new TrustedProviderEndpoint(),new RequestRateLimiter());
$req=static function(string $method,string $path,string $token='',?array $body=null):Request {
 $headers=['CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'198.18.0.12'];
 if($token!=='')$headers['HTTP_AUTHORIZATION']='Bearer '.$token;
 return Request::create($path,$method,[],[],[],$headers,
     $body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
};
$check=static function(bool $condition,string $message):void {
 if(!$condition)throw new RuntimeException('FAIL: '.$message);
 echo 'PASS: '.$message."\n";
};
$anonymous=$controller->sources($req('GET','/api/account/sources'));
$check($anonymous->getStatusCode()===401,'anonymous denied');
$deviceResult=$controller->sources($req('GET','/api/account/sources',$device));
$check($deviceResult->getStatusCode()===401,'paired device cannot manage sources');
$owner=$controller->sources($req('GET','/api/account/sources',$one));
$items=json_decode((string)$owner->getContent(),true)['items']??[];
$check($owner->getStatusCode()===200 && count($items)===2,'account sees its own and granted sources');
$byId=[];foreach($items as $item)$byId[(int)$item['id']]=$item;
$check($byId[10]['managed']===true && $byId[20]['managed']===false,'ownership differs from granted access');
$check(!str_contains((string)$owner->getContent(),'endpoint')
    && !str_contains((string)$owner->getContent(),'credentials'),'provider credentials never returned');
$other=$controller->sources($req('GET','/api/account/sources',$two));
$otherRows=json_decode((string)$other->getContent(),true)['items']??[];
$check(count($otherRows)===1 && (int)$otherRows[0]['id']===20,'user 2 never sees user 1 source');
$update=$controller->update($req('PUT','/api/account/sources/20',$one,[]),20);
$check($update->getStatusCode()===404,'grantee cannot edit an owner-only source');
$sync=$controller->synchronize($req('POST','/api/account/sources/20/sync',$one,[]),20);
$check($sync->getStatusCode()===404,'grantee cannot queue an import on shared source');
$invalid=$controller->create($req('POST','/api/account/sources',$one,
    ['name'=>'Test','kind'=>'xtream','endpoint'=>'http://127.0.0.1:8080','username'=>'x','password'=>'p']));
$check($invalid->getStatusCode()===422,'insecure or local provider endpoints rejected');
$unknown=$controller->create($req('POST','/api/account/sources',$one,
    ['name'=>'Test','kind'=>'mag','endpoint'=>'https://example.org/']));
$check($unknown->getStatusCode()===422,'unsupported MAG connector rejected');
$check((int)$db->fetchOne('SELECT COUNT(*) FROM sources')===2,'invalid requests did not mutate sources');
echo "PASS: account source isolation and input validation\n";
