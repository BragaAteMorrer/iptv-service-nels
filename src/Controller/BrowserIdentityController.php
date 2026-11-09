<?php
declare(strict_types=1);

namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\BrowserSessionListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Separate browser login: no bearer secret exposed to JavaScript.
 * Reuses the same database sessions and login rate limits as identity/login.
 */
final class BrowserIdentityController
{
    public function __construct(
        private readonly IdentityController $identity,
        private readonly Connection $db
    ) {}

    private function cookie(Request $request, string $value, int $expires): Cookie
    {
        return Cookie::create(
            BrowserSessionListener::cookieName($request),
            $value,
            $expires,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    private function userId(Request $request): ?int
    {
        $token = (string)$request->cookies->get(BrowserSessionListener::cookieName($request), '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $user = $this->db->fetchOne(
            'SELECT user_id FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP',
            [hash('sha256', $token)]
        );
        return $user === false ? null : (int)$user;
    }

    #[Route('/api/identity/browser/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        if (!BrowserSessionListener::allowedMutation($request)) {
            return new JsonResponse(['error' => 'Origine invalide'], 403, ['Cache-Control' => 'no-store']);
        }
        $login = $this->identity->login($request);
        if ($login->getStatusCode() !== 200) return $login;
        $data = json_decode((string)$login->getContent(), true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return new JsonResponse(['error' => 'Connexion impossible'], 500);
        }

        $response = new JsonResponse(
            ['authenticated' => true, 'expires_in' => 604800],
            200,
            ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']
        );
        $response->headers->setCookie($this->cookie($request, $token, time() + 604800));
        return $response;
    }

    #[Route('/api/identity/browser/session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        if ($this->userId($request) === null) {
            return new JsonResponse(['authenticated' => false], 401, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse(['authenticated' => true], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/identity/browser/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        if (!BrowserSessionListener::allowedMutation($request)) {
            return new JsonResponse(['error' => 'Origine invalide'], 403, ['Cache-Control' => 'no-store']);
        }
        // Revoke exactly the cookie session, never an unrelated bearer/device token.
        $token = (string)$request->cookies->get(BrowserSessionListener::cookieName($request), '');
        if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $this->db->executeStatement(
                'UPDATE app_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_hash=?',
                [hash('sha256', $token)]
            );
        }
        $response = new JsonResponse(['ok' => true], 200, ['Cache-Control' => 'no-store']);
        $response->headers->setCookie($this->cookie($request, '', 1));
        return $response;
    }
}
