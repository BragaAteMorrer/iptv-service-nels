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
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name:'iptv:sync:source',description:'Synchronize a registered, authorized remote source')]
final class SyncSourceCommand extends Command {
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $secrets,
        private readonly HttpClientInterface $http,
        private readonly M3uParser $parser,
        private readonly ContentClassifier $classifier
    ) {parent::__construct();}

    protected function configure(): void {
        $this->addArgument('source-id',InputArgument::REQUIRED,'Registered source ID');
    }

    private function trustedUrl(string $url): void {
        $parts=parse_url($url);
        if (!$parts || strtolower((string)($parts['scheme']??''))!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Only HTTPS endpoints without embedded credentials are allowed');
        }
        $host=strtolower(rtrim((string)$parts['host'],'.'));
        $allow=array_filter(array_map('trim',explode(',',strtolower($_ENV['IPTV_TRUSTED_HOSTS']??getenv('IPTV_TRUSTED_HOSTS')?:''))));
        if (!in_array($host,$allow,true)) {
            throw new \RuntimeException('Host not explicitly approved in IPTV_TRUSTED_HOSTS');
        }
    }

    private function fetchJson(string $url,array $query): array {
        $this->trustedUrl($url);
        $response=$this->http->request('GET',$url,['query'=>$query,'max_redirects'=>0,'timeout'=>45]);
        if ($response->getStatusCode()!==200) throw new \RuntimeException('Provider request failed');
        $data=$response->toArray();
        if (!is_array($data)) throw new \RuntimeException('Unexpected provider response');
        return $data;
    }

    private function writeItem(int $id,string $key,string $kind,string $name,string $group,string $logo,string $epg,string $url): void {
        $this->db->executeStatement('INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,last_seen_at,active) VALUES(?,?,?,?,?,?,?,?,NOW(),TRUE) ON CONFLICT(source_id,external_id) DO UPDATE SET kind=EXCLUDED.kind,name=EXCLUDED.name,group_name=EXCLUDED.group_name,logo=EXCLUDED.logo,epg_id=EXCLUDED.epg_id,stream_url=EXCLUDED.stream_url,last_seen_at=NOW(),active=TRUE',[$id,$key,$kind,$name,$group,$logo,$epg,$this->secrets->encrypt(['url'=>$url])]);
    }

    protected function execute(InputInterface $input,OutputInterface $output): int {
        $id=(int)$input->getArgument('source-id');
        $source=$this->db->fetchAssociative('SELECT * FROM sources WHERE id=?',[$id]);
        if (!$source) {$output->writeln('<error>Source not found</error>'); return Command::FAILURE;}
        if ($source['kind']==='mag') {$output->writeln('<error>MAG requires a provider-specific authorized adapter</error>');return Command::FAILURE;}
        $count=0;
        try {
            $url=$this->secrets->decrypt($source['endpoint'])['url'];
            $this->trustedUrl($url);
            $credentials=$this->secrets->decrypt($source['credentials']);
            $this->db->executeStatement("UPDATE sources SET status='syncing' WHERE id=?",[$id]);
            if ($source['kind']==='m3u') {
                $response=$this->http->request('GET',$url,['max_redirects'=>0,'timeout'=>90]);
                if ($response->getStatusCode()!==200) throw new \RuntimeException('Playlist request failed');
                $stream=tmpfile();
                if ($stream===false) throw new \RuntimeException('Could not create temporary file');
                try {
                    $bytes=0;
                    foreach($this->http->stream($response) as $chunk) {
                        if ($chunk->isTimeout()) throw new \RuntimeException('Playlist fetch timed out');
                        $part=$chunk->getContent();
                        $bytes+=strlen($part);
                        if ($bytes>350*1024*1024) throw new \RuntimeException('Playlist exceeds 350 MiB limit');
                        fwrite($stream,$part);
                    }
                    rewind($stream);
                    $this->db->beginTransaction();
                    foreach ($this->parser->parse($stream) as $item) {
                        $streamUrl=$item['url'];
                        $kind=$this->classifier->classify($item['name'],$item['group'],$streamUrl);
                        $this->writeItem($id,hash('sha256',$streamUrl),$kind,$item['name'],$item['group'],$item['logo'],$item['tvg_id'],$streamUrl);
                        if (++$count%1000===0) {$this->db->commit();$this->db->beginTransaction();}
                    }
                    $this->db->commit();
                } finally {fclose($stream);}
            } else {
                $api=rtrim($url,'/').'/player_api.php';
                $auth=['username'=>$credentials['username'],'password'=>$credentials['password']];
                $actions=[['get_live_streams','live'],['get_vod_streams','movie'],['get_series','episode']];
                foreach($actions as [$action,$kind]) {
                    $entries=$this->fetchJson($api,$auth+['action'=>$action]);
                    $this->db->beginTransaction();
                    foreach($entries as $item) {
                        if (!is_array($item)) continue;
                        $eid=(string)($item['stream_id']??$item['series_id']??'');
                        if ($eid==='' || !ctype_digit($eid)) continue;
                        $name=(string)($item['name']??'Unnamed');
                        $logo=(string)($item['stream_icon']??$item['cover']??'');
                        $group=(string)($item['category_id']??'');
                        // This is a metadata reference, not a playable URL. Series require episode lookup.
                        $this->writeItem($id,$kind.':'.$eid,$kind,$name,$group,$logo,'','xtream:'.$kind.':'.$eid);
                        if (++$count%1000===0) {$this->db->commit();$this->db->beginTransaction();}
                    }
                    $this->db->commit();
                }
            }
            $this->db->executeStatement("UPDATE sources SET status='ready',last_sync_at=NOW() WHERE id=?",[$id]);
            $output->writeln("Synchronized {$count} items");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            $this->db->executeStatement("UPDATE sources SET status='error' WHERE id=?",[$id]);
            $output->writeln('<error>Synchronization failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
