<?php
declare(strict_types=1);
namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\SourceAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Read-only indexed series catalog; content is populated by a future Xtream metadata sync. */
final class IndexedSeriesController
{
    public function __construct(private readonly Connection $db, private readonly SourceAccess $sourceAccess) {}

    private function maria(): bool { return str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql'); }

    private function user(Request $r): ?int
    {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',(string)$r->headers->get('Authorization',''),$m)) return null;
        $hash=hash('sha256',$m[1]);
        $id=$this->db->fetchOne('SELECT user_id FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW()',[$hash]);
        if ($id!==false) return (int)$id;
        $id=$this->db->fetchOne('SELECT user_id FROM app_devices WHERE device_token_hash=? AND paired_at IS NOT NULL AND user_id IS NOT NULL',[$hash]);
        return $id===false?null:(int)$id;
    }

    #[Route('/api/v1/vod/indexed-series',methods:['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $page=max(1,min(10000,(int)$request->query->get('page',1)));
        $limit=max(1,min(100,(int)$request->query->get('limit',30)));
        $q=trim((string)$request->query->get('q',''));
        if (mb_strlen($q)>100) return new JsonResponse(['error'=>'Search too long'],422);
        $where=' WHERE EXISTS (SELECT 1 FROM user_source_access usa WHERE usa.source_id=vod_series.source_id AND usa.user_id=?)';$args=[$user];
        if($q!==''){$where.=$this->maria() ? " AND title LIKE ? ESCAPE '\\\\'" : " AND title ILIKE ? ESCAPE '\\\\'";$args[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';}
        $total=(int)$this->db->fetchOne('SELECT count(*) FROM vod_series'.$where,$args);
        $rows=$this->db->fetchAllAssociative(
            'SELECT id,source_id,provider_series_id,title,cover,synopsis,genre FROM vod_series'.$where.
            ' ORDER BY title,id LIMIT ? OFFSET ?',array_merge($args,[$limit,($page-1)*$limit])
        );
        return new JsonResponse(['items'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit]);
    }

    #[Route('/api/v1/vod/indexed-series/{id<\d+>}',methods:['GET'])]
    public function show(Request $request,int $id): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $series=$this->db->fetchAssociative(
            'SELECT id,source_id,provider_series_id,title,cover,synopsis,genre FROM vod_series WHERE id=?',[$id]
        );
        if (!$series || !$this->sourceAccess->allows($user,(int)$series['source_id'])) return new JsonResponse(['error'=>'Not found'],404);
        $episodes=$this->db->fetchAllAssociative(
            'SELECT e.season_number,e.episode_number,e.title,c.id AS channel_id,c.name,c.logo,
                    w.position_seconds,w.duration_seconds
             FROM vod_episodes e JOIN channels c ON c.id=e.channel_id AND c.active=TRUE AND c.kind=? 
             LEFT JOIN user_watch_progress w ON w.user_id=? AND w.source_id=c.source_id AND w.external_id=c.external_id
             WHERE e.series_id=? ORDER BY e.season_number,e.episode_number,c.id LIMIT 1000',
            ['episode',$user,$id]
        );
        return new JsonResponse(['series'=>$series,'episodes'=>$episodes,'truncated'=>count($episodes)===1000]);
    }
}
