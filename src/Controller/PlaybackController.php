<?php
declare(strict_types=1);
namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Local-development HLS relay. Encrypted, short-lived tickets keep provider
 * usernames and passwords out of browser-visible URLs.
 */
final class PlaybackController
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly TrustedProviderEndpoint $trusted,
    ) {
        // Private playback client: never pass provider URLs containing subscriber
        // credentials to Symfony's request/response logging decorator.
        $this->http = HttpClient::create();
    }

    private readonly HttpClientInterface $http;

    private function ticket(string $url, int $sourceId): string
    {
        return rtrim(strtr(base64_encode($this->box->encrypt([
            'url' => $url, 'source' => $sourceId, 'expires' => time() + 900,
        ])), '+/', '-_'), '=');
    }

    private function openTicket(string $ticket): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,8192}$/D', $ticket)) {
            throw new \RuntimeException('Invalid ticket');
        }
        $decoded = base64_decode(strtr($ticket, '-_', '+/'), true);
        if (!is_string($decoded)) throw new \RuntimeException('Invalid ticket');
        $data = $this->box->decrypt($decoded);
        if (!isset($data['url'], $data['source'], $data['expires'])
            || !is_string($data['url']) || (int)$data['expires'] < time()) {
            throw new \RuntimeException('Ticket expired');
        }
        return $data;
    }


    /** Follow only a small number of individually validated HTTP redirects. */
    private function fetchTrusted(string $url, int $timeout, bool $validatedTicket = false): array
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            $options = $this->trusted->requestOptions($url, $validatedTicket || $hop > 0);
            $response = $this->http->request('GET', $url, array_replace($options, ['timeout' => $timeout]));
            $status = $response->getStatusCode();
            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return [$response, $url];
            }
            $location = $response->getHeaders(false)['location'][0] ?? '';
            $response->cancel();
            if ($hop === 3 || !is_string($location) || $location === '') {
                throw new \RuntimeException('Too many or invalid provider redirects');
            }
            if (str_starts_with($location, '//')) {
                $location = (string)parse_url($url, PHP_URL_SCHEME) . ':' . $location;
            } elseif (!preg_match('#^https?://#i', $location)) {
                if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
                    throw new \RuntimeException('Unsafe redirect scheme');
                }
                $parts = parse_url($url);
                if (!is_array($parts)) throw new \RuntimeException('Invalid redirect origin');
                $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $basePath = (string)($parts['path'] ?? '/');
                if (str_starts_with($location, '?')) {
                    $location = $origin . $basePath . $location;
                } elseif (str_starts_with($location, '/')) {
                    $location = $origin . $location;
                } else {
                    $location = $origin . substr($basePath, 0, (int)strrpos($basePath, '/') + 1) . $location;
                }
            }
            // Validation is repeated before every new outbound request, including
            // DNS pinning and exact hostname allowlist checks.
            $this->trusted->requestOptions($location, true);
            $url = $location;
        }
        throw new \RuntimeException('Too many provider redirects');
    }

    #[Route('/api/playback/channel/{id<\d+>}/session', methods: ['POST'])]
    public function session(Request $request, int $id): JsonResponse
    {
        $expected = (string) (getenv('IPTV_ADMIN_TOKEN') ?: '');
        if (strlen($expected) < 24 || !hash_equals($expected, (string)$request->headers->get('X-Admin-Token', ''))) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }
        $row = $this->db->fetchAssociative(
            "SELECT c.kind,c.external_id,c.stream_url,c.source_id,s.kind AS source_kind,s.endpoint,s.credentials
             FROM channels c JOIN sources s ON s.id=c.source_id
             WHERE c.id=? AND c.active=TRUE AND c.kind='live'", [$id]
        );
        if (!$row) return new JsonResponse(['error' => 'Chaîne introuvable'], 404);

        try {
            if ($row['source_kind'] === 'xtream') {
                if (!preg_match('/^live:(\d+)$/D', (string)$row['external_id'], $m)) {
                    return new JsonResponse(['error' => 'Référence Xtream invalide'], 422);
                }
                $base = rtrim((string)$this->box->decrypt($row['endpoint'])['url'], '/');
                $cred = $this->box->decrypt($row['credentials']);
                $url = $base . '/live/' . rawurlencode((string)$cred['username'])
                    . '/' . rawurlencode((string)$cred['password']) . '/' . $m[1] . '.m3u8';
            } elseif ($row['source_kind'] === 'm3u') {
                $url = (string)$this->box->decrypt($row['stream_url'])['url'];
                if (!str_contains(strtolower(parse_url($url, PHP_URL_PATH) ?: ''), '.m3u8')) {
                    return new JsonResponse(['error' => 'Ce flux ne semble pas être HLS (.m3u8). Le MPEG-TS direct n’est pas encore pris en charge.'], 422);
                }
            } else {
                return new JsonResponse(['error' => 'Type de source non pris en charge'], 422);
            }
            // Detect provider-side errors before giving the browser an HLS URL.
            // Never include upstream URLs, credentials or response bodies in error messages.
            [$probe, $finalUrl] = $this->fetchTrusted($url, 12);
            $status = $probe->getStatusCode();
            if ($status !== 200) {
                $probe->cancel();
                return new JsonResponse([
                    'error' => 'Le serveur de diffusion renvoie HTTP ' . $status . ' après vérification des redirections. Le flux HLS reste inaccessible.',
                    'upstream_status' => $status,
                ], 422);
            }
            $type = strtolower((string) ($probe->getHeaders(false)['content-type'][0] ?? ''));
            if (str_contains($type, 'video/mp2t') || str_contains($type, 'application/octet-stream')) {
                $probe->cancel();
                return new JsonResponse(['error' => 'Le fournisseur renvoie un flux MPEG-TS plutôt qu’une playlist HLS. Une passerelle de conversion est nécessaire.'], 422);
            }
            // A 200 response alone is not sufficient: some Xtream servers return
            // an HTML error page with HTTP 200 for unsupported extensions.
            $body = '';
            foreach ($this->http->stream($probe) as $chunk) {
                if ($chunk->isTimeout()) throw new \RuntimeException('Provider probe timed out');
                $body .= $chunk->getContent();
                if (strlen($body) > 2 * 1024 * 1024) {
                    $probe->cancel();
                    return new JsonResponse(['error' => 'La réponse du fournisseur n’est pas une playlist HLS de taille raisonnable.'], 422);
                }
            }
            if (!str_starts_with(ltrim($body), '#EXTM3U') || !str_contains($body, '#EXT-X-')) {
                return new JsonResponse(['error' => 'Le fournisseur ne renvoie pas de playlist HLS valide. Le format .m3u8 est probablement indisponible pour cette chaîne.'], 422);
            }
            return new JsonResponse(['url' => '/api/playback/relay?ticket=' . rawurlencode($this->ticket($finalUrl, (int)$row['source_id'])), 'expires_in' => 900]);
        } catch (\Throwable $e) {
            // Errors must not reveal upstream URLs, credentials, or tokens.
            $reason = $e->getMessage();
            $label = match (true) {
                str_contains($reason, 'IPTV_TRUSTED_HOSTS') => 'Redirection vers un domaine absent de IPTV_TRUSTED_HOSTS. Autorise uniquement un domaine de confiance.',
                str_contains($reason, 'private, reserved') => 'Destination bloquée : ' . (preg_match('/\\[(ipv[46]_[a-z_]+|invalid_address)\\]/', $reason, $m) ? $m[1] : 'adresse privée ou réservée') . '. Aucune connexion n’a été effectuée.',
                str_contains($reason, 'public DNS') => 'La destination ne possède pas de nom DNS public valide.',
                str_contains($reason, 'Direct IP requires') => 'Une adresse IP directe a été refusée hors redirection validée.',
                str_contains($reason, 'resolve') || str_contains($reason, 'DNS') => 'Impossible de résoudre le domaine du serveur de diffusion.',
                str_contains($reason, 'redirect') => 'Redirection du fournisseur invalide ou trop longue.',
                str_contains($reason, 'timed out') || str_contains($reason, 'Timeout') => 'Le fournisseur ne répond pas assez vite.',
                default => 'Impossible de contacter le serveur de diffusion. Consulte les journaux serveur.',
            };
            error_log('Playback session failed: ' . get_class($e));
            return new JsonResponse(['error' => $label], 422);
        }
    }

    #[Route('/api/playback/relay', methods: ['GET'])]
    public function relay(Request $request): Response
    {
        try {
            $ticket = $this->openTicket((string)$request->query->get('ticket', ''));
            $url = $ticket['url'];
            // Relay tickets are encrypted and created only after a validated redirect or HLS reference.
            [$upstream, $url] = $this->fetchTrusted($url, 30, true);
            if ($upstream->getStatusCode() !== 200) {
                return new Response('Flux indisponible (HTTP ' . $upstream->getStatusCode() . ')', 502);
            }
            $contentType = strtolower($upstream->getHeaders(false)['content-type'][0] ?? '');
            $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');
            $playlist = str_contains($contentType, 'mpegurl') || str_ends_with($path, '.m3u8');
            if ($playlist) {
                $body = '';
                foreach ($this->http->stream($upstream) as $chunk) {
                    if ($chunk->isTimeout()) throw new \RuntimeException('Timeout');
                    $body .= $chunk->getContent();
                    if (strlen($body) > 2 * 1024 * 1024) throw new \RuntimeException('Playlist too large');
                }
                if (!str_starts_with(ltrim($body), '#EXTM3U')) throw new \RuntimeException('Not an HLS playlist');
                if (preg_match('/#EXT-X-(KEY|SESSION-KEY)\s*:/i', $body)) {
                    return new Response('Flux HLS chiffré non pris en charge', 415);
                }
                $lines = preg_split('/(\r\n|\n|\r)/', $body);
                $rewritten = [];
                foreach ($lines as $line) {
                    $trim = trim($line);
                    if ($trim !== '' && $trim[0] !== '#') {
                        $line = $this->rewrite($url, $trim, (int)$ticket['source']);
                    } elseif (preg_match('/^#EXT-X-(MAP|MEDIA|I-FRAME-STREAM-INF):/i', $trim)) {
                        $line = preg_replace_callback('/URI="([^"]+)"/', fn($m) => 'URI="' . $this->rewrite($url, $m[1], (int)$ticket['source']) . '"', $line);
                    }
                    $rewritten[] = $line;
                }
                return new Response(implode("\n", $rewritten), 200, [
                    'Content-Type' => 'application/vnd.apple.mpegurl',
                    'Cache-Control' => 'no-store',
                    'Referrer-Policy' => 'no-referrer',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            $response = new StreamedResponse(function () use ($upstream): void {
                $total = 0;
                foreach ($this->http->stream($upstream) as $chunk) {
                    if ($chunk->isTimeout()) break;
                    $data = $chunk->getContent();
                    $total += strlen($data);
                    if ($total > 32 * 1024 * 1024) break;
                    echo $data;
                    flush();
                }
            }, 200, [
                'Content-Type' => str_contains($contentType, 'mp4') ? 'video/mp4' : 'video/mp2t',
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ]);
            return $response;
        } catch (\Throwable $e) {
            error_log('Playback relay failed: ' . get_class($e));
            return new Response('Lecture relay refusée ou indisponible', 403);
        }
    }

    private function rewrite(string $base, string $target, int $sourceId): string
    {
        if (str_starts_with($target, '//')) {
            $target = (parse_url($base, PHP_URL_SCHEME) ?: 'http') . ':' . $target;
        } elseif (!preg_match('#^https?://#i', $target)) {
            $parts = parse_url($base);
            $root = $parts['scheme'] . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (str_starts_with($target, '/')) {
                $target = $root . $target;
            } else {
                $dir = substr($parts['path'] ?? '/', 0, (int)strrpos($parts['path'] ?? '/', '/') + 1);
                $target = $root . $dir . $target;
            }
        }
        // Revalidate every referenced hostname and pin its resolved address when requested.
        $this->trusted->requestOptions($target, true);
        return '/api/playback/relay?ticket=' . rawurlencode($this->ticket($target, $sourceId));
    }
}
