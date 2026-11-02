<?php
declare(strict_types=1);
namespace IptvService\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/** Resolve user/device identity; legacy admin access is explicit, not a user identity. */
final class ViewerAccess
{
    public function __construct(private readonly Connection $db) {}

    public function isAdmin(Request $request): bool
    {
        $admin=(string)(getenv('IPTV_ADMIN_TOKEN') ?: '');
        return strlen($admin)>=24 && hash_equals($admin,(string)$request->headers->get('X-Admin-Token',''));
    }

    public function userId(Request $request): ?int
    {
        $auth=(string)$request->headers->get('Authorization','');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)) return null;
        $hash=hash('sha256',$m[1]);
        $user=$this->db->fetchOne(
            'SELECT user_id FROM app_sessions WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW()',
            [$hash]
        );
        if ($user!==false) return (int)$user;
        $device=$this->db->fetchOne(
            'SELECT user_id FROM app_devices WHERE device_token_hash=? AND user_id IS NOT NULL AND paired_at IS NOT NULL',
            [$hash]
        );
        return $device===false ? null : (int)$device;
    }

    public function authorized(Request $request): bool
    {
        return $this->isAdmin($request) || $this->userId($request)!==null;
    }
}
