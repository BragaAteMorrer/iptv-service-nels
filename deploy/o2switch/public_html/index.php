<?php
declare(strict_types=1);

// Staged API entrypoint. Identity endpoints remain disabled by default.
use IptvService\Kernel;
use IptvService\Bootstrap\Environment;
use IptvService\Security\PublicViewerRouteGate;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
Environment::load(dirname(__DIR__));

$request = Request::createFromGlobals();
$headers = [
    'Cache-Control' => 'no-store',
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'no-referrer',
    'X-Frame-Options' => 'DENY',
    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
];
foreach ($headers as $name => $value) header($name.': '.$value);
function denyRequest(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>$message], JSON_THROW_ON_ERROR);
    exit;
}
// Only accept HTTPS and the configured public hostname for identity operations.
$secure = $request->isSecure()
    || (string)$request->server->get('HTTPS', '') === 'on';
$expectedHost = 'nsatellite.bragaatemorrer.fr';
$validHost = strtolower((string)$request->headers->get('host', '')) === $expectedHost;

$path = $request->getPathInfo();
$method = $request->getMethod();
$health = $path === '/api/health' && $method === 'GET';
$identityEnabled = (($_SERVER['NELSTV_ENABLE_IDENTITY_API'] ?? getenv('NELSTV_ENABLE_IDENTITY_API') ?: '') === '1');
$viewerEnabled = (($_SERVER['NELSTV_ENABLE_VIEWER_API'] ?? getenv('NELSTV_ENABLE_VIEWER_API') ?: '') === '1');
$directEnabled = (($_SERVER['IPTV_ENABLE_DIRECT_HLS'] ?? getenv('IPTV_ENABLE_DIRECT_HLS') ?: '') === '1');

// Route decisions are independently unit-tested and fail closed.
// Controllers must ALSO enforce authentication and source entitlements.
if (!PublicViewerRouteGate::allows($method,$path,$identityEnabled,$viewerEnabled,$directEnabled)) {
    denyRequest(404, 'Not found');
}
if (!$health && (!$secure || !$validHost)) {
    denyRequest(403, 'HTTPS and valid hostname required');
}
if (!$health && in_array($request->getMethod(), ['POST', 'PATCH', 'DELETE'], true)) {
    $origin = (string)$request->headers->get('Origin', '');
    if ($origin !== '' && $origin !== 'https://'.$expectedHost) {
        denyRequest(403, 'Origin rejected');
    }
    if (in_array($request->getMethod(), ['POST', 'PATCH'], true)) {
        $type = strtolower(trim(explode(';', (string)$request->headers->get('Content-Type', ''))[0]));
        if ($type !== 'application/json') denyRequest(415, 'JSON required');
        $size = $request->headers->get('Content-Length');
        if ($size !== null && (int)$size > 16384) denyRequest(413, 'Request too large');
    }
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
