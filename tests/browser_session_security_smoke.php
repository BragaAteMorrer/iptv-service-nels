<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use IptvService\Security\BrowserSessionListener;
use Symfony\Component\HttpFoundation\Request;

// Run only with project dependencies installed; no database or network required.
$base = 'https://nsatellite.bragaatemorrer.fr/api/v1/me/library/1/preferences';
$origin = 'https://nsatellite.bragaatemorrer.fr';
$cases = [
    ['valid same-origin write', '1', $origin, true],
    ['missing CSRF header', '', $origin, false],
    ['missing Origin', '1', '', false],
    ['foreign Origin', '1', 'https://attacker.example', false],
    ['subdomain Origin', '1', 'https://sub.nsatellite.bragaatemorrer.fr', false],
];
foreach ($cases as [$label, $marker, $headerOrigin, $expected]) {
    $server = ['HTTPS' => 'on'];
    if ($headerOrigin !== '') $server['HTTP_ORIGIN'] = $headerOrigin;
    if ($marker !== '') $server['HTTP_X_NSATELLITE_REQUEST'] = $marker;
    $request = Request::create($base,'PATCH',[],[],[],$server);
    if (BrowserSessionListener::allowedMutation($request) !== $expected) {
        fwrite(STDERR,"FAIL: ".$label.PHP_EOL);
        exit(1);
    }
    if (BrowserSessionListener::cookieName($request) !== '__Host-nsatellite_session') {
        fwrite(STDERR,"FAIL: secure cookie name".PHP_EOL);
        exit(1);
    }
}
$local = Request::create('http://localhost:8080/api/health');
if (BrowserSessionListener::cookieName($local) !== 'nsatellite_dev_session') {
    fwrite(STDERR,"FAIL: local development cookie name".PHP_EOL);
    exit(1);
}
echo 'PASS: browser cookie and same-origin mutation policy'.PHP_EOL;
