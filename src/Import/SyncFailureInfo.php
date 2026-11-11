<?php
declare(strict_types=1);

namespace IptvService\Import;

use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * An intentionally tiny, allowlisted diagnostic surface.
 *
 * NEVER expose exception messages: HTTP transports can embed complete URLs
 * (including M3U query-string credentials) in their exception messages.
 */
final class SyncFailureInfo
{
    /**
     * Return a fixed label only. The exception message must never be logged:
     * curl and native PHP streams can put Xtream credentials in full URLs.
     */
    private static function transportCategory(\Throwable $error):string
    {
        $message=$error->getMessage(); // inspect in memory only; NEVER echo.
        if (preg_match('/\bcURL error\s+([0-9]{1,3})\b/i',$message,$match)===1) {
            return match((int)$match[1]){
                5,6=>'network_dns_or_proxy',
                7=>'network_connect_refused',
                28=>'network_timeout',
                35=>'network_tls_handshake',
                51,60,77=>'network_tls_certificate',
                18,52,55,56=>'network_connection_closed',
                default=>'network_transport'
            };
        }
        if (preg_match('/certificate|certificate verify|SSL peer/i',$message)===1)
            return 'network_tls_certificate';
        if (preg_match('/timed out|timeout|time out/i',$message)===1)
            return 'network_timeout';
        if (preg_match('/refused|could not connect|connection failed/i',$message)===1)
            return 'network_connect_refused';
        if (preg_match('/could not resolve|name or service not known|host not found|DNS lookup/i',$message)===1)
            return 'network_dns_or_proxy';
        if (preg_match('/SSL connect|TLS handshake|handshake failure/i',$message)===1)
            return 'network_tls_handshake';
        if (preg_match('/reset by peer|connection closed|empty reply/i',$message)===1)
            return 'network_connection_closed';

        return 'network_transport';
    }

    /** @return array{category:string,http_status:int|null} */
    public static function describe(\Throwable $error,string $stage):array
    {
        if ($error instanceof ProviderHttpFailure) {
            return ['category'=>'provider_http','http_status'=>$error->status];
        }
        if ($error instanceof DatabaseException) {
            return ['category'=>'database_sql','http_status'=>null];
        }
        if ($error instanceof TransportExceptionInterface) {
            return ['category'=>self::transportCategory($error),'http_status'=>null];
        }
        if ($error instanceof DecodingExceptionInterface) {
            return ['category'=>'provider_invalid_json','http_status'=>null];
        }
        if ($stage==='decrypt') {
            return ['category'=>'decrypt_credentials','http_status'=>null];
        }
        if ($stage==='authorize_endpoint') {
            return ['category'=>'trusted_host_or_dns','http_status'=>null];
        }
        if ($error instanceof \UnexpectedValueException) {
            return ['category'=>'provider_invalid_data','http_status'=>null];
        }
        return ['category'=>'unexpected','http_status'=>null];
    }
}
