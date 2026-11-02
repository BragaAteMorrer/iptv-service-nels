<?php
declare(strict_types=1);
namespace IptvService\Security;

/**
 * Restrict provider downloads to approved public DNS names.
 * Pin the validated IP in HttpClient's resolve option to prevent DNS rebinding.
 */
final class TrustedProviderEndpoint
{
    public function requestOptions(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \RuntimeException('Invalid provider URL');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Provider endpoint must be an HTTP(S) URL without embedded credentials or fragments');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z][a-z0-9-]{1,62}$/D', $host)
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new \RuntimeException('Only approved public DNS hostnames are allowed');
        }
        $allow = array_filter(array_map(
            static fn (string $h) => strtolower(rtrim(trim($h), '.')),
            explode(',', (string) (getenv('IPTV_TRUSTED_HOSTS') ?: ''))
        ));
        if (!in_array($host, $allow, true)) {
            throw new \RuntimeException('Host not explicitly approved in IPTV_TRUSTED_HOSTS');
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            throw new \RuntimeException('Could not resolve approved provider hostname');
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('Provider hostname resolves to a private, reserved or invalid IP address');
            }
            $ips[] = $ip;
        }
        if ($ips === []) {
            throw new \RuntimeException('No public provider address found');
        }
        return [
            'resolve' => [$host => $ips[0]],
            'max_redirects' => 0,
            'timeout' => 45,
        ];
    }
}
