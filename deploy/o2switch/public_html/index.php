<?php
declare(strict_types=1);

// Public staging entrypoint. All application APIs except health remain closed.
use IptvService\Kernel;
use IptvService\Bootstrap\Environment;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';
Environment::load(dirname(__DIR__));

$request = Request::createFromGlobals();
$path = $request->getPathInfo();
if ($path !== '/api/health' || $request->getMethod() !== 'GET') {
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
