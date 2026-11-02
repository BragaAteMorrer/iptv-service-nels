<?php
declare(strict_types=1);
namespace IptvService\Catalog;

/** Conservative classification: prefer provider group, then file extension and episode markers. */
final class ContentClassifier {
    public function classify(string $name,string $group,string $url): string {
        $g=strtolower($group);
        $ext=strtolower(pathinfo((string)(parse_url($url,PHP_URL_PATH) ?: ''),PATHINFO_EXTENSION));
        $episode=(bool)preg_match('/(?:\bS\d{1,2}\s*E\d{1,3}\b|\b\d{1,2}x\d{1,3}\b)/i',$name);
        if ($episode || preg_match('/s[ée]ries?|series|temporada|epis[oó]dios?|season/i',$g)) return 'episode';
        if (preg_match('/\b(vod|film|films|movie|movies|cinema|cinéma)\b/iu',$g)) return 'movie';
        if (preg_match('/\b(live|tv|sport|news|radio|france|portugal|canada|qu[eé]bec)\b/iu',$g)) return 'live';
        return in_array($ext,['mkv','mp4','avi','mov'],true)?'movie':'live';
    }
}
