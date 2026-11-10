<?php
declare(strict_types=1);

namespace IptvService\Security;

/**
 * Normalize account-owned M3U/Xtream form input without doing DNS or I/O.
 * The caller MUST still use TrustedProviderEndpoint before storing or fetching.
 */
final class AccountSourceInput
{
    public static function normalize(array $data): array
    {
        if (!$data || array_diff(array_keys($data), [
            'name', 'kind', 'endpoint', 'username', 'password', 'allow_insecure_http'
        ])) {
            throw new \InvalidArgumentException('Champs non reconnus');
        }
        $kind=$data['kind']??null;
        $name=$data['name']??null;
        $raw=$data['endpoint']??null;
        if (!in_array($kind,['m3u','xtream'],true)
            || !is_string($name) || trim($name)==='' || mb_strlen(trim($name))>150
            || !is_string($raw) || trim($raw)==='' || strlen($raw)>2048) {
            throw new \InvalidArgumentException('Nom, type ou URL de source invalide');
        }
        $url=trim($raw);
        // Do not allow control characters, CRLF-injection or accidental pasted whitespace.
        if (preg_match('/[\x00-\x20\x7f]/', $url)===1
            || filter_var($url,FILTER_VALIDATE_URL)===false) {
            throw new \InvalidArgumentException('URL invalide. Utilise une adresse HTTP(S) complète avec son port éventuel.');
        }
        $parts=parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string)($parts['scheme']??'')), ['http','https'],true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Adresse HTTP(S) sans identifiants intégrés dans le domaine requise');
        }
        $scheme=strtolower((string)$parts['scheme']);
        // Ports 1-65535 are already validated by parse_url; 8080/8443 are legitimate.
        if (isset($parts['port']) && ((int)$parts['port']<1 || (int)$parts['port']>65535)) {
            throw new \InvalidArgumentException('Port fournisseur invalide');
        }
        $ack=$data['allow_insecure_http']??false;
        if (!is_bool($ack)) {
            throw new \InvalidArgumentException('Confirmation du protocole HTTP invalide');
        }
        if ($scheme==='http' && !$ack) {
            throw new \InvalidArgumentException(
                'Connexion HTTP non chiffrée : coche la confirmation des risques pour utiliser ce fournisseur.'
            );
        }
        $username=$data['username']??'';
        $password=$data['password']??'';
        if (!is_string($username) || !is_string($password)
            || strlen($username)>256 || strlen($password)>256) {
            throw new \InvalidArgumentException('Identifiants trop longs ou invalides');
        }
        if ($kind==='xtream') {
            if ($username==='' || $password==='') {
                throw new \InvalidArgumentException('Renseigne les identifiants et le mot de passe Xtream');
            }
            if (isset($parts['query'])) {
                throw new \InvalidArgumentException(
                    'Pour Xtream, utilise uniquement l’adresse du serveur (ex. http://exemple.test:8080). Pour get.php, choisis M3U.'
                );
            }
            if (preg_match('~/(?:get|player_api)\.php/?$~i',(string)($parts['path']??''))) {
                throw new \InvalidArgumentException(
                    'Pour Xtream, utilise l’adresse de base du serveur, sans get.php ni player_api.php.'
                );
            }
        }
        return [
            'name'=>trim($name), 'kind'=>$kind, 'url'=>$url,
            'credentials'=>$kind==='xtream'?['username'=>$username,'password'=>$password]:[],
        ];
    }
}
