<?php
declare(strict_types=1);
namespace IptvService\Command;

use Doctrine\DBAL\Connection;
use IptvService\Import\M3uParser;
use IptvService\Catalog\ContentClassifier;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name:'iptv:sync:source',description:'Synchronize a registered, authorized remote source')]
final class SyncSourceCommand extends Command {
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $secrets,
        private readonly HttpClientInterface $http,
        private readonly M3uParser $parser,
        private readonly ContentClassifier $classifier,
        private readonly TrustedProviderEndpoint $trustedEndpoint
    ) {parent::__construct();}

    protected function configure(): void {
        $this->addArgument('source-id',InputArgument::REQUIRED,'Registered source ID');
    }

    private function maria(): bool { return str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql'); }

    private function fetchJson(string $url,array $query): array {
        $options=$this->trustedEndpoint->requestOptions($url);
        $response=HttpClient::create()->request('GET',$url,array_replace($options,['query'=>$query,'max_redirects'=>0]));
        if ($response->getStatusCode()!==200) throw new \RuntimeException('Provider request failed');
        $data=$response->toArray();
        if (!is_array($data)) throw new \RuntimeException('Unexpected provider response');
        return $data;
    }

    private function writeItem(int $id,string $key,string $kind,string $name,string $group,string $logo,string $epg,string $url): void {
        $sql=$this->maria()
          ? 'INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,last_seen_at,active) VALUES(?,?,?,?,?,?,?,?,NOW(),TRUE) ON DUPLICATE KEY UPDATE kind=VALUES(kind),name=VALUES(name),group_name=VALUES(group_name),logo=VALUES(logo),epg_id=VALUES(epg_id),stream_url=VALUES(stream_url),last_seen_at=NOW(),active=TRUE'
          : 'INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,last_seen_at,active) VALUES(?,?,?,?,?,?,?,?,NOW(),TRUE) ON CONFLICT(source_id,external_id) DO UPDATE SET kind=EXCLUDED.kind,name=EXCLUDED.name,group_name=EXCLUDED.group_name,logo=EXCLUDED.logo,epg_id=EXCLUDED.epg_id,stream_url=EXCLUDED.stream_url,last_seen_at=NOW(),active=TRUE';
        $this->db->executeStatement($sql,[$id,$key,$kind,$name,$group,$logo,$epg,$this->secrets->encrypt(['url'=>$url])]);
    }

    protected function execute(InputInterface $input,OutputInterface $output): int {
        $id=(int)$input->getArgument('source-id');
        $source=$this->db->fetchAssociative('SELECT * FROM sources WHERE id=?',[$id]);
        if (!$source) {$output->writeln('<error>Source not found</error>'); return Command::FAILURE;}
        if ($source['kind']==='mag') {$output->writeln('<error>MAG requires a provider-specific authorized adapter</error>');return Command::FAILURE;}
        $count=0;
        try {
            $url=$this->secrets->decrypt($source['endpoint'])['url'];
            $downloadOptions=$this->trustedEndpoint->requestOptions($source['kind']==='m3u' ? $url : rtrim($url,'/').'/player_api.php');
            $credentials=$this->secrets->decrypt($source['credentials']);
            $this->db->executeStatement("UPDATE sources SET status='syncing' WHERE id=?",[$id]);
            if ($source['kind']==='m3u') {
                $response=$this->http->request('GET',$url,array_replace($downloadOptions,['timeout'=>90]));
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
                $actions=[['get_live_streams','live'],['get_vod_streams','movie'],['get_series','series']];
                foreach($actions as [$action,$kind]) {
                    $entries=$this->fetchJson($api,$auth+['action'=>$action]);
                    $this->db->beginTransaction();
                    foreach($entries as $item) {
                        if (!is_array($item)) continue;
                        if ($kind==='series') {
                            $seriesId=(string)($item['series_id']??'');
                            if (!ctype_digit($seriesId) || strlen($seriesId)>128) continue;
                            $this->db->executeStatement(
                                ($this->maria()
                                 ? "INSERT INTO vod_series(source_id,provider_series_id,title,cover,synopsis,genre) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),cover=VALUES(cover),synopsis=VALUES(synopsis),genre=VALUES(genre),updated_at=NOW()"
                                 : "INSERT INTO vod_series(source_id,provider_series_id,title,cover,synopsis,genre) VALUES(?,?,?,?,?,?) ON CONFLICT(source_id,provider_series_id) DO UPDATE SET title=EXCLUDED.title,cover=EXCLUDED.cover,synopsis=EXCLUDED.synopsis,genre=EXCLUDED.genre,updated_at=NOW()"),
                                [$id,$seriesId,(string)($item['name']??'Série '.$seriesId),
                                 (string)($item['cover']??''),(string)($item['plot']??''),
                                 (string)($item['genre']??'')]
                            );
                            ++$count;
                            if ($count%1000===0) {$this->db->commit();$this->db->beginTransaction();}
                            continue;
                        }
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
                    if ($kind==='series') {
                        // Old get_series records were mistakenly marked as episodes.
                        // Deactivate only these legacy placeholders, preserving genuine
                        // episode:<id> records, user progress and imported M3U rows.
                        $this->db->executeStatement(
                            "UPDATE channels SET active=FALSE
                             WHERE source_id=? AND kind='episode' AND external_id LIKE 'episode:%'
                               AND stream_url<>'' AND id NOT IN (SELECT channel_id FROM vod_episodes)",
                            [$id]
                        );
                    }
                }
            }
            $this->db->executeStatement("UPDATE sources SET status='ready',last_sync_at=NOW() WHERE id=?",[$id]);
            $output->writeln("Synchronized {$count} items");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            $this->db->executeStatement("UPDATE sources SET status='error' WHERE id=?",[$id]);
            $output->writeln('<error>Synchronization failed; check trusted host, provider access and schema.</error>');
            return Command::FAILURE;
        }
    }
}
