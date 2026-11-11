<?php
declare(strict_types=1);

/**
 * Test the M3U fallback on isolated MariaDB 11.4 with a fixture stored
 * locally. No IPTV provider is contacted and production is never used.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use IptvService\Catalog\ContentClassifier;
use IptvService\Command\ImportM3uCommand;
use IptvService\Import\M3uParser;
use IptvService\Security\SecretBox;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

if (getenv('NSATELLITE_CI_DB_NAME')!=='nsatellite_ci') {
    echo "SKIP: disposable nsatellite_ci database required\n";
    exit(0);
}
$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql',
    'host'=>getenv('NSATELLITE_CI_DB_HOST')?:'127.0.0.1',
    'port'=>(int)(getenv('NSATELLITE_CI_DB_PORT')?:3306),
    'dbname'=>'nsatellite_ci',
    'user'=>getenv('NSATELLITE_CI_DB_USER')?:'ci',
    'password'=>getenv('NSATELLITE_CI_DB_PASSWORD')?:'',
    'charset'=>'utf8mb4'
]);
$assert=static function(bool $ok,string $msg):void{
    if(!$ok)throw new RuntimeException('FAIL: '.$msg);
    echo "PASS: ".$msg.PHP_EOL;
};
$previousEnv=$_ENV['IPTV_SECRET_KEY']??null;
$previousPutenv=getenv('IPTV_SECRET_KEY');
$key=base64_encode(random_bytes(32));
$_ENV['IPTV_SECRET_KEY']=$key;
putenv('IPTV_SECRET_KEY='.$key);
$file=tempnam(sys_get_temp_dir(),'nsat_m3u_');
if($file===false)throw new RuntimeException('No temp fixture');
try{
    file_put_contents($file,"#EXTM3U\n#EXTINF:-1 tvg-id=\"test-news\" group-title=\"News\",Fixture News\nhttps://example.invalid/live/news.ts\n#EXTINF:-1 group-title=\"Movies\",Fixture Movie\nhttps://example.invalid/vod/movie.mp4\n");
    $db->executeStatement('DROP TABLE IF EXISTS channels');
    $db->executeStatement('DROP TABLE IF EXISTS sources');
    $db->executeStatement("CREATE TABLE sources(
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        kind VARCHAR(16) NOT NULL,status VARCHAR(20) NOT NULL,
        last_sync_at DATETIME NULL
    ) ENGINE=InnoDB");
    $db->executeStatement("CREATE TABLE channels(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_id BIGINT UNSIGNED NOT NULL,
        external_id VARCHAR(128) NOT NULL,
        kind VARCHAR(16) NOT NULL,
        name TEXT NOT NULL,group_name TEXT NOT NULL,
        logo TEXT NOT NULL,epg_id TEXT NOT NULL,
        stream_url TEXT NOT NULL,last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY channels_source_external(source_id,external_id)
    ) ENGINE=InnoDB");
    $db->executeStatement("INSERT INTO sources(id,kind,status) VALUES(1,'xtream','error'),(2,'m3u','pending')");
    $cmd=new ImportM3uCommand($db,new M3uParser(),new SecretBox(),new ContentClassifier());
    foreach([1,2] as $id){
        $output=new BufferedOutput();
        $result=$cmd->run(new ArrayInput(['source-id'=>(string)$id,'file'=>$file],$cmd->getDefinition()),$output);
        $assert($result===0 && str_contains($output->fetch(),'Imported 2 catalog entries'),
            'local M3U import works for source kind '.($id===1?'Xtream':'M3U'));
        $assert($db->fetchOne('SELECT status FROM sources WHERE id=?',[$id])==='ready',
            'source becomes ready after offline import');
        $rows=$db->fetchAllAssociative('SELECT kind,stream_url FROM channels WHERE source_id=? ORDER BY id',[$id]);
        $assert(count($rows)===2 && $rows[0]['kind']==='live' && $rows[1]['kind']==='movie',
            'live and movie metadata imported from local fixture');
        $box=new SecretBox();
        $assert($box->decrypt($rows[0]['stream_url'])['url']==='https://example.invalid/live/news.ts'
          && !str_contains($rows[0]['stream_url'],'example.invalid'),
          'stream URLs remain encrypted at rest');
    }
    file_put_contents($file,"#EXTM3U\n");
    $output=new BufferedOutput();
    $result=$cmd->run(new ArrayInput(['source-id'=>'1','file'=>$file],$cmd->getDefinition()),$output);
    $assert($result!==0 && $db->fetchOne('SELECT status FROM sources WHERE id=1')==='error',
        'empty playlist rejected without deleting prior catalogue');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM channels WHERE source_id=1')===2,
        'failed import preserves previous catalog entries');
} finally {
    @unlink($file);
    $db->executeStatement('DROP TABLE IF EXISTS channels');
    $db->executeStatement('DROP TABLE IF EXISTS sources');
    if($previousEnv!==null)$_ENV['IPTV_SECRET_KEY']=$previousEnv;
    else unset($_ENV['IPTV_SECRET_KEY']);
    if($previousPutenv!==false)putenv('IPTV_SECRET_KEY='.$previousPutenv);
    else putenv('IPTV_SECRET_KEY');
}
echo "PASS: Xtream M3U file fallback, encrypted catalogue and safe empty-file handling\n";
