<?php
declare(strict_types=1);
namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\SourceAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Account-scoped preferences, keyed by provider item ID, not ephemeral database row ID. */
final class PersonalLibraryController
{
    public function __construct(private readonly Connection $db, private readonly SourceAccess $sourceAccess) {}

    private function maria(): bool { return str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql'); }

    private function user(Request $request): ?int
    {
        $header=(string)$request->headers->get('Authorization','');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$m)) return null;
        $hash=hash('sha256',$m[1]);
        $id=$this->db->fetchOne('SELECT user_id FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW()',[$hash]);
        if ($id!==false) return (int)$id;
        $id=$this->db->fetchOne('SELECT user_id FROM app_devices WHERE device_token_hash=? AND user_id IS NOT NULL AND paired_at IS NOT NULL',[$hash]);
        return $id===false ? null : (int)$id;
    }

    #[Route('/api/v1/me/library', methods:['GET'])]
    public function library(Request $request): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $kind=(string)$request->query->get('kind','live');
        if (!in_array($kind,['live','movie','episode'],true)) return new JsonResponse(['error'=>'Invalid kind'],422);
        $limit=max(1,min(100,(int)$request->query->get('limit',50)));
        $page=max(1,min(100000,(int)$request->query->get('page',1)));
        $q=trim((string)$request->query->get('q',''));
        $group=trim((string)$request->query->get('group',''));
        $favorite=(string)$request->query->get('favorite','0');
        if (mb_strlen($q)>100 || mb_strlen($group)>200 || !in_array($favorite,['0','1'],true)) {
            return new JsonResponse(['error'=>'Invalid filters'],422);
        }
        $where=['c.active=TRUE','c.kind=?','COALESCE(p.hidden,FALSE)=FALSE',
            'EXISTS (SELECT 1 FROM user_source_access usa WHERE usa.source_id=c.source_id AND usa.user_id=?)'];
        $params=[$user,$kind,$user];
        if ($group!=='') {$where[]='c.group_name=?';$params[]=$group;}
        if ($q!=='') {
            $where[]=$this->maria() ? "c.name LIKE ? ESCAPE '\\\\'" : "c.name ILIKE ? ESCAPE '\\\\'";
            $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q);
            $params[]='%'.$escaped.'%';
        }
        if ($favorite==='1') $where[]='p.favorite=TRUE';
        $join=' LEFT JOIN user_channel_preferences p ON p.user_id=? AND p.source_id=c.source_id AND p.external_id=c.external_id ';
        $filter=implode(' AND ',$where);
        $total=(int)$this->db->fetchOne('SELECT COUNT(*) FROM channels c '.$join.' WHERE '.$filter,$params);
        $items=$this->db->fetchAllAssociative(
            "SELECT c.id,c.source_id,c.external_id,c.name,c.kind,c.group_name,c.logo,
                COALESCE(p.favorite,FALSE) favorite,COALESCE(p.hidden,FALSE) hidden,p.channel_number,
                w.position_seconds,w.duration_seconds
             FROM channels c ".$join."
             LEFT JOIN user_watch_progress w ON w.user_id=? AND w.source_id=c.source_id AND w.external_id=c.external_id
             WHERE ".$filter."
             ORDER BY CASE WHEN p.channel_number IS NULL THEN 1 ELSE 0 END ASC,p.channel_number ASC,c.name ASC,c.id ASC LIMIT ? OFFSET ?",
            array_merge([$user,$user],array_slice($params,1),[$limit,($page-1)*$limit])
        );
        return new JsonResponse(['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit,'kind'=>$kind]);
    }

    #[Route('/api/v1/me/library/{id<\d+>}/preferences', methods:['PATCH'])]
    public function preferences(Request $request,int $id): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $channel=$this->db->fetchAssociative("SELECT source_id,external_id,kind FROM channels WHERE id=? AND active=TRUE",[$id]);
        if (!$channel || !$this->sourceAccess->allows($user,(int)$channel['source_id'])) return new JsonResponse(['error'=>'Item not found'],404);
        try {$data=$request->toArray();} catch (\Throwable) {return new JsonResponse(['error'=>'Invalid JSON'],400);}
        if (!$data || array_diff(array_keys($data),['favorite','hidden','channel_number'])) return new JsonResponse(['error'=>'Unsupported preferences'],422);
        foreach(['favorite','hidden'] as $key) {
            if (array_key_exists($key,$data) && !is_bool($data[$key])) return new JsonResponse(['error'=>'Invalid '.$key],422);
        }
        if (array_key_exists('channel_number',$data)
            && ($channel['kind']!=='live' || ($data['channel_number']!==null
            && (!is_int($data['channel_number']) || $data['channel_number']<1 || $data['channel_number']>99999)))) {
            return new JsonResponse(['error'=>'Invalid channel number'],422);
        }
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement(($this->maria() ? 'INSERT IGNORE INTO user_channel_preferences(user_id,source_id,external_id) VALUES(?,?,?)' : 'INSERT INTO user_channel_preferences(user_id,source_id,external_id) VALUES(?,?,?) ON CONFLICT DO NOTHING'),[$user,$channel['source_id'],$channel['external_id']]);
            foreach (['favorite','hidden','channel_number'] as $key) {
                if (array_key_exists($key,$data)) {
                    $this->db->executeStatement('UPDATE user_channel_preferences SET '.$key.'=?,updated_at=NOW() WHERE user_id=? AND source_id=? AND external_id=?',[$data[$key],$user,$channel['source_id'],$channel['external_id']]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();throw $e;
        }
        return new JsonResponse(['updated'=>true]);
    }

    #[Route('/api/v1/me/library/{id<\d+>}/progress', methods:['PUT'])]
    public function progress(Request $request,int $id): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $channel=$this->db->fetchAssociative("SELECT source_id,external_id,kind FROM channels WHERE id=? AND active=TRUE",[$id]);
        if (!$channel || !$this->sourceAccess->allows($user,(int)$channel['source_id'])) return new JsonResponse(['error'=>'Item not found'],404);
        if (!in_array($channel['kind'],['movie','episode'],true)) return new JsonResponse(['error'=>'Progress only for VOD'],422);
        try {$data=$request->toArray();} catch (\Throwable) {return new JsonResponse(['error'=>'Invalid JSON'],400);}
        $position=$data['position_seconds']??null;
        $duration=$data['duration_seconds']??null;
        if (!is_int($position) || $position<0 || $position>604800 || ($duration!==null && (!is_int($duration) || $duration<0 || $duration>604800)) || ($duration!==null && $position>$duration)) {
            return new JsonResponse(['error'=>'Invalid position/duration'],422);
        }
        $sql=$this->maria()
          ? "INSERT INTO user_watch_progress(user_id,source_id,external_id,position_seconds,duration_seconds) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE position_seconds=VALUES(position_seconds),duration_seconds=VALUES(duration_seconds),updated_at=NOW()"
          : "INSERT INTO user_watch_progress(user_id,source_id,external_id,position_seconds,duration_seconds) VALUES(?,?,?,?,?) ON CONFLICT(user_id,source_id,external_id) DO UPDATE SET position_seconds=EXCLUDED.position_seconds,duration_seconds=EXCLUDED.duration_seconds,updated_at=NOW()";
        $this->db->executeStatement($sql,[
            $user,$channel['source_id'],$channel['external_id'],$position,$duration
        ]);
        return new JsonResponse(['updated'=>true]);
    }
}
