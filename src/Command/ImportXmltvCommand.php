<?php
declare(strict_types=1);
namespace IptvService\Command;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'iptv:import:xmltv',description:'Import programmes from local XMLTV file')]
final class ImportXmltvCommand extends Command {
    public function __construct(private readonly Connection $db){parent::__construct();}
    private function maria(): bool { return str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql'); }
    protected function configure():void { $this->addArgument('file',InputArgument::REQUIRED,'XMLTV file path'); }
    private function date(string $raw): ?string {
        if(!preg_match('/^(\d{14})(?:\s+([+-]\d{4}))?/',trim($raw),$m)) return null;
        $date=\DateTimeImmutable::createFromFormat('!YmdHis O',$m[1].' '.($m[2]??'+0000'));
        return $date ? $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
    }
    protected function execute(InputInterface $input,OutputInterface $output):int {
        $reader=new \XMLReader();
        if(!$reader->open((string)$input->getArgument('file'),null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) {
            $output->writeln('<error>Cannot open XMLTV file</error>');return Command::FAILURE;
        }
        $count=0;$this->db->beginTransaction();
        try {
            while($reader->read()){
                if($reader->nodeType!==\XMLReader::ELEMENT || $reader->name!=='programme')continue;
                $channel=(string)$reader->getAttribute('channel');
                $start=$this->date((string)$reader->getAttribute('start'));
                $stop=$this->date((string)$reader->getAttribute('stop'));
                if(!$channel||!$start||!$stop)continue;
                $node=simplexml_load_string($reader->readOuterXML(),'SimpleXMLElement',LIBXML_NONET);
                if($node===false)continue;
                $title=trim((string)($node->title[0]??''));
                if($title==='')continue;
                $description=trim((string)($node->desc[0]??''));
                $sql=$this->maria()
                    ? 'INSERT INTO epg_programmes(xmltv_channel,title,description,starts_at,ends_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description)'
                    : 'INSERT INTO epg_programmes(xmltv_channel,title,description,starts_at,ends_at) VALUES (?,?,?,?,?) ON CONFLICT(xmltv_channel,starts_at,ends_at) DO UPDATE SET title=EXCLUDED.title,description=EXCLUDED.description';
                $this->db->executeStatement($sql,[$channel,$title,$description,$start,$stop]);
                if(++$count%1000===0){$this->db->commit();$this->db->beginTransaction();}
            }
            $this->db->commit();
        } catch(\Throwable $e) {
            if($this->db->isTransactionActive())$this->db->rollBack();
            $output->writeln('<error>XMLTV import failed</error>');return Command::FAILURE;
        } finally {$reader->close();}
        $output->writeln("Imported {$count} programmes");return Command::SUCCESS;
    }
}
