<?php
declare(strict_types=1);

namespace IptvService\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\HiddenQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iptv:user:create-initial', description: 'Create the first Nels TV user securely from SSH only')]
final class CreateInitialUserCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the initial account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Interactive terminal required: password must never be passed as a CLI argument.');
            return Command::FAILURE;
        }
        $email = strtolower(trim((string) $input->getArgument('email')));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address.');
            return Command::INVALID;
        }
        try {
            if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM app_users') !== 0) {
                $io->error('An account already exists; initial account creation is disabled.');
                return Command::FAILURE;
            }
            $question = new HiddenQuestion('Password (minimum 12 characters): ');
            $question->setHiddenFallback(false);
            $password = $this->getHelper('question')->ask($input, $output, $question);
            if (!is_string($password) || strlen($password) < 12 || strlen($password) > 256) {
                $io->error('Password must contain 12 to 256 bytes.');
                return Command::INVALID;
            }
            $confirm = new HiddenQuestion('Confirm password: ');
            $confirm->setHiddenFallback(false);
            $repeated = $this->getHelper('question')->ask($input, $output, $confirm);
            if (!is_string($repeated) || !hash_equals($password, $repeated)) {
                $io->error('Passwords do not match.');
                return Command::INVALID;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            unset($password, $repeated);
            $this->db->beginTransaction();
            try {
                // Lock user table during first-account creation to prevent two simultaneous
                // CLI invocations from both creating the initial account.
                $this->db->executeStatement(
                    'INSERT INTO app_users(email,password_hash) SELECT ?,? WHERE NOT EXISTS (SELECT 1 FROM app_users)',
                    [$email, $hash]
                );
                $changed = (int) $this->db->fetchOne('SELECT COUNT(*) FROM app_users WHERE email=?', [$email]);
                if ($changed !== 1 || (int)$this->db->fetchOne('SELECT COUNT(*) FROM app_users') !== 1) {
                    throw new \RuntimeException('First account already exists or creation could not be confirmed.');
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->isTransactionActive()) $this->db->rollBack();
                throw $e;
            }
            $io->success('Initial Nels TV account created. No API routes were opened.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Account creation failed. No credentials were printed.');
            return Command::FAILURE;
        }
    }
}
