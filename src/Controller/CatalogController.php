<?php
declare(strict_types=1);
namespace IptvService\Controller;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class CatalogController {
    public function __construct(private readonly Connection $db) {}
    private function auth(Request $req): bool {
        $token=$_ENV['IPTV_ADMIN_TOKEN'] ?? getenv('IPTV_ADMIN_TOKEN') ?: '';
        return strlen($token)>=24 && hash_equals($token,(string)$req->headers->get('X-Admin-Token',''));
    }
    #[Route('/api/catalog/discover',methods:['GET'])]
    public function discover(Request $req): JsonResponse {
        if (!$this->auth($req)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $kind=(string)$req->query->get('kind','live');
        if (!in_array($kind,['live','movie','episode'],true)) return new JsonResponse(['error'=>'Unknown content kind'],422);
        $page=max(1,min(100000,(int)$req->query->get('page',1)));
        $limit=max(1,min(100,(int)$req->query->get('limit',48)));
        $source=max(0,(int)$req->query->get('source',0));
        $group=trim((string)$req->query->get('group',''));
        $q=trim((string)$req->query->get('q',''));
        if (mb_strlen($q)>100 || mb_strlen($group)>200) return new JsonResponse(['error'=>'Filter too long'],422);
        $where=['c.kind=?','c.active=TRUE']; $params=[$kind];$types=[ParameterType::STRING];
        if ($source>0){$where[]='c.source_id=?';$params[]=$source;$types[]=ParameterType::INTEGER;}
        if ($group!==''){$where[]='c.group_name=?';$params[]=$group;$types[]=ParameterType::STRING;}
        if ($q!==''){$where[]='c.name ILIKE ?';$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';$types[]=ParameterType::STRING;}
        $filter=implode(' AND ',$where);
        $total=(int)$this->db->fetchOne('SELECT COUNT(*) FROM channels c WHERE '.$filter,$params,$types);
        $rows=$this->db->fetchAllAssociative('SELECT c.id,c.source_id,c.kind,c.name,c.group_name,c.logo,c.epg_id FROM channels c WHERE '.$filter.' ORDER BY c.name,c.id LIMIT ? OFFSET ?',array_merge($params,[$limit,($page-1)*$limit]),array_merge($types,[ParameterType::INTEGER,ParameterType::INTEGER]));
        return new JsonResponse(['kind'=>$kind,'page'=>$page,'limit'=>$limit,'total'=>$total,'items'=>$rows]);
    }
    #[Route('/api/catalog/groups',methods:['GET'])]
    public function groups(Request $req): JsonResponse {
        if (!$this->auth($req)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $kind=(string)$req->query->get('kind','live');
        if (!in_array($kind,['live','movie','episode'],true)) return new JsonResponse(['error'=>'Unknown content kind'],422);
        $rows=$this->db->fetchAllAssociative('SELECT group_name AS name, COUNT(*) AS total FROM channels WHERE kind=? AND active=TRUE GROUP BY group_name ORDER BY COUNT(*) DESC,group_name LIMIT 500',[$kind]);
        return new JsonResponse(['items'=>$rows]);
    }
    #[Route('/api/catalog/summary',methods:['GET'])]
    public function summary(Request $req): JsonResponse {
        if (!$this->auth($req)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $rows=$this->db->fetchAllAssociative('SELECT kind,COUNT(*) AS total FROM channels WHERE active=TRUE GROUP BY kind');
        return new JsonResponse(['items'=>$rows]);
    }
}
