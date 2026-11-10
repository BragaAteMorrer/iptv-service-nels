<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use IptvService\Security\SecretBox;
use IptvService\Service\AccountSourceCreator;
use IptvService\Service\AccountSourceWriteFailure;

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"SKIP: pdo_sqlite missing\n");exit(2); }
$oldEnv=$_ENV['IPTV_SECRET_KEY']??null;
$oldGlobal=getenv('IPTV_SECRET_KEY');
$key=base64_encode(random_bytes(32));
$_ENV['IPTV_SECRET_KEY']=$key;
putenv('IPTV_SECRET_KEY='.$key);

$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('PRAGMA foreign_keys=ON');
$db->executeStatement('CREATE TABLE app_users(id INTEGER PRIMARY KEY)');
$db->executeStatement('CREATE TABLE sources(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,kind TEXT,endpoint TEXT,credentials TEXT,status TEXT)');
$db->executeStatement('CREATE TABLE source_owners(source_id INTEGER PRIMARY KEY,user_id INTEGER,FOREIGN KEY(source_id) REFERENCES sources(id),FOREIGN KEY(user_id) REFERENCES app_users(id))');
$db->executeStatement('CREATE TABLE user_source_access(source_id INTEGER,user_id INTEGER,PRIMARY KEY(user_id,source_id),FOREIGN KEY(source_id) REFERENCES sources(id),FOREIGN KEY(user_id) REFERENCES app_users(id))');
$db->executeStatement('CREATE TABLE import_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER,method TEXT,status TEXT,FOREIGN KEY(source_id) REFERENCES sources(id))');
$db->insert('app_users',['id'=>1]);
$box=new SecretBox();
$creator=new AccountSourceCreator($db,$box);
$data=['name'=>'Fixture sans fournisseur externe','kind'=>'xtream','url'=>'https://example.invalid:8443','credentials'=>['username'=>'test-only','password'=>'not-real']];
$result=$creator->create(1,$data);
$source=(int)$result['source_id'];
$job=(int)$result['job_id'];
$check=static function(bool $condition,string $label):void{
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    echo "PASS: ".$label.PHP_EOL;
};
$row=$db->fetchAssociative('SELECT * FROM sources WHERE id=?',[$source]);
$check($source>0 && $job>0 && $row['status']==='pending','source + pending status inserted');
$check($row['endpoint']!==$data['url'] && !str_contains($row['credentials'],'not-real'),'provider access encrypted at rest');
$check($box->decrypt($row['endpoint'])['url']===$data['url'],'encrypted endpoint round-trip');
$check($box->decrypt($row['credentials'])===$data['credentials'],'encrypted credentials round-trip');
$check((int)$db->fetchOne('SELECT COUNT(*) FROM source_owners WHERE source_id=? AND user_id=1',[$source])===1,'owner created');
$check((int)$db->fetchOne('SELECT COUNT(*) FROM user_source_access WHERE source_id=? AND user_id=1',[$source])===1,'account grant created');
$check((string)$db->fetchOne('SELECT status FROM import_jobs WHERE id=?',[$job])==='queued','remote import queued');

// Ensure failures do not leave half-created sources/grants in the database.
$db->executeStatement('DROP TABLE import_jobs');
try {
    $creator->create(1,$data);
    throw new RuntimeException('Expected write failure');
} catch (AccountSourceWriteFailure $e) {
    $check($e->stage==='queue_import','failed job insert identifies exact stage');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM sources')===1,'failed transaction rolled back source insert');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM source_owners')===1,'failed transaction rolled back ownership insert');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM user_source_access')===1,'failed transaction rolled back grant insert');
}

// A missing/invalid encryption key must be distinguished from missing schema.
unset($_ENV['IPTV_SECRET_KEY']);
putenv('IPTV_SECRET_KEY');
try {
    $creator->create(1,$data);
    throw new RuntimeException('Expected encryption failure');
} catch (AccountSourceWriteFailure $e) {
    $check($e->stage==='encryption','invalid key identifies encryption stage');
}
if($oldEnv!==null)$_ENV['IPTV_SECRET_KEY']=$oldEnv;
else unset($_ENV['IPTV_SECRET_KEY']);
if($oldGlobal!==false)putenv('IPTV_SECRET_KEY='.$oldGlobal);
else putenv('IPTV_SECRET_KEY');
echo "PASS: account source atomic creation / rollback / encryption diagnosis\n";
