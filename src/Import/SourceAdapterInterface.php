<?php
declare(strict_types=1);
namespace IptvService\Import;

/** Each adapter yields normalized catalog records without exposing credentials. */
interface SourceAdapterInterface
{
    public function type(): SourceType;

    /** @return iterable<array<string, mixed>> */
    public function listChannels(): iterable;
}
