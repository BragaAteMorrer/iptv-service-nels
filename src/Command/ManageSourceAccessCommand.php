<?php
declare(strict_types=1);

namespace IptvService\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iptv:source:access', description: 'List, grant or revoke account access to a source (SSH only)')]
final class ManageSourceAccessCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'list, grant or revoke');
        $this->addArgument('email', InputArgument::OPTIONAL, 'Account email for grant/revoke');
        $this->addArgument('source-id', InputArgument::OPTIONAL, 'Numeric source ID for grant/revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        if (!in_array($action, ['list', 'grant', 'revoke'], true)) {
            $io->error('Action must be list, grant or revoke.');
            return Command::INVALID;
        }
        try {
            if ($action === 'list') {
                $rows = $this->db->fetchAllAssociative(
                    'SELECT s.id, s.name, s.kind, COUNT(usa.user_id) AS grants FROM sources s LEFT JOIN user_source_access usa ON usa.source_id=s.id GROUP BY s.id, s.name, s.kind ORDER BY s.id'
                );
                $io->table(['Source ID', 'Name', 'Kind', 'Accounts allowed'], array_map(
                    static fn (array $r): array => [(string)$r['id'], (string)$r['name'], (string)$r['kind'], (string)$r['grants']], $rows
                ));
                return Command::SUCCESS;
            }
            if (!$input->isInteractive()) {
                $io->error('Interactive SSH terminal required for changing access.');
                return Command::FAILURE;
            }
            $email = strtolower(trim((string)$input->getArgument('email')));
            $rawId = (string)$input->getArgument('source-id');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[1-9][0-9]*$/D', $rawId)) {
                $io->error('Provide a valid account email and a positive numeric source ID.');
                return Command::INVALID;
            }
            $userId = $this->db->fetchOne('SELECT id FROM app_users WHERE email=?', [$email]);
            $source = $this->db->fetchAssociative('SELECT id, name, kind FROM sources WHERE id=?', [$rawId]);
            if ($userId === false || !$source) {
                $io->error('Account or source not found. No change made.');
                return Command::FAILURE;
            }
            $userId = (int)$userId;
            $sourceId = (int)$source['id'];
            $exists = $this->db->fetchOne('SELECT 1 FROM user_source_access WHERE user_id=? AND source_id=?', [$userId, $sourceId]) !== false;
            $io->writeln(sprintf('%s: %s / source #%d (%s)', strtoupper($action), $email, $sourceId, (string)$source['name']));
            $io->writeln('Current access: '.($exists ? 'GRANTED' : 'DENIED'));
            if (!$io->confirm('Apply this access change?', false)) {
                $io->warning('Cancelled. No change made.');
                return Command::SUCCESS;
            }
            if ($action === 'grant' && !$exists) {
                $this->db->insert('user_source_access', ['user_id'=>$userId, 'source_id'=>$sourceId]);
            } elseif ($action === 'revoke' && $exists) {
                $this->db->delete('user_source_access', ['user_id'=>$userId, 'source_id'=>$sourceId]);
            }
            $actual = $this->db->fetchOne('SELECT 1 FROM user_source_access WHERE user_id=? AND source_id=?', [$userId, $sourceId]) !== false;
            if ($actual !== ($action === 'grant')) {
                throw new \RuntimeException('Access change could not be confirmed.');
            }
            $io->success('Source access is now '.($actual ? 'GRANTED.' : 'REVOKED.'));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Source access operation failed ('.get_class($e).'). No provider credentials were printed.');
            return Command::FAILURE;
        }
    }
}
