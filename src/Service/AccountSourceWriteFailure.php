<?php
declare(strict_types=1);

namespace IptvService\Service;

/** Safe stage codes for operational diagnosis; never contains provider secrets. */
final class AccountSourceWriteFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $stage,
        \Throwable $previous
    ) {
        parent::__construct('Account source storage failed at '.$stage,0,$previous);
    }
}
