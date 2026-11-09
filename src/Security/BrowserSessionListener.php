<?php
declare(strict_types=1);

namespace IptvService\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Browser-only session bridge. The token is held in a Secure/HttpOnly cookie,
 * never in localStorage or browser-visible JSON.
 *
 * Existing bearer/device clients continue to use Authorization headers.
 */
#[AsEventListener(event: 'kernel.request', priority: 50)]
final class BrowserSessionListener
{
    public static function cookieName(Request $request): string
    {
        return ($request->isSecure() ? '__Host-nsatellite_session' : 'nsatellite_dev_session');
    }

    public static function allowedMutation(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        $expected = $request->getSchemeAndHttpHost();
        return hash_equals($expected, $origin)
            && $request->headers->get('X-NSatellite-Request', '') === '1';
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->headers->has('Authorization')) {
            return; // Explicit API/device credentials always take precedence.
        }

        $token = (string) $request->cookies->get(self::cookieName($request), '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return;
        }

        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && !self::allowedMutation($request)) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Origine ou protection CSRF invalide'],
                403,
                ['Cache-Control' => 'no-store']
            ));
            return;
        }
        $request->headers->set('Authorization', 'Bearer '.$token);
    }
}
