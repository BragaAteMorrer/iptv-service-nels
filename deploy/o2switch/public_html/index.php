<?php
declare(strict_types=1);

// Staged API entrypoint. Identity endpoints remain disabled by default.
use IptvService\Kernel;
use IptvService\Bootstrap\Environment;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
Environment::load(dirname(__DIR__));

$request = Request::createFromGlobals();
$path = $request->getPathInfo();
$health = $path === '/api/health' && $request->getMethod() === 'GET';
$identityRoutes = [
    'POST /api/identity/login' => true,
    'POST /api/identity/logout' => true,
    'POST /api/devices/pair/start' => true,
    'POST /api/devices/pair/approve' => true,
    'POST /api/devices/pair/status' => true,
    'GET /api/devices' => true,
];
$identityEnabled = (($_SERVER['NELSTV_ENABLE_IDENTITY_API'] ?? getenv('NELSTV_ENABLE_IDENTITY_API') ?: '') === '1');
$identity = $identityEnabled && isset($identityRoutes[$request->getMethod().' '.$path]);
$revokeDevice = $identityEnabled && $request->getMethod() === 'DELETE'
    && preg_match('#^/api/devices/[1-9][0-9]*$#D', $path) === 1;
if (!$health && !$identity && !$revokeDevice) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"error":"Not found"}';
    exit;
}
$environment = $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod';
$debug = ($_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '0') === '1';
if ($environment !== 'prod' || $debug) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":"Service unavailable"}';
    exit;
}
$kernel = new Kernel('prod', false);
$response = $kernel->handle($request);
$response->headers->set('Cache-Control', 'no-store');
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->send();
$kernel->terminate($request, $response);
