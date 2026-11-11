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
            return ['category'=>'network_tls_timeout','http_status'=>null];
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
