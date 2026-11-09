<?php
declare(strict_types=1);
namespace IptvService\Security;

use Doctrine\DBAL\Connection;

/** Fail-closed source-level access for authenticated user/device principals. */
final class SourceAccess
{
    public function __construct(private readonly Connection $db) {}

    public function allows(int $userId, int $sourceId): bool
    {
        if ($userId <= 0 || $sourceId <= 0) return false;
        return $this->db->fetchOne(
            'SELECT 1 FROM user_source_access WHERE user_id=? AND source_id=?',
            [$userId, $sourceId]
        ) !== false;
    }
}
