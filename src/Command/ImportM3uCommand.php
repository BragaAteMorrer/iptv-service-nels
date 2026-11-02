<?php
declare(strict_types=1);
namespace IptvService\Command;
use Doctrine\DBAL\Connection;
use IptvService\Import\M3uParser;
use IptvService\Catalog\ContentClassifier;
use IptvService\Security\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'iptv:import:m3u',description:'Import a local M3U into a registered source')]
final class ImportM3uCommand extends Command {
    public function __construct(private readonly Connection $db,private readonly M3uParser $parser,private readonly SecretBox $secrets,private readonly ContentClassifier $classifier){parent::__construct();}
    private function maria(): bool { return str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql'); }
    protected function configure(): void {
        $this->addArgument('source-id',InputArgument::REQUIRED)->addArgument('file',InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $input,OutputInterface $output): int {
        $id=(int)$input->getArgument('source-id');
        $src=$this->db->fetchAssociative('SELECT id,kind FROM sources WHERE id = ?',[$id]);
        if (!$src || $src['kind']!=='m3u') { $output->writeln('<error>Unknown M3U source</error>');return Command::FAILURE; }
        $path=(string)$input->getArgument('file');
        $stream=@fopen($path,'rb');
        if (!$stream) { $output->writeln('<error>Cannot open file</error>');return Command::FAILURE; }
        $count=0;
        try {
            $this->db->beginTransaction();
            foreach($this->parser->parse($stream) as $item) {
                $url=$item['url'];
                $name=$item['name'];
                $kind=$this->classifier->classify($name,$item['group'],$url);
                $key=hash('sha256',$url);
                $sql=$this->maria()
                    ? 'INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,last_seen_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE kind=VALUES(kind),name=VALUES(name),group_name=VALUES(group_name),logo=VALUES(logo),epg_id=VALUES(epg_id),stream_url=VALUES(stream_url),last_seen_at=NOW(),active=TRUE'
                    : 'INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,last_seen_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON CONFLICT(source_id,external_id) DO UPDATE SET kind=EXCLUDED.kind,name=EXCLUDED.name,group_name=EXCLUDED.group_name,logo=EXCLUDED.logo,epg_id=EXCLUDED.epg_id,stream_url=EXCLUDED.stream_url,last_seen_at=NOW(),active=TRUE';
                $this->db->executeStatement($sql,[$id,$key,$kind,$name,$item['group'],$item['logo'],$item['tvg_id'],$this->secrets->encrypt(['url'=>$url])]);
                if (++$count % 1000 === 0) { $this->db->commit();$this->db->beginTransaction(); }
            }
            $this->db->commit();
            $this->db->executeStatement("UPDATE sources SET status='ready',last_sync_at=NOW() WHERE id=?",[$id]);
        } catch(\Throwable $e) {
            if($this->db->isTransactionActive())$this->db->rollBack();
            $this->db->executeStatement("UPDATE sources SET status='error' WHERE id=?",[$id]);
            $output->writeln('<error>Import failed. Check application logs.</error>');
            return Command::FAILURE;
        } finally { fclose($stream); }
        $output->writeln("Imported {$count} catalog entries");
        return Command::SUCCESS;
    }
}
