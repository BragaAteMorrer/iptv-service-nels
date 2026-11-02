<?php
declare(strict_types=1);
use IptvService\Kernel;
use Symfony\Component\HttpFoundation\Request;
require dirname(__DIR__).'/vendor/autoload.php';
$kernel = new Kernel($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'dev', ($_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '1') !== '0');
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
