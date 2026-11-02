<?php
declare(strict_types=1);
namespace IptvService\Controller;
use Doctrine\DBAL\Connection;
use IptvService\Security\SecretBox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class ImportController {
    public function __construct(private readonly Connection $db,private readonly SecretBox $secrets) {}
    private function allowed(Request $req): bool {
        $token=$_ENV['IPTV_ADMIN_TOKEN'] ?? getenv('IPTV_ADMIN_TOKEN') ?: '';
        return strlen($token)>=24 && hash_equals($token,(string)$req->headers->get('X-Admin-Token',''));
    }
    #[Route('/api/imports/upload',methods:['POST'])]
    public function upload(Request $request):JsonResponse {
        if (!$this->allowed($request))return new JsonResponse(['error'=>'Unauthorized'],401);
        $file=$request->files->get('file');
        $name=trim((string)$request->request->get('name','Playlist locale'));
        if (!$file || !$file->isValid())return new JsonResponse(['error'=>'Fichier manquant ou invalide'],422);
        if ($name==='' || strlen($name)>150)return new JsonResponse(['error'=>'Nom invalide'],422);
        if ($file->getSize()===false || $file->getSize()>350*1024*1024)return new JsonResponse(['error'=>'Fichier trop volumineux (350 MiB max)'],413);
        $handle=fopen($file->getRealPath(),'rb');
        $first=$handle?fread($handle,2048):false;
        if($handle)fclose($handle);
        if(!is_string($first) || (!str_starts_with(ltrim($first),"#EXTM3U") && !str_contains($first,'#EXTINF:')))
            return new JsonResponse(['error'=>'Fichier M3U invalide'],422);
        $dir=dirname(__DIR__,2).'/var/imports';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))return new JsonResponse(['error'=>'Stockage indisponible'],500);
        $filename=bin2hex(random_bytes(20)).'.m3u';
        try {
            $file->move($dir,$filename);
            $path=$dir.'/'.$filename;
            chmod($path,0600);
            $this->db->beginTransaction();
            $this->db->insert('sources',['name'=>$name,'kind'=>'m3u','endpoint'=>$this->secrets->encrypt(['url'=>'file-upload']),'credentials'=>$this->secrets->encrypt([]),'status'=>'pending']);
            $sourceId=(int)$this->db->lastInsertId();
            $this->db->insert('import_jobs',['source_id'=>$sourceId,'method'=>'file','file_path'=>$path]);
            $jobId=(int)$this->db->lastInsertId();
            $this->db->commit();
            return new JsonResponse(['source_id'=>$sourceId,'job_id'=>$jobId,'status'=>'queued'],202);
        } catch(\Throwable $e) {
            if($this->db->isTransactionActive())$this->db->rollBack();
            if(isset($path)&&is_file($path))unlink($path);
            return new JsonResponse(['error'=>'Impossible de préparer l’import'],500);
        }
    }
    #[Route('/api/imports/{id<\d+>}',methods:['GET'])]
    public function status(Request $req,int $id):JsonResponse {
        if(!$this->allowed($req))return new JsonResponse(['error'=>'Unauthorized'],401);
        $row=$this->db->fetchAssociative('SELECT id,source_id,method,status,attempts,created_at,started_at,finished_at FROM import_jobs WHERE id=?',[$id]);
        return $row?new JsonResponse($row):new JsonResponse(['error'=>'Import introuvable'],404);
    }
    #[Route('/api/imports',methods:['GET'])]
    public function list(Request $req):JsonResponse {
        if(!$this->allowed($req))return new JsonResponse(['error'=>'Unauthorized'],401);
        return new JsonResponse(['items'=>$this->db->fetchAllAssociative('SELECT j.id,j.source_id,s.name,j.status,j.method,j.created_at,j.finished_at FROM import_jobs j JOIN sources s ON s.id=j.source_id ORDER BY j.id DESC LIMIT 30')]);
    }
}
