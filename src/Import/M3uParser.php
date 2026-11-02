<?php
declare(strict_types=1);
namespace IptvService\Import;

/**
 * Streaming extended-M3U parser: keeps memory bounded for very large playlists.
 * Caller is responsible for authenticated downloads and validated URLs.
 */
final class M3uParser
{
    /** @param resource $stream
     *  @return \Generator<array<string, mixed>>
     */
    public function parse($stream): \Generator
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Expected a readable stream');
        }

        $meta = null;
        $attributes = [];
        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#EXTINF:')) {
                $info = substr($line, 8);
                $comma = strrpos($info, ',');
                $header = $comma === false ? $info : substr($info, 0, $comma);
                $name = $comma === false ? '' : trim(substr($info, $comma + 1));
                $attributes = [];
                preg_match_all('/([\\w-]+)="([^"]*)"/u', $header, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $attributes[$match[1]] = $match[2];
                }
                $meta = ['name' => $name ?: ($attributes['tvg-name'] ?? 'Unknown'), 'attributes' => $attributes];
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            if ($meta === null) {
                continue;
            }
            yield [
                'name' => $meta['name'],
                'url' => $line,
                'group' => $attributes['group-title'] ?? '',
                'tvg_id' => $attributes['tvg-id'] ?? '',
                'logo' => $attributes['tvg-logo'] ?? '',
                'provider' => SourceType::M3U->value,
            ];
            $meta = null;
            $attributes = [];
        }
    }
}
