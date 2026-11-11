<?php
declare(strict_types=1);

namespace IptvService\Import;

/**
 * Only a numeric status is retained; never include the provider URL or its
 * credentials in the public exception message.
 */
final class ProviderHttpFailure extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Provider HTTP status '.(int)$status);
    }
}
