<?php
declare(strict_types=1);

/**
 * Read-only diagnostic for the most recent source (or an explicit numeric ID).
 * Does not contact the provider over HTTP, change rows or print any secret.
 */
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';

use IptvService\Bootstrap\Environment;
use IptvService\Database\ConnectionFactory;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;

Environment::load($root);
echo "=== NSatellite source synchronisation (lecture seule) ===".PHP_EOL;
$issues=0;
$report=static function(bool $ok,string $description)use(&$issues):void{
    echo ($ok?'OK':'ERREUR').' : '.$description.PHP_EOL;
    if(!$ok)++$issues;
};

try {
    $db=ConnectionFactory::create();
    $db->fetchOne('SELECT 1');
} catch (\Throwable) {
    echo "ERREUR : base de données inaccessible".PHP_EOL;
    exit(1);
}
$arg=$argv[1]??null;
if($arg!==null && !preg_match('/^[1-9][0-9]*$/D',$arg)){
    echo "Usage : php bin/doctor-sync-source.php [source_id]".PHP_EOL;
    exit(2);
}
try{
    $source=$arg!==null
        ? $db->fetchAssociative('SELECT id,kind,status,endpoint,credentials FROM sources WHERE id=?',[(int)$arg])
        : $db->fetchAssociative('SELECT id,kind,status,endpoint,credentials FROM sources ORDER BY id DESC LIMIT 1');
} catch (\Throwable) {
    echo "ERREUR : impossible de consulter les sources".PHP_EOL;
    exit(1);
}
if(!$source){
    echo "Aucune source trouvée.".PHP_EOL;
    exit(1);
}
echo 'Source ID : '.(int)$source['id'].PHP_EOL;
echo 'Type : '.(in_array($source['kind'],['m3u','xtream','mag'],true)?$source['kind']:'inconnu').PHP_EOL;
echo 'Statut : '.(in_array($source['status'],['pending','syncing','ready','error'],true)?$source['status']:'autre').PHP_EOL;

try{
    $job=$db->fetchAssociative(
        'SELECT id,status,attempts FROM import_jobs WHERE source_id=? ORDER BY id DESC LIMIT 1',
        [$source['id']]
    );
    if($job){
        echo 'Dernier job ID : '.(int)$job['id'].PHP_EOL;
        echo 'Dernier job statut : '.(in_array($job['status'],['queued','running','completed','failed'],true)?$job['status']:'autre').PHP_EOL;
        echo 'Tentatives : '.(int)$job['attempts'].PHP_EOL;
    }
}catch(\Throwable){$report(false,'état des jobs inaccessible');}

try{
    $schema=$db->createSchemaManager();
    $required=[
        'channels'=>['id','source_id','external_id','kind','name','group_name','logo','epg_id','stream_url','last_seen_at','active']
    ];
    if($source['kind']==='xtream'){
        $required['vod_series']=['id','source_id','provider_series_id','title','cover','synopsis','genre','updated_at'];
        $required['vod_episodes']=['series_id','channel_id'];
    }
    foreach($required as $table=>$expected){
        try{
            $actual=array_map('strtolower',array_keys($schema->listTableColumns($table)));
            $missing=array_diff($expected,$actual);
            $report($missing===[],'schéma '.$table.' '.($missing===[]?'OK':'incomplet ('.implode(',',$missing).')'));
        }catch(\Throwable){$report(false,'table '.$table.' absente ou inaccessible');}
    }
}catch(\Throwable){$report(false,'inspection du schéma catalogue indisponible');}

try{
    $secrets=new SecretBox();
    $url=$secrets->decrypt((string)$source['endpoint'])['url']??null;
    $credentials=$secrets->decrypt((string)$source['credentials']);
    if(!is_string($url)||$url===''||!is_array($credentials))throw new \RuntimeException();
    $report(true,'déchiffrement de la source et des identifiants');
}catch(\Throwable){
    $report(false,'déchiffrement impossible : retrouver IPTV_SECRET_KEY originale');
    echo "Diagnostic interrompu. Aucun accès fournisseur envoyé.".PHP_EOL;
    exit(1);
}
if($source['kind']==='xtream'){
    $valid=is_string($credentials['username']??null) && ($credentials['username']??'')!==''
        && is_string($credentials['password']??null) && ($credentials['password']??'')!=='';
    $report($valid,'structure des champs Xtream (valeurs jamais affichées)');
}
try{
    $candidate=$source['kind']==='xtream'?rtrim($url,'/').'/player_api.php':$url;
    (new TrustedProviderEndpoint())->requestOptions($candidate);
    $report(true,'hôte fournisseur approuvé et DNS public disponible côté CLI');
}catch(\Throwable){
    $report(false,'hôte fournisseur refusé ou DNS non résolu côté CLI (IPTV_TRUSTED_HOSTS)');
}
echo "Aucune connexion HTTP au fournisseur. Aucune donnée modifiée.".PHP_EOL;
exit($issues===0?0:1);
