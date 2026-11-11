<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use IptvService\Catalog\ContentClassifier;
use IptvService\Command\SyncSourceCommand;
use IptvService\Import\M3uParser;
use IptvService\Import\ProviderHttpFailure;
use IptvService\Import\SyncFailureInfo;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;

$check=static function(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
};
$provider=SyncFailureInfo::describe(new ProviderHttpFailure(403),'xtream_live_request');
$check($provider['category']==='provider_http' && $provider['http_status']===403,
    'provider HTTP 403 diagnosis retains only the numeric status');

// Interpret common cURL error codes without ever exposing an exception message.
foreach([
    28=>'network_timeout',
    7=>'network_connect_refused',
    6=>'network_dns_or_proxy',
    60=>'network_tls_certificate',
    35=>'network_tls_handshake',
    56=>'network_connection_closed'
] as $curlCode=>$category){
    $message='cURL error '.$curlCode.': https://user:SECRET@provider.example.invalid:8080/player_api.php';
    $info=SyncFailureInfo::describe(new TransportException($message),'xtream_live_request');
    $check($info['category']===$category && $info['http_status']===null,
        'safe cURL '.$curlCode.' category '.$category);
    $check(!str_contains(json_encode($info),'SECRET')
        && !str_contains(json_encode($info),'provider.example.invalid'),
        'transport classification contains no credentials or endpoint');
}
$fallback=SyncFailureInfo::describe(new TransportException('Internal transport failure'),'xtream_live_request');
$check($fallback['category']==='network_transport','unknown transport error gets nonmisleading category');

$dns=SyncFailureInfo::describe(new RuntimeException('https://user:password@provider.invalid'),'authorize_endpoint');
$check($dns['category']==='trusted_host_or_dns' && $dns['http_status']===null,
    'DNS/allowlist diagnostics discard exception messages containing credentials');

if(!extension_loaded('pdo_sqlite')){
    fwrite(STDERR,"SKIP: pdo_sqlite unavailable\n");
    exit(2);
}
$oldKey=getenv('IPTV_SECRET_KEY');
$oldEnv=$_ENV['IPTV_SECRET_KEY']??null;
$oldHosts=getenv('IPTV_TRUSTED_HOSTS');
$key=base64_encode(random_bytes(32));
$_ENV['IPTV_SECRET_KEY']=$key;
putenv('IPTV_SECRET_KEY='.$key);
putenv('IPTV_TRUSTED_HOSTS=');
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement("CREATE TABLE sources(id INTEGER PRIMARY KEY,kind TEXT,endpoint TEXT,credentials TEXT,status TEXT)");
$secret=new SecretBox();
$db->insert('sources',[
    'id'=>1,'kind'=>'m3u',
    'endpoint'=>'not-an-encrypted-endpoint',
    'credentials'=>$secret->encrypt([]),
    'status'=>'pending'
]);
$db->insert('sources',[
    'id'=>2,'kind'=>'m3u',
    'endpoint'=>$secret->encrypt([
        'url'=>'https://provider.example.invalid/get.php?username=HIDDEN_LOGIN&password=HIDDEN_SECRET'
    ]),
    'credentials'=>$secret->encrypt([]),
    'status'=>'pending'
]);
$command=new SyncSourceCommand(
    $db,$secret,new MockHttpClient(),new M3uParser(),
    new ContentClassifier(),new TrustedProviderEndpoint()
);
foreach([
    1=>['stage=decrypt','category=decrypt_credentials'],
    2=>['stage=authorize_endpoint','category=trusted_host_or_dns']
] as $id=>$expected){
    $output=new BufferedOutput();
    $exit=$command->run(
        new ArrayInput(['source-id'=>(string)$id],$command->getDefinition()),
        $output
    );
    $log=$output->fetch();
    $check($exit!==0 && str_contains($log,$expected[0]) && str_contains($log,$expected[1]),
        "source $id emits diagnostic stage and category");
    $check(!str_contains($log,'HIDDEN_LOGIN') && !str_contains($log,'HIDDEN_SECRET')
        && !str_contains($log,'provider.example.invalid'),
        "source $id never prints provider URL or credentials");
    $check($db->fetchOne('SELECT status FROM sources WHERE id=?',[$id])==='error',
        "source $id marked as errored");
}
if($oldEnv!==null)$_ENV['IPTV_SECRET_KEY']=$oldEnv;
else unset($_ENV['IPTV_SECRET_KEY']);
if($oldKey!==false)putenv('IPTV_SECRET_KEY='.$oldKey);else putenv('IPTV_SECRET_KEY');
if($oldHosts!==false)putenv('IPTV_TRUSTED_HOSTS='.$oldHosts);else putenv('IPTV_TRUSTED_HOSTS');
echo "PASS: synchronization diagnostic contains no provider secrets and exposes safe stage\n";
