<?php
declare(strict_types=1);

namespace IptvService\Command;

use Doctrine\DBAL\Connection;
use IptvService\Security\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iptv:source:import-file', description: 'Queue a local M3U playlist import securely from SSH')]
final class ImportLocalSourceCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly SecretBox $secrets) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Public display name of the source');
        $this->addArgument('file', InputArgument::REQUIRED, 'Absolute path to an authorized local M3U file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('An interactive SSH terminal is required.');
            return Command::FAILURE;
        }
        $name = trim((string)$input->getArgument('name'));
        $inputPath = (string)$input->getArgument('file');
        if ($name === '' || strlen($name) > 150 || !str_starts_with($inputPath, '/')) {
            $io->error('Invalid name or file path.');
            return Command::INVALID;
        }
        $path = realpath($inputPath);
        if ($path === false || !is_file($path) || is_link($inputPath) || !is_readable($path)
            || ($size=filesize($path)) === false || $size > 350*1024*1024) {
            $io->error('File must be a readable regular M3U of at most 350 MiB.');
            return Command::INVALID;
        }
        $stream = fopen($path, 'rb');
        $first = $stream ? fread($stream, 2048) : false;
        if ($stream) fclose($stream);
        if (!is_string($first) || (!str_starts_with(ltrim($first), '#EXTM3U') && !str_contains($first, '#EXTINF:'))) {
            $io->error('The selected file does not look like an M3U playlist.');
            return Command::INVALID;
        }
        $io->writeln('Source name: '.$name);
        $io->writeln('File size: '.$size.' bytes');
        if (!$io->confirm('Queue this playlist for import?', false)) return Command::SUCCESS;
        $root = dirname(__DIR__, 2);
        $dir = $root.'/var/imports';
        $destination = null;
        try {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Import directory unavailable.');
            }
            $destination = $dir.'/'.bin2hex(random_bytes(20)).'.m3u';
            if (!copy($path, $destination)) throw new \RuntimeException('Unable to copy import file.');
            chmod($destination, 0600);
            $maria = str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql');
            $this->db->beginTransaction();
            $sql = 'INSERT INTO sources(name,kind,endpoint,credentials,status) VALUES (?,?,?,?,?)';
            $args = [$name, 'm3u', $this->secrets->encrypt(['url'=>'file-upload']), $this->secrets->encrypt([]), 'pending'];
            if ($maria) {
                $this->db->executeStatement($sql, $args);
                $sourceId = (int)$this->db->lastInsertId();
            } else {
                $sourceId = (int)$this->db->fetchOne($sql.' RETURNING id', $args);
            }
            $this->db->insert('import_jobs', ['source_id'=>$sourceId, 'method'=>'file', 'file_path'=>$destination, 'status'=>'queued']);
            $this->db->commit();
            $io->success('Source #'.$sourceId.' created. Import is queued; a separate worker must process it.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            if ($destination !== null && is_file($destination)) unlink($destination);
            $io->error('Could not queue import ('.get_class($e).').');
            return Command::FAILURE;
        }
    }
}
