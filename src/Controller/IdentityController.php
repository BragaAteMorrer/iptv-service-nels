<?php
declare(strict_types=1);
namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\RequestRateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Initial personal-account and device-pairing API. All tokens are stored hashed.
 * Local development only until hardened, rate-limited and deployed behind HTTPS.
 */
final class IdentityController
{
    public function __construct(private readonly Connection $db, private readonly RequestRateLimiter $limiter) {}

    private function throttled(): JsonResponse
    {
        return new JsonResponse(['error'=>'Too many requests'],429,['Retry-After'=>'900','Cache-Control'=>'no-store']);
    }

    private function maria(): bool { return str_contains((string)($this->db->getParams()['driver'] ?? ''), 'mysql'); }
    private function sqlite(): bool { return str_contains((string)($this->db->getParams()['driver'] ?? ''), 'sqlite'); }
    private function expiry(int $minutes): string {
        if ($this->sqlite()) return "datetime('now', '+".$minutes." minutes')";
        return $this->maria() ? 'DATE_ADD(NOW(), INTERVAL '.$minutes.' MINUTE)' : "NOW()+INTERVAL '".$minutes." minutes'";
    }

    private function json(Request $r): ?array
    {
        try { $data = $r->toArray(); return is_array($data) ? $data : null; }
        catch (\Throwable) { return null; }
    }
    private function admin(Request $r): bool
    {
        $value = (string)(getenv('IPTV_ADMIN_TOKEN') ?: '');
        return strlen($value) >= 24 && hash_equals($value, (string)$r->headers->get('X-Admin-Token', ''));
    }
    private function user(Request $r): ?int
    {
        $auth = (string)$r->headers->get('Authorization', '');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $m)) return null;
        $id = $this->db->fetchOne('SELECT user_id FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP', [hash('sha256', $m[1])]);
        return $id === false ? null : (int)$id;
    }
    private function token(): string { return bin2hex(random_bytes(32)); }

    #[Route('/api/identity/bootstrap', methods: ['POST'])]
    public function bootstrap(Request $r): JsonResponse
    {
        if (!$this->limiter->allow($r,'bootstrap','global',5,86400)) return $this->throttled();
        if (!$this->admin($r)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $data=$this->json($r);
        $email=strtolower(trim((string)($data['email']??'')));
        $password=(string)($data['password']??'');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12 || strlen($password)>256) {
            return new JsonResponse(['error'=>'Adresse email invalide ou mot de passe trop court (12 caractères minimum)'],422);
        }
        if ((int)$this->db->fetchOne('SELECT COUNT(*) FROM app_users')>0) {
            return new JsonResponse(['error'=>'Compte initial déjà créé'],409);
        }
        try {
            if ($this->maria()) {
                $this->db->executeStatement('INSERT INTO app_users(email,password_hash) VALUES(?,?)',[$email,password_hash($password,PASSWORD_DEFAULT)]);
                $id=(int)$this->db->lastInsertId();
            } else {
                $id=$this->db->fetchOne('INSERT INTO app_users(email,password_hash) VALUES(?,?) RETURNING id',[$email,password_hash($password,PASSWORD_DEFAULT)]);
            }
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return new JsonResponse(['error'=>'Compte existant'],409);
        }
        return new JsonResponse(['user_id'=>(int)$id,'created'=>true],201);
    }

    #[Route('/api/identity/login', methods: ['POST'])]
    public function login(Request $r): JsonResponse
    {
        $data=$this->json($r);
        $email=strtolower(trim((string)($data['email']??'')));
        $password=(string)($data['password']??'');
        if (!$this->limiter->allow($r,'login-ip','global',20,900)
            || !$this->limiter->allow($r,'login-account',hash('sha256',$email),5,900)) return $this->throttled();
        if (strlen($email)>254 || strlen($password)>256) return new JsonResponse(['error'=>'Identifiants invalides'],401);
        $row=$this->db->fetchAssociative('SELECT id,password_hash FROM app_users WHERE email=?',[$email]);
        if (!$row || !password_verify($password,(string)$row['password_hash'])) {
            return new JsonResponse(['error'=>'Identifiants invalides'],401);
        }
        $token=$this->token();
        $this->db->executeStatement("INSERT INTO app_sessions(user_id,token_hash,expires_at) VALUES(?,?,".$this->expiry(10080).")",[(int)$row['id'],hash('sha256',$token)]);
        return new JsonResponse(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>604800]);
    }

    #[Route('/api/identity/logout', methods: ['POST'])]
    public function logout(Request $r): JsonResponse
    {
        $auth=(string)$r->headers->get('Authorization','');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $this->db->executeStatement('UPDATE app_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_hash=?',[hash('sha256',$m[1])]);
        return new JsonResponse(['ok'=>true]);
    }

    #[Route('/api/devices/pair/start', methods: ['POST'])]
    public function start(Request $r): JsonResponse
    {
        if (!$this->limiter->allow($r,'pair-start','global',10,600)) return $this->throttled();
        $data=$this->json($r)??[];
        $name=trim((string)($data['name']??'Nels TV'));
        if ($name==='' || strlen($name)>80) return new JsonResponse(['error'=>'Nom appareil invalide'],422);
        // A random 6-digit code alone is not sufficient: the pairing ID is also
        // a 256-bit secret, supplied only by the initiating TV for polling.
        $pairingId=$this->token();
        $code=(string)random_int(100000,999999);
        $hash=hash('sha256',$code);
        $this->db->executeStatement("DELETE FROM app_devices WHERE user_id IS NULL AND pairing_expires_at < CURRENT_TIMESTAMP");
        for ($i=0;$i<5;$i++) {
            if ($i>0) { $code=(string)random_int(100000,999999); $hash=hash('sha256',$code); }
            try {
                $this->db->executeStatement("INSERT INTO app_devices(device_name,device_type,device_token_hash,pairing_code_hash,pairing_expires_at) VALUES(?,'tv',?,?,".$this->expiry(5).")",[$name,hash('sha256',$pairingId),$hash]);
                return new JsonResponse(['pairing_id'=>$pairingId,'code'=>$code,'expires_in'=>300],201);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) { /* retry collision */ }
        }
        return new JsonResponse(['error'=>'Réessaie la demande d’association'],503);
    }

    #[Route('/api/devices/pair/approve', methods: ['POST'])]
    public function approve(Request $r): JsonResponse
    {
        $user=$this->user($r);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        if (!$this->limiter->allow($r,'pair-approve',(string)$user,10,900)) return $this->throttled();
        $code=(string)(($this->json($r)??[])['code']??'');
        if (!preg_match('/^\d{6}$/D',$code)) return new JsonResponse(['error'=>'Code invalide'],422);
        $changed=$this->db->executeStatement("UPDATE app_devices SET user_id=?,paired_at=CURRENT_TIMESTAMP,pairing_code_hash=NULL,pairing_expires_at=NULL WHERE pairing_code_hash=? AND user_id IS NULL AND pairing_expires_at > CURRENT_TIMESTAMP",[$user,hash('sha256',$code)]);
        return $changed===1 ? new JsonResponse(['approved'=>true]) : new JsonResponse(['error'=>'Code expiré ou incorrect'],404);
    }

    #[Route('/api/devices/pair/status', methods: ['POST'])]
    public function status(Request $r): JsonResponse
    {
        if (!$this->limiter->allow($r,'pair-status','global',60,60)) return $this->throttled();
        $id=(string)(($this->json($r)??[])['pairing_id']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return new JsonResponse(['error'=>'Invalid request'],422);
        $row=$this->db->fetchAssociative('SELECT id,user_id,pairing_expires_at,device_name,paired_at FROM app_devices WHERE device_token_hash=?',[hash('sha256',$id)]);
        if (!$row) return new JsonResponse(['error'=>'Association inconnue'],404);
        if ($row['user_id']===null) {
            if (strtotime((string)$row['pairing_expires_at'])<time()) return new JsonResponse(['status'=>'expired'],410);
            return new JsonResponse(['status'=>'pending']);
        }
        // First successful claim rotates the pre-pair secret to a fresh device
        // bearer token. Compare-and-swap prevents multiple claims.
        $token=$this->token();
        $count=$this->db->executeStatement('UPDATE app_devices SET device_token_hash=?,last_seen_at=CURRENT_TIMESTAMP WHERE id=? AND device_token_hash=?',[
            hash('sha256',$token),(int)$row['id'],hash('sha256',$id)
        ]);
        if ($count!==1) return new JsonResponse(['error'=>'Association déjà récupérée'],409);
        return new JsonResponse(['status'=>'paired','device_token'=>$token]);
    }

    #[Route('/api/devices', methods: ['GET'])]
    public function devices(Request $r): JsonResponse
    {
        $user=$this->user($r);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        return new JsonResponse(['items'=>$this->db->fetchAllAssociative('SELECT id,device_name,device_type,paired_at,last_seen_at FROM app_devices WHERE user_id=? ORDER BY id DESC',[$user])]);
    }

    #[Route('/api/devices/{id<\d+>}', methods: ['DELETE'])]
    public function revoke(Request $r,int $id): JsonResponse
    {
        $user=$this->user($r);
        if ($user===null) return new JsonResponse(['error'=>'Unauthorized'],401);
        $changed=$this->db->executeStatement('DELETE FROM app_devices WHERE id=? AND user_id=?',[$id,$user]);
        return $changed ? new JsonResponse(['revoked'=>true]) : new JsonResponse(['error'=>'Appareil introuvable'],404);
    }
}
