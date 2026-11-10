<?php
declare(strict_types=1);

namespace IptvService\Service;

use Doctrine\DBAL\Connection;
use IptvService\Security\SecretBox;

/**
 * Atomic storage only. The controller validates the user, provider URL and
 * allowlisted public DNS before calling this service.
 */
final class AccountSourceCreator
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $secrets
    ) {}

    /** @return array{source_id:int,job_id:int} */
    public function create(int $userId,array $data): array
    {
        $stage='begin';
        try {
            $this->db->beginTransaction();

            $stage='source_limit';
            $count=(int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM source_owners WHERE user_id=?',[$userId]
            );
            if ($count>=10) {
                $this->db->rollBack();
                throw new \DomainException('Source limit reached');
            }

            $stage='encryption';
            $encryptedEndpoint=$this->secrets->encrypt(['url'=>$data['url']]);
            $encryptedCredentials=$this->secrets->encrypt($data['credentials']);

            $stage='insert_source';
            $this->db->executeStatement(
                "INSERT INTO sources(name,kind,endpoint,credentials,status) VALUES(?,?,?,?, 'pending')",
                [$data['name'],$data['kind'],$encryptedEndpoint,$encryptedCredentials]
            );
            $sourceId=(int)$this->db->lastInsertId();

            $stage='insert_ownership';
            $this->db->insert('source_owners',['source_id'=>$sourceId,'user_id'=>$userId]);

            $stage='insert_grant';
            $this->db->insert('user_source_access',['source_id'=>$sourceId,'user_id'=>$userId]);

            $stage='queue_import';
            $this->db->executeStatement(
                "INSERT INTO import_jobs(source_id,method,status) VALUES(?,'remote','queued')",
                [$sourceId]
            );
            $jobId=(int)$this->db->lastInsertId();

            $stage='commit';
            $this->db->commit();
            return ['source_id'=>$sourceId,'job_id'=>$jobId];
        } catch (\DomainException $e) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            try {
                if ($this->db->isTransactionActive()) $this->db->rollBack();
            } catch (\Throwable) {
                // Do not override the useful stage of the original exception.
            }
            throw new AccountSourceWriteFailure($stage,$e);
        }
    }
}
