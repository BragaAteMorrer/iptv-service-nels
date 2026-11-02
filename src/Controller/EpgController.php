<?php
declare(strict_types=1);
namespace IptvService\Controller;
use Doctrine\DBAL\Connection;
use IptvService\Security\ViewerAccess;
use IptvService\Security\SourceAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class EpgController {
    public function __construct(private readonly Connection $db,private readonly ViewerAccess $viewers,private readonly SourceAccess $sources){}
    private function allowed(Request $req):bool {
        $token=$_ENV['IPTV_ADMIN_TOKEN']??getenv('IPTV_ADMIN_TOKEN')?:'';
        return strlen($token)>=24 && hash_equals($token,(string)$req->headers->get('X-Admin-Token',''));
    }
    #[Route('/api/epg/channel/{id<\d+>}',methods:['GET'])]
    public function programmes(Request $req,int $id):JsonResponse {
        $user=$this->viewers->userId($req);
        if(!$this->viewers->isAdmin($req) && $user===null)return new JsonResponse(['error'=>'Unauthorized'],401);
        $channel=$this->db->fetchAssociative('SELECT c.id,c.source_id,c.name,c.epg_id,m.xmltv_channel FROM channels c LEFT JOIN epg_channel_mappings m ON m.channel_id=c.id WHERE c.id=? AND c.kind=\'live\' ',[$id]);
        if(!$channel || (!$this->viewers->isAdmin($req) && !$this->sources->allows((int)$user,(int)$channel['source_id'])))return new JsonResponse(['error'=>'Unknown channel'],404);
        $epg=$channel['xmltv_channel'] ?: $channel['epg_id'];
        if(!$epg)return new JsonResponse(['channel'=>$channel['name'],'items'=>[]]);
        $mysql=str_contains((string)($this->db->getParams()['driver']??''),'mysql');
        $sql=$mysql
          ? "SELECT title,description,starts_at,ends_at FROM epg_programmes WHERE xmltv_channel=? AND ends_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) AND starts_at<DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR) ORDER BY starts_at LIMIT 100"
          : "SELECT title,description,starts_at,ends_at FROM epg_programmes WHERE xmltv_channel=? AND ends_at>NOW()-INTERVAL '2 hours' AND starts_at<NOW()+INTERVAL '48 hours' ORDER BY starts_at LIMIT 100";
        $items=$this->db->fetchAllAssociative($sql,[$epg]);
        return new JsonResponse(['channel'=>$channel['name'],'items'=>$items]);
    }
    #[Route('/api/epg/channel/{id<\d+>}/mapping',methods:['PUT'])]
    public function map(Request $req,int $id):JsonResponse {
        if(!$this->allowed($req))return new JsonResponse(['error'=>'Unauthorized'],401);
        try{$body=$req->toArray();}catch(\Throwable){return new JsonResponse(['error'=>'Invalid JSON'],400);}
        $xmltv=$body['xmltv_channel']??null;
        if(!is_string($xmltv)||$xmltv===''||strlen($xmltv)>255)return new JsonResponse(['error'=>'Invalid XMLTV channel'],422);
        $exists=$this->db->fetchOne("SELECT id FROM channels WHERE id=? AND kind='live'",[$id]);
        if(!$exists)return new JsonResponse(['error'=>'Channel not found'],404);
        $mysql=str_contains((string)($this->db->getParams()['driver']??''),'mysql');
        $sql=$mysql ? 'INSERT INTO epg_channel_mappings(channel_id,xmltv_channel) VALUES(?,?) ON DUPLICATE KEY UPDATE xmltv_channel=VALUES(xmltv_channel)' : 'INSERT INTO epg_channel_mappings(channel_id,xmltv_channel) VALUES(?,?) ON CONFLICT(channel_id) DO UPDATE SET xmltv_channel=EXCLUDED.xmltv_channel';
        $this->db->executeStatement($sql,[$id,$xmltv]);
        return new JsonResponse(['updated'=>true]);
    }
}
