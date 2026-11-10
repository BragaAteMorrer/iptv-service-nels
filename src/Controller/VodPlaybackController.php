<?php
declare(strict_types=1);
namespace IptvService\Controller;

use Doctrine\DBAL\Connection;
use IptvService\Security\SecretBox;
use IptvService\Security\TrustedProviderEndpoint;
use IptvService\Security\ViewerAccess;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Development-only bounded range relay for MP4 VOD.
 * Never expose this PHP relay publicly or use it on shared o2switch hosting.
 */
final class VodPlaybackController
{
    private const WINDOW = 2 * 1024 * 1024;

    // Defence in depth: the Apache allowlist is not the security boundary.
    // Never permit PHP media relaying from a public/prod Symfony entrypoint.
    private function localVideoRelayEnabled(): bool
    {
        return (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod') === 'dev')
            && (($_SERVER['IPTV_ENABLE_LOCAL_RELAY'] ?? $_ENV['IPTV_ENABLE_LOCAL_RELAY'] ?? getenv('IPTV_ENABLE_LOCAL_RELAY') ?: '') === '1');
    }
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly TrustedProviderEndpoint $trusted,
        private readonly ViewerAccess $viewers,
    ) {}

    #[Route('/api/v1/vod/items/{id<\d+>}/session', methods:['POST'])]
    public function session(Request $request,int $id): JsonResponse
    {
        if (!$this->localVideoRelayEnabled()) {
            return new JsonResponse(['error'=>'Lecture VOD indisponible sur cet hébergement'],403,['Cache-Control'=>'no-store']);
        }
        if (!$this->viewers->authorized($request)) return new JsonResponse(['error'=>'Unauthorized'],401);
        $item=$this->db->fetchAssociative(
            "SELECT c.source_id,c.kind,c.external_id,c.stream_url,s.kind AS source_kind,s.endpoint,s.credentials
             FROM channels c JOIN sources s ON s.id=c.source_id
             WHERE c.id=? AND c.active=TRUE AND c.kind IN ('movie','episode')",[$id]
        );
        if (!$item) return new JsonResponse(['error'=>'Vidéo introuvable'],404);
        try {
            if ($item['source_kind']==='xtream') {
                if (!preg_match('/^(movie|episode):([0-9]+)$/D',(string)$item['external_id'],$m)
                    || $m[1]!==$item['kind']) return new JsonResponse(['error'=>'Identifiant VOD incompatible'],422);
                $base=rtrim((string)$this->box->decrypt((string)$item['endpoint'])['url'],'/');
                $cred=$this->box->decrypt((string)$item['credentials']);
                $category=$m[1]==='movie'?'movie':'series';
                // Preliminary MP4-only transport: extensions and codec hints need provider metadata.
                $url=$base.'/'.$category.'/'.rawurlencode((string)$cred['username']).'/'
                    .rawurlencode((string)$cred['password']).'/'.$m[2].'.mp4';
            } elseif ($item['source_kind']==='m3u') {
                $url=(string)$this->box->decrypt((string)$item['stream_url'])['url'];
                if (!str_ends_with(strtolower((string)parse_url($url,PHP_URL_PATH)),'.mp4'))
                    return new JsonResponse(['error'=>'Seules les VOD MP4 sont prises en charge par ce lecteur','code'=>'UNSUPPORTED_FORMAT'],415);
            } else return new JsonResponse(['error'=>'Source non prise en charge'],415);
            $this->trusted->requestOptions($url);
            $ticket=rtrim(strtr(base64_encode($this->box->encrypt([
                'url'=>$url,'source'=>(int)$item['source_id'],'expires'=>time()+600,
                'format'=>'mp4'
            ])),'+/','-_'),'=');
            return new JsonResponse([
                'url'=>'/api/v1/vod/range?ticket='.rawurlencode($ticket),
                'format'=>'mp4','expires_in'=>600,'max_chunk_bytes'=>self::WINDOW
            ],200,['Cache-Control'=>'no-store']);
        } catch (\Throwable $e) {
            error_log('VOD session failure: '.get_class($e));
            return new JsonResponse(['error'=>'Source VOD inaccessible ou non autorisée'],422);
        }
    }

    #[Route('/api/v1/vod/range', methods:['GET'])]
    public function range(Request $request): Response
    {
        if (!$this->localVideoRelayEnabled()) {
            return new Response('Lecture indisponible',403,['Cache-Control'=>'no-store']);
        }
        try {
            $ticket=(string)$request->query->get('ticket','');
            if (!preg_match('/^[A-Za-z0-9_-]{20,8192}$/D',$ticket)) throw new \RuntimeException('Ticket invalid');
            $raw=base64_decode(strtr($ticket,'-_','+/'),true);
            if (!is_string($raw)) throw new \RuntimeException('Ticket invalid');
            $data=$this->box->decrypt($raw);
            if (($data['format']??null)!=='mp4' || !is_string($data['url']??null)
                || (int)($data['expires']??0)<time()) throw new \RuntimeException('Ticket expired');
            $range=(string)$request->headers->get('Range','');
            // Browsers may first request bytes=0-. We turn it into a bounded range.
            if ($range==='') $start=0;
            elseif (preg_match('/^bytes=(\d+)-(\d*)$/D',$range,$m)) {
                $start=(int)$m[1];
                if (isset($m[2]) && $m[2]!=='' && (int)$m[2]<$start) return new Response('',416);
            } else return new Response('',416);
            if ($start<0 || $start>PHP_INT_MAX-self::WINDOW) return new Response('',416);
            $end=$start+self::WINDOW-1;
            if (isset($m[2]) && $m[2]!=='') $end=min($end,(int)$m[2]);
            $url=$data['url'];
            $opts=$this->trusted->requestOptions($url);
            $client=HttpClient::create();
            $upstream=$client->request('GET',$url,array_replace($opts,[
                'headers'=>['Range'=>'bytes='.$start.'-'.$end,'Accept-Encoding'=>'identity'],
                'timeout'=>30,'max_redirects'=>0
            ]));
            if ($upstream->getStatusCode()!==206) {
                $code=$upstream->getStatusCode();
                $upstream->cancel();
                return new Response('',($code===416)?416:502,['Cache-Control'=>'no-store']);
            }
            $h=$upstream->getHeaders(false);
            $contentRange=(string)($h['content-range'][0]??'');
            if (!preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/D',$contentRange,$parts)
                || (int)$parts[1]!==$start || (int)$parts[2]>$end
                || (int)$parts[2]<(int)$parts[1] || (int)$parts[3]<=(int)$parts[2]) {
                $upstream->cancel();return new Response('',502);
            }
            $length=(int)$parts[2]-(int)$parts[1]+1;
            $response=new StreamedResponse(static function() use ($client,$upstream,$length):void {
                $sent=0;
                foreach($client->stream($upstream) as $chunk) {
                    if ($chunk->isTimeout()) break;
                    $bytes=$chunk->getContent();
                    if ($bytes==='') continue;
                    $remaining=$length-$sent;
                    if ($remaining<=0) break;
                    $data=substr($bytes,0,$remaining);
                    echo $data;
                    $sent+=strlen($data);
                    if (connection_aborted() || $sent>=$length) break;
                    flush();
                }
                $upstream->cancel();
            },206,[
                'Content-Type'=>'video/mp4','Content-Range'=>$contentRange,
                'Content-Length'=>(string)$length,'Accept-Ranges'=>'bytes',
                'Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer',
                'X-Content-Type-Options'=>'nosniff'
            ]);
            return $response;
        } catch (\Throwable $e) {
            error_log('VOD range failure: '.get_class($e));
            return new Response('Lecture indisponible',403,['Cache-Control'=>'no-store']);
        }
    }
}
