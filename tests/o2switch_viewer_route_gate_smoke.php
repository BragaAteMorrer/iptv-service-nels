<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Security/PublicViewerRouteGate.php';

use IptvService\Security\PublicViewerRouteGate;

$cases = [
    // method, route, identity flag, viewer flag, direct flag, expected
    ['GET','/api/health',false,false,false,true],
    ['GET','/api/capabilities',false,false,false,true],
    ['POST','/api/capabilities',true,true,true,false],
    ['GET','/api/lineup',false,false,false,false],
    ['POST','/api/identity/login',false,true,true,false],
    ['POST','/api/identity/login',true,false,false,true],
    ['POST','/api/identity/browser/login',true,false,false,true],
    ['GET','/api/identity/browser/session',true,false,false,true],
    ['POST','/api/identity/browser/logout',true,false,false,true],
    ['GET','/api/identity/browser/session',false,true,true,false],
    ['GET','/api/identity/browser/logout',true,true,true,false],
    ['GET','/api/devices',true,false,false,true],
    ['DELETE','/api/devices/123',true,false,false,true],
    ['GET','/api/v1/me/library',true,false,false,true],
    ['GET','/api/lineup',true,false,true,false],
    ['GET','/api/lineup',true,true,false,true],
    ['GET','/api/catalog/discover',true,true,false,true],
    ['GET','/api/catalog/groups',true,true,false,true],
    ['GET','/api/epg/channel/17',true,true,false,true],
    ['GET','/api/v1/vod/indexed-series/9',true,true,false,true],
    ['PATCH','/api/v1/me/library/20/preferences',true,true,false,true],
    ['POST','/api/playback/channel/20/session',true,true,false,false],
    ['POST','/api/playback/channel/20/session',true,true,true,true],
    ['GET','/api/playback/relay',true,true,true,false],
    ['GET','/api/v1/vod/range',true,true,true,false],
    ['POST','/api/v1/vod/items/1/session',true,true,true,false],
    ['GET','/api/sources',true,true,true,false],
    ['POST','/api/sources',true,true,true,false],
    ['GET','/api/epg/channel/0001',true,true,true,false],
    ['GET','/api/lineup/1/preferences',true,true,true,false],
    ['GET','/api/playback/channel/20/session',true,true,true,false],
    ['POST','/api/playback/channel/0/session',true,true,true,false],
    ['POST','/api/playback/channel/20/session/',true,true,true,false],
    ['GET','/api/catalog/summary',true,true,true,false],
];
foreach ($cases as $n => [$method,$path,$identity,$viewer,$direct,$expected]) {
    $actual=PublicViewerRouteGate::allows($method,$path,$identity,$viewer,$direct);
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL case '.($n+1).' '.$method.' '.$path.PHP_EOL);
        exit(1);
    }
}
echo 'PASS: '.count($cases).' public viewer gateway route fixtures'.PHP_EOL;
