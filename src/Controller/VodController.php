<?php
declare(strict_types=1);
namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\SourceAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only VOD discovery. No provider URL or credentials are exposed.
 * Episodes are grouped only when an explicit SxxExx marker exists in the name.
 */
final class VodController
{
    public function __construct(private readonly Connection $db,private readonly SourceAccess $sources) {}

    private function user(Request $request): ?int
    {
        $auth=(string)$request->headers->get('Authorization','');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$matches)) return null;
        $hash=hash('sha256',$matches[1]);
        $id=$this->db->fetchOne('SELECT user_id FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW()',[$hash]);
        if ($id!==false) return (int)$id;
        $id=$this->db->fetchOne('SELECT user_id FROM app_devices WHERE device_token_hash=? AND user_id IS NOT NULL AND paired_at IS NOT NULL',[$hash]);
        return $id===false?null:(int)$id;
    }

    #[Route('/api/v1/vod/items/{id<\d+>}',methods:['GET'])]
    public function details(Request $request,int $id): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $row=$this->db->fetchAssociative(
            "SELECT c.id,c.source_id,c.external_id,c.name,c.kind,c.group_name,c.logo,
                    COALESCE(p.favorite,FALSE) AS favorite,
                    w.position_seconds,w.duration_seconds
             FROM channels c
             LEFT JOIN user_channel_preferences p ON p.user_id=? AND p.source_id=c.source_id AND p.external_id=c.external_id
             LEFT JOIN user_watch_progress w ON w.user_id=? AND w.source_id=c.source_id AND w.external_id=c.external_id
             WHERE c.id=? AND c.active=TRUE AND c.kind IN ('movie','episode')",
            [$user,$user,$id]
        );
        if (!$row || !$this->sources->allows($user,(int)$row['source_id'])) return new JsonResponse(['error'=>'Not found'],404);
        $episode=$this->parseEpisode((string)$row['name']);
        return new JsonResponse(['item'=>$row,'episode'=>$episode]);
    }

    #[Route('/api/v1/vod/series',methods:['GET'])]
    public function series(Request $request): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $page=max(1,min(10000,(int)$request->query->get('page',1)));
        $limit=max(1,min(100,(int)$request->query->get('limit',30)));
        $q=trim((string)$request->query->get('q',''));
        if (mb_strlen($q)>100) return new JsonResponse(['error'=>'Search too long'],422);
        // Search whole episode table, not only the current page.
        $maria=str_contains((string)($this->db->getParams()['driver']??''),'mysql');
        $pattern=$maria ? "name REGEXP 'S[0-9]{1,2}[[:space:]._-]*E[0-9]{1,3}'" : "name ~* 'S[0-9]{1,2}[[:space:]._-]*E[0-9]{1,3}'";
        $where="kind='episode' AND active=TRUE AND ".$pattern." AND EXISTS (SELECT 1 FROM user_source_access usa WHERE usa.source_id=channels.source_id AND usa.user_id=?)";
        $params=[$user];
        if ($q!=='') {
            $where.=$maria ? " AND LOCATE(LOWER(?),LOWER(name))>0" : " AND POSITION(LOWER(?) IN LOWER(name))>0";
            $params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';
        }
        $rows=$this->db->fetchAllAssociative(
            "SELECT id,source_id,name,logo,group_name FROM channels WHERE ".$where." ORDER BY name,id LIMIT 5000",
            $params
        );
        $series=[];
        foreach($rows as $r) {
            $parsed=$this->parseEpisode((string)$r['name']);
            if (!$parsed) continue;
            $key=$r['source_id'].':'.mb_strtolower($parsed['series']);
            if (!isset($series[$key])) $series[$key]=[
                'source_id'=>(int)$r['source_id'],'title'=>$parsed['series'],
                'poster'=>$r['logo'],'episodes'=>0,'seasons'=>[]
            ];
            $series[$key]['episodes']++;
            $series[$key]['seasons'][(string)$parsed['season']]=true;
        }
        $result=array_values(array_map(function(array $row) {
            $row['seasons']=array_map('intval',array_keys($row['seasons']));
            sort($row['seasons']);
            return $row;
        },$series));
        usort($result,fn($a,$b)=>strnatcasecmp($a['title'],$b['title']));
        return new JsonResponse([
            'items'=>array_slice($result,($page-1)*$limit,$limit),
            'total'=>count($result),'page'=>$page,'limit'=>$limit,
            'partial'=>count($rows)===5000,
            'note'=>'Regroupement par titres SxxExx explicites; limite provisoire de 5000 épisodes examinés.'
        ]);
    }

    private function parseEpisode(string $name): ?array
    {
        if (!preg_match('/^(.*?)\s*[-–.]?\s*S(\d{1,2})[\s._-]*E(\d{1,3})(?:\b|[\s._-])/iu',$name,$m)) return null;
        $title=trim($m[1]," \t\n\r\0\x0B-._");
        if ($title==='') return null;
        return ['series'=>$title,'season'=>(int)$m[2],'episode'=>(int)$m[3]];
    }
}
