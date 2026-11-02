<?php
declare(strict_types=1);

namespace IptvService\Bootstrap;

use Symfony\Component\Dotenv\Dotenv;

/** Load local private configuration for CLI and web without committing secrets. */
final class Environment
{
    public static function load(string $root): void
    {
        $local = rtrim($root, '/').'/.env.local';
        if (is_file($local)) {
            (new Dotenv())->usePutenv()->load($local);
        }
    }
}
