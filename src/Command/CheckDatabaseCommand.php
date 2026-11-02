<?php
declare(strict_types=1);

namespace IptvService\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'iptv:db:check', description: 'Safely verify database connectivity and report schema status')]
final class CheckDatabaseCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $platform = $this->db->getDatabasePlatform()->getName();
            $version = (string) $this->db->fetchOne('SELECT VERSION()');
            $database = (string) $this->db->fetchOne(
                str_contains((string) ($this->db->getParams()['driver'] ?? ''), 'mysql')
                    ? 'SELECT DATABASE()'
                    : 'SELECT current_database()'
            );
            $output->writeln('Database platform: '.$platform);
            $output->writeln('Database name: '.$database);
            $output->writeln('Server version: '.$version);
            $tableNames = $this->db->createSchemaManager()->listTableNames();
            $output->writeln('Tables detected: '.count($tableNames));
            $output->writeln('Database connection OK; no migrations were executed.');
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Database check failed. Verify DATABASE_URL, user privileges and connectivity; secrets are not printed.</error>');
            return Command::FAILURE;
        }
    }
}
