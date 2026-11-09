<?php
declare(strict_types=1);

namespace IptvService\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Local, fail-closed rate limiter for a single o2switch application node.
 * State lives under var/ (outside public_html) and is guarded with flock.
 */
final class RequestRateLimiter
{
    public function allow(Request $request, string $action, string $subject, int $limit, int $windowSeconds): bool
    {
        $ip = (string) ($request->server->get('REMOTE_ADDR') ?: '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // No X-Forwarded-For trust: only the server-reported peer is authoritative.
        $key = hash('sha256', $action."\0".$subject."\0".$ip);
        $dir = dirname(__DIR__, 2).'/var/rate-limits';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $file = $dir.'/'.$key.'.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) return false;
        try {
            if (!flock($handle, LOCK_EX)) return false;
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $now = time();
            $start = is_array($decoded) && is_int($decoded['start'] ?? null) ? $decoded['start'] : $now;
            $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? $decoded['count'] : 0;
            if ($start > $now || $start + $windowSeconds <= $now) {
                $start = $now;
                $count = 0;
            }
            $allowed = $count < $limit;
            $data = json_encode(['start' => $start, 'count' => min($count + 1, $limit + 1)], JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0)) return false;
            if (fwrite($handle, $data) !== strlen($data)) return false;
            if (!fflush($handle)) return false;
            @chmod($file, 0600);
            return $allowed;
        } catch (\Throwable) {
            return false;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
