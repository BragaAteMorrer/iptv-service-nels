<?php
declare(strict_types=1);
namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Account-scoped preferences, keyed by provider item ID, not ephemeral database row ID. */
final class PersonalLibraryController
{
    public function __construct(private readonly Connection $db) {}

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
        $sql="SELECT c.id,c.source_id,c.external_id,c.name,c.kind,c.group_name,c.logo,
            COALESCE(p.favorite,FALSE) favorite,COALESCE(p.hidden,FALSE) hidden,p.channel_number,
            w.position_seconds,w.duration_seconds
            FROM channels c
            LEFT JOIN user_channel_preferences p ON p.user_id=? AND p.source_id=c.source_id AND p.external_id=c.external_id
            LEFT JOIN user_watch_progress w ON w.user_id=? AND w.source_id=c.source_id AND w.external_id=c.external_id
            WHERE c.active=TRUE AND c.kind=?
            ORDER BY p.channel_number ASC NULLS LAST,c.name ASC,c.id ASC LIMIT ? OFFSET ?";
        $items=$this->db->fetchAllAssociative($sql,[$user,$user,$kind,$limit,($page-1)*$limit],[
            \Doctrine\DBAL\ParameterType::INTEGER,\Doctrine\DBAL\ParameterType::INTEGER,
            \Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::INTEGER,\Doctrine\DBAL\ParameterType::INTEGER
        ]);
        return new JsonResponse(['items'=>$items,'page'=>$page,'limit'=>$limit,'kind'=>$kind]);
    }

    #[Route('/api/v1/me/library/{id<\d+>}/preferences', methods:['PATCH'])]
    public function preferences(Request $request,int $id): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $channel=$this->db->fetchAssociative("SELECT source_id,external_id,kind FROM channels WHERE id=? AND active=TRUE",[$id]);
        if (!$channel) return new JsonResponse(['error'=>'Item not found'],404);
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
            $this->db->executeStatement('INSERT INTO user_channel_preferences(user_id,source_id,external_id) VALUES(?,?,?) ON CONFLICT DO NOTHING',[$user,$channel['source_id'],$channel['external_id']]);
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
        if (!$channel) return new JsonResponse(['error'=>'Item not found'],404);
        if (!in_array($channel['kind'],['movie','episode'],true)) return new JsonResponse(['error'=>'Progress only for VOD'],422);
        try {$data=$request->toArray();} catch (\Throwable) {return new JsonResponse(['error'=>'Invalid JSON'],400);}
        $position=$data['position_seconds']??null;
        $duration=$data['duration_seconds']??null;
        if (!is_int($position) || $position<0 || $position>604800 || ($duration!==null && (!is_int($duration) || $duration<0 || $duration>604800)) || ($duration!==null && $position>$duration)) {
            return new JsonResponse(['error'=>'Invalid position/duration'],422);
        }
        $this->db->executeStatement("INSERT INTO user_watch_progress(user_id,source_id,external_id,position_seconds,duration_seconds)
            VALUES(?,?,?,?,?) ON CONFLICT(user_id,source_id,external_id) DO UPDATE SET position_seconds=EXCLUDED.position_seconds,duration_seconds=EXCLUDED.duration_seconds,updated_at=NOW()",[
            $user,$channel['source_id'],$channel['external_id'],$position,$duration
        ]);
        return new JsonResponse(['updated'=>true]);
    }
}
