<?php
declare(strict_types=1);

namespace IptvService\Security;

/**
 * Production-safe subset: only deliberately public, credential-free HLS URLs.
 * No video bytes are fetched by PHP. Browser access still requires provider CORS.
 */
final class DirectHlsPolicy
{
    public function __construct(private readonly TrustedProviderEndpoint $trusted) {}

    public static function assertPublicUrl(string $url, array $publicHosts): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            throw new \DomainException('Direct HLS requires a plain HTTPS URL without credentials, query or fragment');
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        $path = (string)($parts['path'] ?? '');
        if (!preg_match('/\.m3u8$/iD', $path)) {
            throw new \DomainException('Only HLS playlists are eligible for direct playback');
        }

        // Common Xtream credentials embedded in paths must never be returned.
        if (preg_match('#/(?:live|movie|series)/[^/]+/[^/]+/[^/]+\.m3u8$#iD', $path)) {
            throw new \DomainException('Credential-bearing provider path is not eligible');
        }

        if (!in_array($host, $publicHosts, true)) {
            throw new \DomainException('Host is not explicitly approved for public HLS playback');
        }

    }

    public function assertAllowed(string $url): void
    {
        $publicHosts = array_filter(array_map(
            static fn(string $value): string => strtolower(rtrim(trim($value), '.')),
            explode(',', (string)(getenv('IPTV_PUBLIC_HLS_HOSTS') ?: ''))
        ));
        self::assertPublicUrl($url, $publicHosts);

        // DNS validation also enforces the independent IPTV_TRUSTED_HOSTS list.
        // No media data is fetched by this operation.
        $this->trusted->requestOptions($url);
    }
}
