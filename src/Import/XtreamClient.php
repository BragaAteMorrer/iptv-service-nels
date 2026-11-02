<?php
declare(strict_types=1);
namespace IptvService\Import;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for a provider-authorized Xtream-compatible player_api.php.
 * Never log credentials or full request URLs.
 */
final class XtreamClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /** @return array<string, mixed>|list<mixed> */
    public function request(?string $action = null, array $parameters = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/player_api.php';
        $query = ['username' => $this->username, 'password' => $this->password];
        if ($action !== null) {
            $query['action'] = $action;
        }
        $response = $this->http->request('GET', $url, [
            'query' => array_merge($query, $parameters),
            'timeout' => 20,
            'max_redirects' => 0,
        ]);
        $data = $response->toArray();
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Invalid API response');
        }
        return $data;
    }

    public function account(): array { return $this->request(); }
    public function liveCategories(): array { return $this->request('get_live_categories'); }
    public function liveStreams(): array { return $this->request('get_live_streams'); }
    public function vodCategories(): array { return $this->request('get_vod_categories'); }
    public function vodStreams(): array { return $this->request('get_vod_streams'); }
    public function seriesCategories(): array { return $this->request('get_series_categories'); }
    public function series(): array { return $this->request('get_series'); }
    public function shortEpg(int $streamId, int $limit = 4): array
    {
        return $this->request('get_short_epg', ['stream_id' => $streamId, 'limit' => $limit]);
    }
}
