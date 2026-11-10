<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use IptvService\Controller\VodPlaybackController;
use Symfony\Component\HttpFoundation\Request;

// No provider connection, account credentials or database needed.
$controller = (new ReflectionClass(VodPlaybackController::class))->newInstanceWithoutConstructor();
$policy = new ReflectionMethod(VodPlaybackController::class, 'localVideoRelayEnabled');
$cases = [
    ['prod', '1', false],
    ['prod', '0', false],
    ['test', '1', false],
    ['dev',  '0', false],
    ['dev',  '1', true],
];
foreach ($cases as [$env, $flag, $expected]) {
    $_SERVER['APP_ENV'] = $env;
    $_SERVER['IPTV_ENABLE_LOCAL_RELAY'] = $flag;

    if ($policy->invoke($controller) !== $expected) {
        fwrite(STDERR, "FAIL: VOD policy in $env\n");
        exit(1);
    }
    if (!$expected) {
        $post = $controller->session(Request::create('/api/v1/vod/items/5/session', 'POST'), 5);
        $range = $controller->range(Request::create('/api/v1/vod/range', 'GET'));
        if ($post->getStatusCode() !== 403 || $range->getStatusCode() !== 403) {
            fwrite(STDERR, "FAIL: VOD endpoint not denied in $env\n");
            exit(1);
        }
    }
}
unset($_SERVER['APP_ENV'], $_SERVER['IPTV_ENABLE_LOCAL_RELAY']);
echo "PASS: VOD session and range fail closed outside explicit local dev\n";
