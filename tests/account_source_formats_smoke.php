<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Security/AccountSourceInput.php';
require dirname(__DIR__).'/src/Security/TrustedProviderEndpoint.php';

use IptvService\Security\AccountSourceInput;
use IptvService\Security\TrustedProviderEndpoint;

$checks=0;
$ok=static function(bool $yes,string $label)use(&$checks):void {
    if(!$yes)throw new RuntimeException('FAIL: '.$label);
    $checks++;
    echo "PASS: ".$label.PHP_EOL;
};
$reject=static function(array $input,string $expected)use($ok):void {
    try {
        AccountSourceInput::normalize($input);
        $ok(false,'Must reject '.$expected);
    } catch(InvalidArgumentException $e) {
        $ok(true,'Reject '.$expected);
    }
};

$xtream=[
    'name'=>'Test Xtream','kind'=>'xtream','endpoint'=>'http://line.example.test:8080',
    'username'=>'account','password'=>'pass','allow_insecure_http'=>true,
];
$parsed=AccountSourceInput::normalize($xtream);
$ok($parsed['url']==='http://line.example.test:8080'
    && $parsed['credentials']['username']==='account'
    && $parsed['credentials']['password']==='pass',
    'Xtream HTTP :8080 base URL and separate credentials accepted');

$m3uUrl='http://line.example.test:8080/get.php?username=account&password=pass&type=m3u_plus&output=ts';
$m3u=['name'=>'M3U complète','kind'=>'m3u','endpoint'=>$m3uUrl,'allow_insecure_http'=>true];
$parsed=AccountSourceInput::normalize($m3u);
$ok($parsed['url']===$m3uUrl && $parsed['credentials']===[],
    'Full HTTP get.php M3U with password query and output=ts preserved');

$secure=AccountSourceInput::normalize([
 'name'=>'M3U sécurisée','kind'=>'m3u',
 'endpoint'=>'https://line.example.test:8443/get.php?username=account&password=pass&type=m3u_plus&output=m3u8',
]);
$ok(str_starts_with($secure['url'],'https://') && str_contains($secure['url'],':8443/'),
    'Custom HTTPS port and full M3U URL accepted without HTTP consent');

$reject(array_diff_key($xtream,['allow_insecure_http'=>true]),'HTTP without explicit consent');
$reject([...$xtream,'allow_insecure_http'=>'true'],'non-boolean HTTP consent');
$reject([...$xtream,'endpoint'=>'http://alice:password@line.example.test:8080'],'credentials embedded in host');
$reject([...$xtream,'endpoint'=>'ftp://line.example.test:8080'],'FTP');
$reject([...$xtream,'endpoint'=>'http://line.example.test:8080/player_api.php'],'Xtream API file pasted as base');
$reject([...$xtream,'endpoint'=>$m3uUrl],'get.php selected as Xtream instead of M3U');
$reject([...$m3u,'endpoint'=>'http://line.example.test:8080/get.php?username=x#fragment'],'fragment');
$reject([...$xtream,'endpoint'=>"http://line.example.test:8080/\r\nHost:other.test"],'CRLF');
$reject([...$xtream,'endpoint'=>'http://line.example.test:65536'],'port out of range');
$reject([...$xtream,'endpoint'=>'http://line.example.test:8080','username'=>''],'missing Xtream credentials');

$trust=new TrustedProviderEndpoint();
foreach(['http://127.0.0.1:8080/get.php?username=x','http://192.168.1.1:8080/','http://[::1]:8080/'] as $url) {
    try {
        $trust->requestOptions($url);
        $ok(false,'Private/loopback address must not be requested');
    } catch(RuntimeException) {
        $ok(true,'Private/loopback address blocked by TrustedProviderEndpoint');
    }
}
$old=getenv('IPTV_TRUSTED_HOSTS');
putenv('IPTV_TRUSTED_HOSTS=approved.example.test');
try {
    $trust->requestOptions('http://unapproved.example.test:8080/get.php?username=x');
    $ok(false,'Unapproved public hostname must not be requested');
} catch(RuntimeException) {
    $ok(true,'Unapproved hostname remains blocked despite valid HTTP format');
} finally {
    if($old===false)putenv('IPTV_TRUSTED_HOSTS');
    else putenv('IPTV_TRUSTED_HOSTS='.$old);
}
echo 'PASS: '.$checks.' format and security fixtures'.PHP_EOL;
