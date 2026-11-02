<?php
declare(strict_types=1);
namespace IptvService\Controller;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class LineupController {
    public function __construct(private readonly Connection $db) {}
    private function allowed(Request $request): bool {
        $secret=$_ENV['IPTV_ADMIN_TOKEN'] ?? getenv('IPTV_ADMIN_TOKEN') ?: '';
        return strlen($secret)>=24 && hash_equals($secret,(string)$request->headers->get('X-Admin-Token',''));
    }
    #[Route('/api/lineup',methods:['GET'])]
    public function list(Request $request): JsonResponse {
        if (!$this->allowed($request)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $page=max(1,min(100000,(int)$request->query->get('page',1)));
        $limit=max(1,min(100,(int)$request->query->get('limit',50)));
        $fav=$request->query->get('favorites')==='1';
        $hidden=$request->query->get('include_hidden')==='1';
        $where=['c.kind = ?','c.active = TRUE'];
        $params=['live'];
        $types=[ParameterType::STRING];
        if ($fav) $where[]='COALESCE(p.favorite,FALSE) = TRUE';
        if (!$hidden) $where[]='COALESCE(p.hidden,FALSE) = FALSE';
        $sql='SELECT c.id,c.name,c.group_name,c.logo,c.epg_id,c.source_id,p.channel_number,COALESCE(p.favorite,FALSE) AS favorite,COALESCE(p.hidden,FALSE) AS hidden FROM channels c LEFT JOIN channel_preferences p ON p.channel_id=c.id WHERE '.implode(' AND ',$where).' ORDER BY p.channel_number ASC NULLS LAST,c.name ASC,c.id ASC LIMIT ? OFFSET ?';
        $items=$this->db->fetchAllAssociative($sql,array_merge($params,[$limit,($page-1)*$limit]),array_merge($types,[ParameterType::INTEGER,ParameterType::INTEGER]));
        return new JsonResponse(['page'=>$page,'items'=>$items]);
    }
    #[Route('/api/lineup/{id<\d+>}/preferences',methods:['PATCH'])]
    public function update(Request $request,int $id): JsonResponse {
        if (!$this->allowed($request)) return new JsonResponse(['error'=>'Unauthorized'],401);
        try {$data=$request->toArray();} catch (\Throwable) {return new JsonResponse(['error'=>'Invalid JSON'],400);}
        if ($data===[] || count(array_diff(array_keys($data),['channel_number','favorite','hidden']))>0)
            return new JsonResponse(['error'=>'Unsupported preferences'],422);
        $channel=$this->db->fetchAssociative("SELECT id FROM channels WHERE id=? AND kind='live'",[$id]);
        if (!$channel) return new JsonResponse(['error'=>'Channel not found'],404);
        foreach(['favorite','hidden'] as $key) if (array_key_exists($key,$data) && !is_bool($data[$key]))
            return new JsonResponse(['error'=>'Invalid '.$key],422);
        if (array_key_exists('channel_number',$data) && $data['channel_number']!==null
            && (!is_int($data['channel_number']) || $data['channel_number']<1 || $data['channel_number']>99999))
            return new JsonResponse(['error'=>'Invalid channel number'],422);
        // Use dynamic updates only for validated fixed-column allowlist; do not reset unspecified preferences.
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('INSERT INTO channel_preferences(channel_id) VALUES(?) ON CONFLICT(channel_id) DO NOTHING',[$id]);
            foreach (['channel_number','favorite','hidden'] as $key) if(array_key_exists($key,$data)){
                $this->db->executeStatement('UPDATE channel_preferences SET '.$key.'=?,updated_at=NOW() WHERE channel_id=?',[$data[$key],$id]);
            }
            $this->db->commit();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->db->rollBack();
            return new JsonResponse(['error'=>'Channel number already in use'],409);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return new JsonResponse(['id'=>$id,'updated'=>true]);
    }
}
