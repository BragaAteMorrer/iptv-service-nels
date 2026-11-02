<?php
declare(strict_types=1);
namespace IptvService\Controller;
use Doctrine\DBAL\Connection;
use IptvService\Security\SecretBox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class ApiController {
    public function __construct(private Connection $db,private SecretBox $secrets){}
    private function authorized(Request $req): bool {
        $token = $_ENV['IPTV_ADMIN_TOKEN'] ?? getenv('IPTV_ADMIN_TOKEN') ?: '';
        return strlen($token)>=24 && hash_equals($token,(string)$req->headers->get('X-Admin-Token',''));
    }
    private function insertId(string $sql, array $params): int {
        if (str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql')) {
            $this->db->executeStatement($sql, $params);
            return (int)$this->db->lastInsertId();
        }
        return (int)$this->db->fetchOne($sql.' RETURNING id', $params);
    }
    #[Route('/api/health', methods:['GET'])]
    public function health(): JsonResponse { return new JsonResponse(['status'=>'ok']); }
    #[Route('/api/sources',methods:['GET'])]
    public function sources(Request $req): JsonResponse {
        if (!$this->authorized($req)) return new JsonResponse(['error'=>'Unauthorized'],401);
        return new JsonResponse($this->db->fetchAllAssociative('SELECT id,name,kind,status,last_sync_at,created_at FROM sources ORDER BY id DESC'));
    }
    #[Route('/api/sources',methods:['POST'])]
    public function create(Request $req): JsonResponse {
        if (!$this->authorized($req)) return new JsonResponse(['error'=>'Unauthorized'],401);
        try { $data=$req->toArray(); } catch (\Throwable) { return new JsonResponse(['error'=>'Invalid JSON'],400); }
        $kind=$data['kind']??'';
        $name=trim((string)($data['name']??''));
        $url=trim((string)($data['endpoint']??''));
        if (!in_array($kind,['m3u','xtream','mag'],true) || $name==='' || strlen($name)>150)
            return new JsonResponse(['error'=>'Invalid source type/name'],422);
        if ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)))
            return new JsonResponse(['error'=>'Invalid HTTP(S) endpoint'],422);
        if ($url==='') return new JsonResponse(['error'=>'Endpoint required'],422);
        $credentials=[];
        if ($kind==='xtream') {
            if (empty($data['username']) || empty($data['password'])) return new JsonResponse(['error'=>'Xtream username/password required'],422);
            $credentials=['username'=>(string)$data['username'],'password'=>(string)$data['password']];
        }
        if ($kind==='mag') {
            $mac=strtoupper((string)($data['mac']??''));
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/',$mac)) return new JsonResponse(['error'=>'Valid authorized MAC required'],422);
            $credentials=['mac'=>$mac];
        }
        $sourceId=$this->insertId('INSERT INTO sources(name,kind,endpoint,credentials,status) VALUES (?,?,?,?,?)',[$name,$kind,$this->secrets->encrypt(['url'=>$url]),$this->secrets->encrypt($credentials),'pending']);
        if($kind!=='mag'){
            $jobId=$this->insertId('INSERT INTO import_jobs(source_id,method) VALUES (?,?)',[$sourceId,'remote']);
            return new JsonResponse(['id'=>$sourceId,'job_id'=>$jobId,'status'=>'queued'],202);
        }
        return new JsonResponse(['id'=>$sourceId,'status'=>'pending','message'=>'Connecteur MAG fournisseur à configurer'],201);
    }
    #[Route('/api/catalog',methods:['GET'])]
    public function catalog(Request $req): JsonResponse {
        if (!$this->authorized($req)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $kind=(string)$req->query->get('kind','live');
        if(!in_array($kind,['live','movie','episode'],true)) return new JsonResponse(['error'=>'Invalid kind'],422);
        $page=max(1,min(100000,(int)$req->query->get('page',1)));
        $limit=max(1,min(100,(int)$req->query->get('limit',50)));
        $rows=$this->db->fetchAllAssociative('SELECT id,source_id,kind,name,group_name,logo,epg_id FROM channels WHERE kind = ? AND active = TRUE ORDER BY id LIMIT ? OFFSET ?',[$kind,$limit,($page-1)*$limit],[\Doctrine\DBAL\ParameterType::STRING,\Doctrine\DBAL\ParameterType::INTEGER,\Doctrine\DBAL\ParameterType::INTEGER]);
        return new JsonResponse(['page'=>$page,'items'=>$rows]);
    }
}
