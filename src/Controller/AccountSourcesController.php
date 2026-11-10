<?php
declare(strict_types=1);

namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\RequestRateLimiter;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Self-service account sources. Existing grants are NEVER treated as ownership:
 * only sources with an explicit source_owners row can be modified.
 *
 * Browser login is cookie based and converted to a server-side bearer by the
 * BrowserSessionListener. Paired device tokens and the legacy admin header
 * deliberately do not authorize account administration.
 */
final class AccountSourcesController
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly TrustedProviderEndpoint $trusted,
        private readonly RequestRateLimiter $limiter,
    ) {}

    private function user(Request $request): ?int
    {
        $auth=(string)$request->headers->get('Authorization','');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$matches)) return null;
        $id=$this->db->fetchOne(
            'SELECT user_id FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at>CURRENT_TIMESTAMP',
            [hash('sha256',$matches[1])]
        );
        return $id===false ? null : (int)$id;
    }

    private function owned(int $user,int $source): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM source_owners WHERE user_id=? AND source_id=?',[$user,$source]
        )!==false;
    }

    private function maria(): bool
    {
        return str_contains((string)($this->db->getParams()['driver']??''),'mysql');
    }

    private function input(Request $request): array|JsonResponse
    {
        try { $data=$request->toArray(); }
        catch (\Throwable) { return new JsonResponse(['error'=>'JSON invalide'],400); }
        if (!$data || array_diff(array_keys($data),['name','kind','endpoint','username','password'])) {
            return new JsonResponse(['error'=>'Champs non reconnus'],422);
        }
        $kind=$data['kind']??null;
        $name=$data['name']??null;
        $endpoint=$data['endpoint']??null;
        if (!in_array($kind,['m3u','xtream'],true) || !is_string($name)
            || trim($name)==='' || mb_strlen($name)>150 || !is_string($endpoint)
            || strlen($endpoint)>2048) {
            return new JsonResponse(['error'=>'Nom, type ou URL de source invalide'],422);
        }
        $parts=parse_url(trim($endpoint));
        if (!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && (int)$parts['port']!==443)
            || ($kind==='xtream' && isset($parts['query']))) {
            return new JsonResponse(['error'=>'Une URL HTTPS sans identifiants intégrés est requise'],422);
        }
        $url=trim($endpoint);
        $username=$data['username']??'';
        $password=$data['password']??'';
        if (!is_string($username)||!is_string($password)
            || strlen($username)>256||strlen($password)>256
            || ($kind==='xtream' && ($username===''||$password===''))) {
            return new JsonResponse(['error'=>'Identifiants Xtream manquants ou invalides'],422);
        }
        try {
            // Uses the deployment's explicit IPTV_TRUSTED_HOSTS allowlist,
            // public DNS checks and rebinding protections before queuing work.
            $this->trusted->requestOptions($kind==='xtream'?rtrim($url,'/').'/player_api.php':$url);
        } catch (\Throwable) {
            return new JsonResponse(['error'=>'Domaine fournisseur non approuvé ou inaccessible. Demande son autorisation à l’administrateur.'],422);
        }
        return [
            'name'=>trim($name),'kind'=>$kind,'url'=>$url,
            'credentials'=>$kind==='xtream'?['username'=>$username,'password'=>$password]:[]
        ];
    }

    #[Route('/api/account/sources',methods:['GET'])]
    public function sources(Request $request): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Non connecté'],401);
        $rows=$this->db->fetchAllAssociative(
            'SELECT s.id,s.name,s.kind,s.status,s.last_sync_at,so.user_id AS owner_user_id,
                (SELECT j.status FROM import_jobs j WHERE j.source_id=s.id ORDER BY j.id DESC LIMIT 1) AS import_status
             FROM user_source_access a JOIN sources s ON s.id=a.source_id
             LEFT JOIN source_owners so ON so.source_id=s.id
             WHERE a.user_id=? ORDER BY s.id DESC LIMIT 50',[$user]
        );
        $items=array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'name'=>(string)$r['name'],
            'kind'=>(string)$r['kind'],'status'=>(string)$r['status'],
            'last_sync_at'=>$r['last_sync_at'],'import_status'=>$r['import_status'],
            'managed'=>(int)($r['owner_user_id']??0)===$user
        ],$rows);
        return new JsonResponse(['items'=>$items],200,['Cache-Control'=>'no-store']);
    }

    #[Route('/api/account/sources',methods:['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Non connecté'],401);
        if (!$this->limiter->allow($request,'account-source-create',(string)$user,8,3600))
            return new JsonResponse(['error'=>'Trop de créations. Réessaie plus tard.'],429);
        $data=$this->input($request);
        if ($data instanceof JsonResponse) return $data;
        $this->db->beginTransaction();
        try {
            $count=(int)$this->db->fetchOne('SELECT COUNT(*) FROM source_owners WHERE user_id=?',[$user]);
            if ($count>=10) {
                $this->db->rollBack();
                return new JsonResponse(['error'=>'Limite de 10 sources personnelles atteinte'],409);
            }
            $this->db->executeStatement(
                "INSERT INTO sources(name,kind,endpoint,credentials,status) VALUES(?,?,?,?, 'pending')",
                [$data['name'],$data['kind'],$this->box->encrypt(['url'=>$data['url']]),$this->box->encrypt($data['credentials'])]
            );
            $source=(int)$this->db->lastInsertId();
            $this->db->insert('source_owners',['source_id'=>$source,'user_id'=>$user]);
            $this->db->insert('user_source_access',['source_id'=>$source,'user_id'=>$user]);
            $this->db->executeStatement(
                "INSERT INTO import_jobs(source_id,method,status) VALUES(?,'remote','queued')",[$source]
            );
            $job=(int)$this->db->lastInsertId();
            $this->db->commit();
            return new JsonResponse(['id'=>$source,'job_id'=>$job,'status'=>'queued'],201,['Cache-Control'=>'no-store']);
        } catch (\Throwable) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            return new JsonResponse(['error'=>'Enregistrement impossible. Vérifie la migration source_owners.'],500);
        }
    }

    #[Route('/api/account/sources/{id<\d+>}',methods:['PUT'])]
    public function update(Request $request,int $id): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Non connecté'],401);
        if (!$this->owned($user,$id)) return new JsonResponse(['error'=>'Source introuvable'],404);
        if (!$this->limiter->allow($request,'account-source-update',(string)$user,12,3600))
            return new JsonResponse(['error'=>'Trop de modifications'],429);
        $data=$this->input($request);
        if ($data instanceof JsonResponse) return $data;
        $this->db->beginTransaction();
        try {
            $pending=(int)$this->db->fetchOne(
                "SELECT COUNT(*) FROM import_jobs WHERE source_id=? AND status IN ('queued','running')",[$id]
            );
            if ($pending>0) {
                $this->db->rollBack();
                return new JsonResponse(['error'=>'Un import est déjà en cours. Attends sa fin.'],409);
            }
            $this->db->executeStatement(
                "UPDATE sources SET name=?,kind=?,endpoint=?,credentials=?,status='pending' WHERE id=?",
                [$data['name'],$data['kind'],$this->box->encrypt(['url'=>$data['url']]),$this->box->encrypt($data['credentials']),$id]
            );
            $this->db->executeStatement(
                "INSERT INTO import_jobs(source_id,method,status) VALUES(?,'remote','queued')",[$id]
            );
            $this->db->commit();
            return new JsonResponse(['updated'=>true,'status'=>'queued'],200,['Cache-Control'=>'no-store']);
        } catch (\Throwable) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            return new JsonResponse(['error'=>'Impossible de modifier la source'],500);
        }
    }

    #[Route('/api/account/sources/{id<\d+>}/sync',methods:['POST'])]
    public function synchronize(Request $request,int $id): JsonResponse
    {
        $user=$this->user($request);
        if ($user===null) return new JsonResponse(['error'=>'Non connecté'],401);
        if (!$this->owned($user,$id)) return new JsonResponse(['error'=>'Source introuvable'],404);
        if (!$this->limiter->allow($request,'account-source-sync',(string)$user,12,3600))
            return new JsonResponse(['error'=>'Trop de synchronisations'],429);
        $this->db->beginTransaction();
        try {
            $busy=(int)$this->db->fetchOne(
                "SELECT COUNT(*) FROM import_jobs WHERE source_id=? AND status IN ('queued','running')",[$id]
            );
            if ($busy>0) {
                $this->db->rollBack();
                return new JsonResponse(['error'=>'Une synchronisation est déjà en attente ou en cours'],409);
            }
            $this->db->executeStatement(
                "INSERT INTO import_jobs(source_id,method,status) VALUES(?,'remote','queued')",[$id]
            );
            $this->db->commit();
            return new JsonResponse(['queued'=>true],202,['Cache-Control'=>'no-store']);
        } catch (\Throwable) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            return new JsonResponse(['error'=>'Synchronisation impossible'],500);
        }
    }
}
