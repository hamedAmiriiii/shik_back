<?php

namespace App\Services\DesktopLicense;

use RuntimeException;

class DesktopLicenseSigner
{
    public function publicKeyPem(): string
    {
        $inline = config('desktop_license.public_key');
        if (is_string($inline) && str_contains($inline, 'BEGIN')) {
            return $inline;
        }
        $path = config('desktop_license.public_key_path');
        if ($path && is_readable($path)) {
            return file_get_contents($path);
        }
        throw new RuntimeException('Desktop license public key not found.');
    }

    public function privateKeyPem(): string
    {
        $inline = config('desktop_license.private_key');
        if (is_string($inline) && str_contains($inline, 'BEGIN')) {
            return $inline;
        }
        $path = config('desktop_license.private_key_path');
        if ($path && is_readable($path)) {
            return file_get_contents($path);
        }
        throw new RuntimeException(
            'Desktop license private key not found. Set DESKTOP_LICENSE_PRIVATE_KEY or DESKTOP_LICENSE_PRIVATE_KEY_PATH.'
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $body = base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($this->privateKeyPem());
        if ($key === false) {
            throw new RuntimeException('Invalid desktop license private key.');
        }
        $signature = '';
        $ok = openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('Failed to sign desktop license token.');
        }

        return $body . '.' . base64_encode($signature);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sigB64] = $parts;
        $sig = base64_decode($sigB64, true);
        if ($sig === false) {
            return null;
        }
        $key = openssl_pkey_get_public($this->publicKeyPem());
        if ($key === false) {
            return null;
        }
        $ok = openssl_verify($body, $sig, $key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return null;
        }
        $json = base64_decode($body, true);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== 1) {
            return null;
        }

        return $payload;
    }
}
