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

    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'], $parts['pass'])
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

        $publicHosts = array_filter(array_map(
            static fn(string $value): string => strtolower(rtrim(trim($value), '.')),
            explode(',', (string)(getenv('IPTV_PUBLIC_HLS_HOSTS') ?: ''))
        ));
        if (!in_array($host, $publicHosts, true)) {
            throw new \DomainException('Host is not explicitly approved for public HLS playback');
        }

        // Also enforce the SSRF/DNS protections and exact IPTV_TRUSTED_HOSTS allowlist.
        // Validation performs DNS checks but never downloads the video stream.
        $this->trusted->requestOptions($url);
    }
}
