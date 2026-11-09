<?php
declare(strict_types=1);
namespace IptvService\Security;
final class SecretBox {
    private function key(): string {
        $key=base64_decode($_ENV['IPTV_SECRET_KEY'] ?? getenv('IPTV_SECRET_KEY') ?: '',true);
        if ($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('IPTV_SECRET_KEY must be base64 of 32 bytes');
        return $key;
    }
    public function encrypt(array $data): string {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox(json_encode($data,JSON_THROW_ON_ERROR),$nonce,$this->key()));
    }
    public function decrypt(string $encoded): array {
        $raw=base64_decode($encoded,true);
        if ($raw===false) throw new \RuntimeException('Invalid encrypted data');
        $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$this->key());
        if ($plain===false) throw new \RuntimeException('Unable to decrypt credentials');
        return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
    }
}
