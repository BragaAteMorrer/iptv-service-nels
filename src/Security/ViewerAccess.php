<?php
declare(strict_types=1);
namespace IptvService\Security;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/** Legacy admin access plus signed-in user or previously paired device access. */
final class ViewerAccess
{
    public function __construct(private readonly Connection $db) {}
    public function authorized(Request $request): bool
    {
        $admin=(string)(getenv('IPTV_ADMIN_TOKEN') ?: '');
        if (strlen($admin)>=24 && hash_equals($admin,(string)$request->headers->get('X-Admin-Token',''))) return true;
        $auth=(string)$request->headers->get('Authorization','');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)) return false;
        $hash=hash('sha256',$m[1]);
        $user=$this->db->fetchOne('SELECT 1 FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW()',[$hash]);
        if ($user!==false) return true;
        $device=$this->db->fetchOne('SELECT 1 FROM app_devices WHERE device_token_hash=? AND user_id IS NOT NULL AND paired_at IS NOT NULL',[$hash]);
        return $device!==false;
    }
}
