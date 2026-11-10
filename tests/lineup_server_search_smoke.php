<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use IptvService\Controller\LineupController;
use IptvService\Security\ViewerAccess;
use Symfony\Component\HttpFoundation\Request;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR,"SKIP: pdo_sqlite missing\n");
    exit(2);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE user_source_access(user_id INTEGER,source_id INTEGER)');
$db->executeStatement('CREATE TABLE app_sessions(user_id INTEGER,token_hash TEXT,revoked_at TEXT,expires_at TEXT)');
$db->executeStatement('CREATE TABLE app_devices(user_id INTEGER,device_token_hash TEXT,paired_at TEXT)');
$db->executeStatement('CREATE TABLE channels(id INTEGER PRIMARY KEY,source_id INTEGER,external_id TEXT,kind TEXT,active INTEGER,name TEXT,group_name TEXT,logo TEXT,epg_id TEXT)');
$db->executeStatement('CREATE TABLE user_channel_preferences(user_id INTEGER,source_id INTEGER,external_id TEXT,channel_number INTEGER,favorite INTEGER,hidden INTEGER)');
$db->executeStatement('INSERT INTO user_source_access VALUES(1,10)');
$token=str_repeat('a',64);
$db->executeStatement('INSERT INTO app_sessions VALUES(?,?,NULL,?)',[1,hash('sha256',$token),'2099-01-01 00:00:00']);
$db->beginTransaction();
for($i=1;$i<=1205;$i++){
    $source=$i===1205?20:10;
    $db->executeStatement('INSERT INTO channels VALUES(?,?,?,?,?,?,?,?,?)',
        [$i,$source,'external-'.$i,'live',1,sprintf('Channel %04d',$i),$i>1000?'Sport':'Divers',null,null]);
}
$db->executeStatement('INSERT INTO user_channel_preferences VALUES(?,?,?,?,?,?)',[1,10,'external-1100',1234,1,0]);
$db->executeStatement('INSERT INTO user_channel_preferences VALUES(?,?,?,?,?,?)',[1,10,'external-50',50,0,1]);
$db->commit();

$controller=new LineupController($db,new ViewerAccess($db));
$call=static function(string $query, bool $loggedIn=true)use($controller,$token):array {
    $server=$loggedIn?['HTTP_AUTHORIZATION'=>'Bearer '.$token]:[];
    $request=Request::create('/api/lineup'.$query,'GET',[],[],[],$server);
    $response=$controller->list($request);
    return [$response->getStatusCode(),json_decode((string)$response->getContent(),true)];
};
$check=static function(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label."\n";
};

[$code,$data]=$call('?page=13&limit=100');
$check($code===200 && $data['total']===1203 && count($data['items'])===3,'page 13 reaches channels past 1000; hidden/foreign excluded');
[$code,$data]=$call('?channel_number=1234');
$check($code===200 && count($data['items'])===1 && (int)$data['items'][0]['id']===1100,'server-side channel number lookup');
[$code,$data]=$call('?channel_id=1100');
$check($code===200 && count($data['items'])===1 && (int)$data['items'][0]['id']===1100,'direct link to non-cached channel');
[$code,$data]=$call('?q=Channel%201100');
$check($code===200 && $data['total']===1 && (int)$data['items'][0]['id']===1100,'server-side name search past 1000');
[$code,$data]=$call('?q=Sport&limit=100');
$check($code===200 && $data['total']===204,'server-side group search with source restrictions');
[$code,$data]=$call('?favorites=1');
$check($code===200 && $data['total']===1 && (int)$data['items'][0]['id']===1100,'favorite only belongs to current account');
[$code,$data]=$call('?channel_id=1205');
$check($code===200 && $data['total']===0,'other-account source remains invisible');
[$code,$data]=$call('?channel_id=50');
$check($code===200 && $data['total']===0,'hidden channels excluded by default');
[$code,$data]=$call('?channel_number=abc');
$check($code===422,'malformed number rejected');
[$code,$data]=$call('?q='.str_repeat('x',101));
$check($code===422,'unbounded text query rejected');
[$code,$data]=$call('',false);
$check($code===401,'unauthenticated lineup denied');
echo "PASS: searchable lineup across 1205 rows with isolated SQLite\n";
