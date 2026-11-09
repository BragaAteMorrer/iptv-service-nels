<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Security/DirectHlsPolicy.php';

use IptvService\Security\DirectHlsPolicy;

// Deterministic policy test: no DNS, database, network, or real stream needed.
$hosts=['cdn.example.org'];
$valid=[
    'https://cdn.example.org/channel.m3u8',
    'https://CDN.EXAMPLE.ORG/live/news.m3u8',
];
$invalid=[
    'http://cdn.example.org/channel.m3u8',
    'https://evil.example.org/channel.m3u8',
    'https://cdn.example.org/channel.m3u8?key=secret',
    'https://cdn.example.org/channel.m3u8#fragment',
    'https://user:pass@cdn.example.org/channel.m3u8',
    'https://cdn.example.org:8443/channel.m3u8',
    'https://cdn.example.org/channel.ts',
    'https://cdn.example.org/live/user/pass/100.m3u8',
    'file:///etc/passwd',
];
foreach ($valid as $url) {
    DirectHlsPolicy::assertPublicUrl($url,$hosts);
}
foreach ($invalid as $url) {
    try {
        DirectHlsPolicy::assertPublicUrl($url,$hosts);
    } catch (\DomainException) {
        continue;
    }
    fwrite(STDERR,"Expected reject for URL category: ".$url.PHP_EOL);
    exit(1);
}
echo "PASS: direct HLS URL policy accepted ".count($valid)." fixtures and rejected ".count($invalid)." fixtures".PHP_EOL;
