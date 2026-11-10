<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use IptvService\Controller\VodPlaybackController;
use IptvService\Controller\CapabilitiesController;
use Symfony\Component\HttpFoundation\Request;

// No database, provider, credentials or media stream needed.
$controller=(new ReflectionClass(VodPlaybackController::class))->newInstanceWithoutConstructor();
$cases=[
    ['prod','1',false],
    ['prod','0',false],
    ['test','1',false],
    ['dev','0',false],
    ['dev','1',true],
];
foreach($cases as [$env,$relay,$expected]){
    $_SERVER['APP_ENV']=$env;
    $_SERVER['IPTV_ENABLE_LOCAL_RELAY']=$relay;
    $_SERVER['IPTV_ENABLE_DIRECT_HLS']='0';
    $_SERVER['APP_DEBUG']='0';
    $method=new ReflectionMethod(VodPlaybackController::class,'localVideoRelayEnabled');
    if($method->invoke($controller)!==$expected){
        fwrite(STDERR,"FAIL: VOD relay policy for environment $env\n");
        exit(1);
    }
    $cap=(new CapabilitiesController())->index();
    $decoded=json_decode((string)$cap->getContent(),true);
    if(($decoded['vod_playback']??null)!==$expected){
        fwrite(STDERR,"FAIL: VOD capability for environment $env\n");
        exit(1);
    }
    if(!$expected){
        $s=$controller->session(Request::create('/api/v1/vod/items/3/session','POST'),3);
        $r=$controller->range(Request::create('/api/v1/vod/range','GET'));
        if($s->getStatusCode()!==403||$r->getStatusCode()!==403){
            fwrite(STDERR,"FAIL: VOD production routes must fail closed\n");
            exit(1);
        }
    }
}
unset($_SERVER['APP_ENV'],$_SERVER['IPTV_ENABLE_LOCAL_RELAY'],$_SERVER['IPTV_ENABLE_DIRECT_HLS'],$_SERVER['APP_DEBUG']);
echo "PASS: VOD relay disabled outside explicit development mode\n";
