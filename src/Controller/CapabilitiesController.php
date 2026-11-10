<?php
declare(strict_types=1);

namespace IptvService\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, non-sensitive feature availability. Does not reveal source URLs,
 * configured allowlists, account privileges or provider credentials.
 */
final class CapabilitiesController
{
    #[Route('/api/capabilities', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $env = (string)($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod');
        $localRelay = (($_SERVER['IPTV_ENABLE_LOCAL_RELAY'] ?? $_ENV['IPTV_ENABLE_LOCAL_RELAY'] ?? getenv('IPTV_ENABLE_LOCAL_RELAY') ?: '') === '1');
        $publicDirect = (($_SERVER['IPTV_ENABLE_DIRECT_HLS'] ?? $_ENV['IPTV_ENABLE_DIRECT_HLS'] ?? getenv('IPTV_ENABLE_DIRECT_HLS') ?: '') === '1');
        $debug = (($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '0') === '1');
        return new JsonResponse([
            'vod_playback' => $env === 'dev' && $localRelay,
            'tv_direct_hls' => $env === 'prod' && !$debug && $publicDirect,
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
