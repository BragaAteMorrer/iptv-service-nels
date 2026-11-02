<?php
declare(strict_types=1);
namespace IptvService\Command;

use Doctrine\DBAL\Connection;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

/** Bounded Xtream VOD sync, suitable for manual runs or short scheduled jobs. */
#[AsCommand(name:'iptv:vod:sync',description:'Import provider series and genuine episode metadata in bounded batches')]
final class SyncVodCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $secrets,
        private readonly TrustedProviderEndpoint $trusted
    ) {parent::__construct();}

    protected function configure(): void
    {
        $this->addArgument('source-id',InputArgument::REQUIRED,'Xtream source ID');
        $this->addOption('offset',null,InputOption::VALUE_REQUIRED,'Series offset',0);
        $this->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum series per run (1-25)',10);
    }

    private function api(string $url,array $auth,string $action,array $params=[]): array
    {
        $options=$this->trusted->requestOptions($url);
        // Dedicated client: provider credentials must not appear in profiler request logs.
        $http=HttpClient::create();
        $response=$http->request('GET',$url,array_replace($options,[
            'query'=>array_merge($auth,['action'=>$action],$params),
            'timeout'=>20,'max_redirects'=>0
        ]));
        if($response->getStatusCode()!==200) throw new \RuntimeException('Provider returned non-200 status');
        $data=$response->toArray();
        if(!is_array($data)) throw new \RuntimeException('Invalid provider response');
        return $data;
    }

    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $id=(int)$input->getArgument('source-id');
        $offset=max(0,(int)$input->getOption('offset'));
        $limit=max(1,min(25,(int)$input->getOption('limit')));
        $source=$this->db->fetchAssociative("SELECT kind,endpoint,credentials FROM sources WHERE id=?",[$id]);
        if(!$source || $source['kind']!=='xtream') {
            $output->writeln('<error>Xtream source not found</error>');
            return Command::FAILURE;
        }
        try {
            $endpoint=(string)$this->secrets->decrypt((string)$source['endpoint'])['url'];
            $credentials=$this->secrets->decrypt((string)$source['credentials']);
            $api=rtrim($endpoint,'/').'/player_api.php';
            $auth=['username'=>(string)$credentials['username'],'password'=>(string)$credentials['password']];
            $seriesList=$this->api($api,$auth,'get_series');
            $batch=array_slice(array_values($seriesList),$offset,$limit);
            $processed=0;$episodes=0;$failed=0;
            foreach($batch as $item) {
                if(!is_array($item)) continue;
                $sid=(string)($item['series_id']??'');
                if(!ctype_digit($sid) || strlen($sid)>128) continue;
                try {
                    $detail=$this->api($api,$auth,'get_series_info',['series_id'=>$sid]);
                    $info=is_array($detail['info']??null)?$detail['info']:[];
                    $seasons=is_array($detail['episodes']??null)?$detail['episodes']:[];
                    $title=trim((string)($info['name']??$item['name']??''));
                    if($title==='') $title='Série '.$sid;
                    $poster=(string)($info['cover']??$item['cover']??'');
                    $synopsis=(string)($info['plot']??$item['plot']??'');
                    $genre=(string)($info['genre']??$item['genre']??'');
                    $this->db->beginTransaction();
                    $seriesId=(int)$this->db->fetchOne(
                        "INSERT INTO vod_series(source_id,provider_series_id,title,cover,synopsis,genre)
                         VALUES(?,?,?,?,?,?) ON CONFLICT(source_id,provider_series_id)
                         DO UPDATE SET title=EXCLUDED.title,cover=EXCLUDED.cover,synopsis=EXCLUDED.synopsis,
                         genre=EXCLUDED.genre,updated_at=NOW() RETURNING id",
                        [$id,$sid,$title,$poster,$synopsis,$genre]
                    );
                    foreach($seasons as $seasonKey=>$entries) {
                        if(!is_array($entries)) continue;
                        foreach($entries as $entry) {
                            if(!is_array($entry)) continue;
                            $episodeId=(string)($entry['id']??'');
                            if(!ctype_digit($episodeId) || strlen($episodeId)>120) continue;
                            $season=filter_var((string)($entry['season']??$seasonKey),FILTER_VALIDATE_INT);
                            $number=filter_var((string)($entry['episode_num']??$entry['episode']??''),FILTER_VALIDATE_INT);
                            if($season===false || $season<0 || $season>999 || $number===false || $number<0 || $number>9999) continue;
                            $episodeTitle=trim((string)($entry['title']??''));
                            if($episodeTitle==='') $episodeTitle='Épisode '.$number;
                            $logo=(string)($entry['info']['movie_image']??$poster);
                            $key='episode:'.$episodeId;
                            $channelId=(int)$this->db->fetchOne(
                                "INSERT INTO channels(source_id,external_id,kind,name,group_name,logo,epg_id,stream_url,active,last_seen_at)
                                VALUES(?,?,?,?,?,'','','',TRUE,NOW())
                                ON CONFLICT(source_id,external_id) DO UPDATE SET
                                kind='episode',name=EXCLUDED.name,group_name=EXCLUDED.group_name,
                                active=TRUE,last_seen_at=NOW() RETURNING id",
                                [$id,$key,'episode',$title.' S'.str_pad((string)$season,2,'0',STR_PAD_LEFT).'E'.str_pad((string)$number,2,'0',STR_PAD_LEFT).' · '.$episodeTitle,(string)($item['category_id']??'')]
                            );
                            $this->db->executeStatement(
                                "INSERT INTO vod_episodes(series_id,channel_id,season_number,episode_number,title)
                                VALUES(?,?,?,?,?) ON CONFLICT(channel_id) DO UPDATE SET
                                series_id=EXCLUDED.series_id,season_number=EXCLUDED.season_number,
                                episode_number=EXCLUDED.episode_number,title=EXCLUDED.title",
                                [$seriesId,$channelId,$season,$number,$episodeTitle]
                            );
                            ++$episodes;
                        }
                    }
                    $this->db->commit();
                    ++$processed;
                } catch(\Throwable) {
                    if($this->db->isTransactionActive()) $this->db->rollBack();
                    ++$failed;
                }
            }
            $output->writeln("Series processed: {$processed}; episodes synchronized: {$episodes}; failed series: {$failed}; next offset: ".($offset+count($batch)));
            return $failed===0?Command::SUCCESS:Command::FAILURE;
        } catch(\Throwable) {
            if($this->db->isTransactionActive()) $this->db->rollBack();
            $output->writeln('<error>VOD sync failed. Check source, trusted host and schema; credentials are not logged.</error>');
            return Command::FAILURE;
        }
    }
}
