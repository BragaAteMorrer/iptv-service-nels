<?php
declare(strict_types=1);

namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\AccountSourceInput;
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
            'SELECT 1 FROM source_owners o JOIN user_source_access a
             ON a.source_id=o.source_id AND a.user_id=o.user_id
             WHERE o.user_id=? AND o.source_id=?',[$user,$source]
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
        try {
            $input=AccountSourceInput::normalize($data);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error'=>$e->getMessage()],422);
        }
        try {
            // The form may accept HTTP :8080 or get.php?username=...,
            // but the *server* still enforces explicitly approved public hosts,
            // a pinned DNS result, blocked private IPs and no redirects.
            $testUrl=$input['kind']==='xtream'
                ? rtrim($input['url'],'/').'/player_api.php'
                : $input['url'];
            $this->trusted->requestOptions($testUrl);
        } catch (\Throwable) {
            return new JsonResponse([
                'error'=>'Hôte fournisseur non approuvé ou non résolu. Vérifie IPTV_TRUSTED_HOSTS et le DNS public.'
            ],422);
        }
        return $input;
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
        $currentKind=$this->db->fetchOne('SELECT kind FROM sources WHERE id=?',[$id]);
        if ($currentKind!==$data['kind']) {
            return new JsonResponse(['error'=>'Le type de source ne peut pas être modifié. Crée une autre source.'],422);
        }
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
