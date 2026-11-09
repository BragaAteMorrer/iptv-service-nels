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

#[AsCommand(name: 'iptv:catalog:check', description: 'Check the online catalog and source grants without exposing provider credentials')]
final class CatalogCheckCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Account email to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string)$input->getArgument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>254) {
            $io->error('Invalid account email.');
            return Command::INVALID;
        }
        try {
            $user = $this->db->fetchOne('SELECT id FROM app_users WHERE email=?', [$email]);
            if ($user === false) { $io->error('Account not found.'); return Command::FAILURE; }
            $userId = (int)$user;
            $sources = (int)$this->db->fetchOne('SELECT COUNT(*) FROM sources');
            $grants = (int)$this->db->fetchOne('SELECT COUNT(*) FROM user_source_access WHERE user_id=?', [$userId]);
            $jobs = $this->db->fetchAllAssociative('SELECT status,COUNT(*) AS total FROM import_jobs GROUP BY status ORDER BY status');
            $counts = $this->db->fetchAllAssociative(
                'SELECT c.kind,COUNT(*) AS total FROM channels c WHERE c.active=1 AND EXISTS (SELECT 1 FROM user_source_access usa WHERE usa.user_id=? AND usa.source_id=c.source_id) GROUP BY c.kind ORDER BY c.kind', [$userId]
            );
            $io->title('Nels TV — catalog readiness');
            $io->writeln('Account: found');
            $io->writeln('Sources installed: '.$sources);
            $io->writeln('Sources granted to this account: '.$grants);
            $io->section('Import jobs');
            if ($jobs) $io->table(['Status','Count'],array_map(static fn(array $r):array=>[(string)$r['status'],(string)$r['total']],$jobs));
            else $io->writeln('No import jobs.');
            $io->section('Accessible catalog');
            if ($counts) $io->table(['Kind','Active items'],array_map(static fn(array $r):array=>[(string)$r['kind'],(string)$r['total']],$counts));
            else $io->writeln('No catalog items accessible to this account.');
            if ($sources === 0) $io->warning('No sources yet: queue and run a local M3U import.');
            elseif ($grants === 0) $io->warning('No source granted to this account: run iptv:source:access grant.');
            elseif (!$counts) $io->warning('Sources granted but no active content: inspect import jobs, then run iptv:imports:run-local.');
            else $io->success('Database catalog is ready for authenticated browser reading.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Catalog check failed ('.get_class($e).'). No provider details were printed.');
            return Command::FAILURE;
        }
    }
}
