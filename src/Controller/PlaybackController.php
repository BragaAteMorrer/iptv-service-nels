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
        private readonly HttpClientInterface $http,
    ) {}

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
            $this->trusted->requestOptions($url);
            return new JsonResponse(['url' => '/api/playback/relay?ticket=' . rawurlencode($this->ticket($url, (int)$row['source_id'])), 'expires_in' => 900]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Lecture indisponible : vérifie le serveur et les autorisations réseau'], 422);
        }
    }

    #[Route('/api/playback/relay', methods: ['GET'])]
    public function relay(Request $request): Response
    {
        try {
            $ticket = $this->openTicket((string)$request->query->get('ticket', ''));
            $url = $ticket['url'];
            $options = $this->trusted->requestOptions($url);
            $upstream = $this->http->request('GET', $url, array_replace($options, ['timeout' => 30]));
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
            return new Response('Lien de lecture invalide, expiré ou inaccessible', 403);
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
        $this->trusted->requestOptions($target);
        return '/api/playback/relay?ticket=' . rawurlencode($this->ticket($target, $sourceId));
    }
}
