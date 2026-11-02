<?php
declare(strict_types=1);
namespace IptvService\Security;
final class SecretBox {
    private function key(): string {
        $key = base64_decode($_ENV['IPTV_SECRET_KEY'] ?? getenv('IPTV_SECRET_KEY') ?: '', true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('IPTV_SECRET_KEY must be a base64-encoded 32-byte key');
        }
        return $key;
    }
    public function encrypt(array $data): string {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce.sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR),$nonce,$this->key());
    }
    public function decrypt(string $encoded): array {
        $nonce=substr($encoded,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $decoded=sodium_crypto_secretbox_open(substr($encoded,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$this->key());
        if ($decoded === false) throw new \RuntimeException('Unable to decrypt credentials');
        return json_decode($decoded,true,512,JSON_THROW_ON_ERROR);
    }
}
