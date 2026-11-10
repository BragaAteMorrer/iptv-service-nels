<?php
declare(strict_types=1);
namespace IptvService\Command;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'iptv:jobs:run',description:'Process the next queued import job')]
final class RunImportJobsCommand extends Command {
    // MariaDB 11.4 and PostgreSQL require LIMIT *before* FOR UPDATE.
    // SKIP LOCKED permits multiple workers to claim distinct queued jobs.
    // Keep the row lock within a transaction until status='running' is committed.
    public const CLAIM_SQL = "SELECT * FROM import_jobs WHERE status='queued' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED";

    public function __construct(private readonly Connection $db,private readonly ImportM3uCommand $m3u,private readonly SyncSourceCommand $sync){parent::__construct();}
    protected function execute(InputInterface $input,OutputInterface $output):int {
        $this->db->beginTransaction();
        try {
            $job=$this->db->fetchAssociative(self::CLAIM_SQL);
            if(!$job){$this->db->commit();return Command::SUCCESS;}
            $this->db->executeStatement("UPDATE import_jobs SET status='running',attempts=attempts+1,started_at=NOW() WHERE id=?",[$job['id']]);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        $ok=false;
        try {
            if($job['method']==='file') {
                $arguments=['source-id'=>(string)$job['source_id'],'file'=>(string)$job['file_path']];
                $command=$this->m3u;
            } else {
                $arguments=['source-id'=>(string)$job['source_id']];
                $command=$this->sync;
            }
            $result=$command->run(new ArrayInput($arguments,$command->getDefinition()),$output);
            $ok=$result===Command::SUCCESS;
        } catch(\Throwable $e){$output->writeln('<error>Import job failed</error>');}
        finally {
            $this->db->executeStatement('UPDATE import_jobs SET status=?,finished_at=NOW() WHERE id=?',[$ok?'completed':'failed',$job['id']]);
            if($job['method']==='file' && is_string($job['file_path']) && is_file($job['file_path']))unlink($job['file_path']);
        }
        return $ok?Command::SUCCESS:Command::FAILURE;
    }
}
