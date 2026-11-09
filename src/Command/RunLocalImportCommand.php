<?php
declare(strict_types=1);

namespace IptvService\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'iptv:imports:run-local', description: 'Process one pending local M3U import (SSH/cron only)')]
final class RunLocalImportCommand extends Command
{
    public function __construct(private readonly Connection $db) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = false;
        $maria = str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql');
        try {
            if ($maria) {
                $lock = (string)$this->db->fetchOne("SELECT GET_LOCK('nels_tv_m3u_worker', 0)") === '1';
                if (!$lock) { $io->warning('Worker already running.'); return Command::SUCCESS; }
            }
            $job = $this->db->fetchAssociative(
                "SELECT j.id,j.source_id,j.file_path FROM import_jobs j WHERE j.status='queued' AND j.method='file' ORDER BY j.id LIMIT 1"
            );
            if (!$job) { $io->success('No queued local imports.'); return Command::SUCCESS; }
            $id = (int)$job['id'];
            $path = (string)$job['file_path'];
            $root = realpath(dirname(__DIR__, 2).'/var/imports');
            $real = realpath($path);
            if (!$root || !$real || !str_starts_with($real, $root.DIRECTORY_SEPARATOR) || !is_file($real) || !is_readable($real)) {
                throw new \RuntimeException('Queued import file is unavailable or outside private imports directory.');
            }
            $handle = fopen($real, 'rb');
            if (!$handle) throw new \RuntimeException('Unable to open import file.');
            $this->db->executeStatement("UPDATE import_jobs SET status='running',started_at=CURRENT_TIMESTAMP,attempts=attempts+1 WHERE id=? AND status='queued'", [$id]);
            $insert = $maria
                ? 'INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,active,last_seen_at) VALUES(?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE name=VALUES(name),group_name=VALUES(group_name),logo=VALUES(logo),epg_id=VALUES(epg_id),stream_url=VALUES(stream_url),active=1,last_seen_at=CURRENT_TIMESTAMP'
                : 'INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,active,last_seen_at) VALUES(?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP) ON CONFLICT(source_id,external_id) DO UPDATE SET name=EXCLUDED.name,group_name=EXCLUDED.group_name,logo=EXCLUDED.logo,epg_id=EXCLUDED.epg_id,stream_url=EXCLUDED.stream_url,active=TRUE,last_seen_at=CURRENT_TIMESTAMP';
            $pending = null;
            $count = 0;
            $this->db->beginTransaction();
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (str_starts_with($line, '#EXTINF:')) {
                    $pending = $line;
                    continue;
                }
                if (!$pending || $line === '' || $line[0] === '#') continue;
                $meta = $pending; $pending = null;
                $scheme = strtolower((string)parse_url($line, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true) || !filter_var($line, FILTER_VALIDATE_URL)) continue;
                $title = trim((string)substr($meta, strrpos($meta, ',') !== false ? strrpos($meta, ',')+1 : strlen($meta)));
                if ($title === '') continue;
                preg_match('/tvg-id="([^"]*)"/', $meta, $epg);
                preg_match('/tvg-logo="([^"]*)"/', $meta, $logo);
                preg_match('/group-title="([^"]*)"/', $meta, $group);
                $epgId = substr($epg[1] ?? '', 0, 255);
                $externalId = substr(hash('sha256', $epgId.'|'.$line), 0, 64);
                $this->db->executeStatement($insert, [
                    (int)$job['source_id'], $externalId, 'live', $title,
                    $group[1] ?? '', $logo[1] ?? '', $epgId, $line
                ]);
                if (++$count > 250000) throw new \RuntimeException('Import exceeds channel safety limit.');
            }
            fclose($handle);
            $handle = null;
            if ($count === 0) throw new \RuntimeException('No valid M3U channels found.');
            $this->db->executeStatement("UPDATE import_jobs SET status='completed',finished_at=CURRENT_TIMESTAMP WHERE id=?", [$id]);
            $this->db->executeStatement("UPDATE sources SET status='active',last_sync_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$job['source_id']]);
            $this->db->commit();
            $io->success('Import #'.$id.' completed ('.$count.' channels).');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if (isset($handle) && is_resource($handle)) fclose($handle);
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            if (isset($id)) {
                try { $this->db->executeStatement("UPDATE import_jobs SET status='failed',finished_at=CURRENT_TIMESTAMP WHERE id=?", [$id]); }
                catch (\Throwable) {}
            }
            $io->error('Local import failed ('.get_class($e).'). Inspect the source file and job status privately.');
            return Command::FAILURE;
        } finally {
            if ($lock) $this->db->executeStatement("SELECT RELEASE_LOCK('nels_tv_m3u_worker')");
        }
    }
}
