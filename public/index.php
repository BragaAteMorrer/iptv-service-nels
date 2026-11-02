<?php
declare(strict_types=1);
use IptvService\Kernel;
require dirname(__DIR__).'/vendor/autoload.php';
(new Kernel('dev', true))->handleRequest(); 
