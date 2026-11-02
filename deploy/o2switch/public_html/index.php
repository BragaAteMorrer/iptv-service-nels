<?php
declare(strict_types=1);

// o2switch hosts the domain in public_html, while the application
// code and vendor/ remain outside the web-accessible directory.
use IptvService\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$environment = $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod';
$debug = ($_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '0') === '1';

$kernel = new Kernel($environment, $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
