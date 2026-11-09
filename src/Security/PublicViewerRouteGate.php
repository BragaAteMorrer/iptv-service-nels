<?php
declare(strict_types=1);

namespace IptvService\Security;

/**
 * Method-and-path gateway for the staged o2switch public front controller.
 * A route passing here must still pass its Symfony authentication/authorization.
 */
final class PublicViewerRouteGate
{
    public static function allows(
        string $method,
        string $path,
        bool $identityEnabled,
        bool $viewerEnabled,
        bool $directHlsEnabled
    ): bool {
        if ($method === 'GET' && $path === '/api/health') return true;

        if (!$identityEnabled) return false;

        $identityRoutes = [
            'POST /api/identity/login' => true,
            'POST /api/identity/browser/login' => true,
            'POST /api/identity/browser/logout' => true,
            'GET /api/identity/browser/session' => true,
            'POST /api/identity/logout' => true,
            'POST /api/devices/pair/start' => true,
            'POST /api/devices/pair/approve' => true,
            'POST /api/devices/pair/status' => true,
            'GET /api/devices' => true,
            'GET /api/v1/me/library' => true,
        ];
        if (isset($identityRoutes[$method.' '.$path])) return true;
        if ($method === 'DELETE'
            && preg_match('#^/api/devices/[1-9][0-9]*$#D', $path) === 1) return true;

        if (!$viewerEnabled) return false;

        $viewerRoutes = [
            'GET /api/lineup' => true,
            'GET /api/catalog/discover' => true,
            'GET /api/catalog/groups' => true,
            'GET /api/v1/vod/indexed-series' => true,
        ];
        if (isset($viewerRoutes[$method.' '.$path])) return true;
        if ($method === 'GET'
            && preg_match('#^/api/epg/channel/[1-9][0-9]*$#D', $path) === 1) return true;
        if ($method === 'GET'
            && preg_match('#^/api/v1/vod/indexed-series/[1-9][0-9]*$#D', $path) === 1) return true;
        if ($method === 'PATCH'
            && preg_match('#^/api/v1/me/library/[1-9][0-9]*/preferences$#D', $path) === 1) return true;

        return $directHlsEnabled
            && $method === 'POST'
            && preg_match('#^/api/playback/channel/[1-9][0-9]*/session$#D', $path) === 1;
    }
}
