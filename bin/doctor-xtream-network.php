<?php
declare(strict_types=1);

/**
 * Run from SSH: php bin/doctor-xtream-network.php [source_id]
 *
 * A short unauthenticated GET /player_api.php plus a TCP port probe.
 * URL and Xtream user/password are NEVER sent to stdout or logs.
 * Only the previously approved public provider host can be contacted.
 * No schema mutations, no queued jobs, no redirects, no auth data.
 */
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';

use IptvService\Bootstrap\Environment;
use IptvService\Database\ConnectionFactory;
use IptvService\Import\SyncFailureInfo;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use Symfony\Component\HttpClient\HttpClient;

Environment::load($root);

$id=$argv[1]??null;
if ($id!==null && preg_match('/^[1-9][0-9]*$/D',$id)!==1) {
    fwrite(STDERR,"Usage: php bin/doctor-xtream-network.php [source_id]\n");
    exit(2);
}

echo "=== NSatellite : diagnostic réseau Xtream (sans identifiants) ===\n";
echo "Transport PHP : ".(extension_loaded('curl')?'cURL':'streams PHP')."\n";
foreach(['HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','NO_PROXY'] as $name) {
    $value=getenv($name);
    echo $name.' : '.($value!==false && $value!==''?'défini':'non défini')."\n";
}
try {
    $db=ConnectionFactory::create();
    $row=$id===null
        ? $db->fetchAssociative("SELECT id,kind,endpoint FROM sources WHERE kind='xtream' ORDER BY id DESC LIMIT 1")
        : $db->fetchAssociative('SELECT id,kind,endpoint FROM sources WHERE id=?',[(int)$id]);
    if(!$row || $row['kind']!=='xtream'){
        echo "ERREUR : source Xtream introuvable.\n";
        exit(1);
    }
    echo 'Source ID : '.(int)$row['id']."\n";
    $endpoint=(new SecretBox())->decrypt((string)$row['endpoint'])['url']??null;
    if(!is_string($endpoint))throw new RuntimeException('Invalid stored endpoint');
    $url=rtrim($endpoint,'/').'/player_api.php';
    $options=(new TrustedProviderEndpoint())->requestOptions($url);
    $parts=parse_url($url);
    $host=$parts['host']??'';
    $scheme=$parts['scheme']??'';
    $port=(int)($parts['port']??($scheme==='https'?443:80));
    $ip=$options['resolve'][$host]??null;
    if(!is_string($ip)||filter_var($ip,FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false){
        echo "ERREUR : IP publique épinglée introuvable.\n";
        exit(1);
    }
    echo "Endpoint : autorisé, DNS public vérifié, port ".$port."\n";
} catch (Throwable) {
    echo "ERREUR : lecture, déchiffrement ou validation du fournisseur impossible.\n";
    exit(1);
}

// No hostname resolution or credentials are used for the TCP test. The IP was
// already checked and pinned by TrustedProviderEndpoint (SSRF protection).
$socketAddress='tcp://'.(str_contains($ip,':')?'['.$ip.']':$ip).':'.$port;
$errno=0;
$errstr='';
$started=microtime(true);
$socket=@stream_socket_client($socketAddress,$errno,$errstr,5,STREAM_CLIENT_CONNECT);
$elapsed=microtime(true)-$started;
if(is_resource($socket)){
    fclose($socket);
    echo "TCP sur IP publique épinglée : OK\n";
}else{
    // Never output $errstr: it can expose IPs or network topology.
    echo "TCP sur IP publique épinglée : ÉCHEC (catégorie : ".
         ($elapsed>=4.5?'timeout_possible':'connexion_refusée_ou_bloquée').")\n";
}

// Test provider HTTP endpoint without username/password, without following
// redirects. HTTP 200, 401, 403, etc. are connectivity observations only;
// provider authentication is NOT tested here.
try{
    $client=HttpClient::create();
    $response=$client->request('GET',$url,array_replace($options,[
        'max_redirects'=>0,
        'timeout'=>8,
        'max_duration'=>15,
        'headers'=>['Accept'=>'application/json']
    ]));
    $status=$response->getStatusCode();
    $response->cancel();
    echo "HTTP sans identifiants : réponse ".$status."\n";
    echo "Connectivité HTTP établie ; ce test ne valide pas les accès Xtream.\n";
    exit(0);
}catch(Throwable $e){
    $info=SyncFailureInfo::describe($e,'xtream_live_request');
    echo "HTTP sans identifiants : ÉCHEC category=".$info['category']."\n";
    echo "Aucune URL, clé ou identifiant fournisseur affiché.\n";
    exit(1);
}
