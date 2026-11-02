<?php
declare(strict_types=1);
namespace IptvService\Import;

enum SourceType: string
{
    case M3U = 'm3u';
    case XTREAM = 'xtream';
    case MAG = 'mag';
}
