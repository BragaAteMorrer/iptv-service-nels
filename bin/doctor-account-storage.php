<?php
declare(strict_types=1);

/**
 * Read-only diagnostics for the database and encryption prerequisites of
 * POST /api/account/sources. Never prints credentials, tokens or URLs.
 */
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';

use IptvService\Bootstrap\Environment;
use IptvService\Database\ConnectionFactory;
use IptvService\Security\SecretBox;

$problems=0;
function report(bool $ok,string $label):void {
    global $problems;
    echo ($ok?'OK':'ERREUR').' : '.$label.PHP_EOL;
    if (!$ok) ++$problems;
}

echo "=== NSatellite : stockage des accès IPTV (lecture seule) ===".PHP_EOL;
try {
    Environment::load($root);
    report(true,'configuration privée chargée');
} catch (\Throwable) {
    report(false,'configuration privée non lisible');
    exit(1);
}

try {
    if (!extension_loaded('sodium')) {
        report(false,'extension PHP sodium indisponible');
    } else {
        $box=new SecretBox();
        $secret=['url'=>'https://example.invalid/diagnostic'];
        $cipher=$box->encrypt($secret);
        report($box->decrypt($cipher)===$secret,'clé IPTV_SECRET_KEY utilisable pour le chiffrement');
    }
} catch (\Throwable) {
    report(false,'IPTV_SECRET_KEY absent/invalide ou chiffrement impossible (valeur non affichée)');
}

try {
    $db=ConnectionFactory::create();
    $db->fetchOne('SELECT 1');
    report(true,'connexion base de données active');
    $schema=$db->createSchemaManager();
    $required=[
        'app_users'=>['id'],
        'app_sessions'=>['user_id','token_hash','expires_at','revoked_at'],
        'sources'=>['id','name','kind','endpoint','credentials','status'],
        'source_owners'=>['source_id','user_id'],
        'user_source_access'=>['user_id','source_id'],
        'import_jobs'=>['id','source_id','method','status'],
    ];
    foreach($required as $table=>$columns) {
        try {
            $actual=array_map('strtolower',array_keys($schema->listTableColumns($table)));
            $missing=array_diff($columns,$actual);
            report(!$missing,$table.($missing?' colonnes manquantes: '.implode(',',$missing):' : structure correcte'));
        } catch (\Throwable) {
            report(false,$table.' absente ou inaccessible');
        }
    }
} catch (\Throwable) {
    report(false,'connexion SQL ou inspection du schéma impossible');
}

echo PHP_EOL;
if ($problems) {
    echo "Échec : ".$problems." prérequis indisponible(s). Aucune donnée modifiée.".PHP_EOL;
    exit(1);
}
echo "Pré-requis présents. Si l'ajout échoue encore, utilise le code d'étape ".
    "du formulaire (insert_source / insert_ownership / insert_grant / queue_import).".PHP_EOL;
echo "Aucune donnée modifiée.".PHP_EOL;
