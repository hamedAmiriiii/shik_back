<?php

namespace App\Services\Moadian;

use RuntimeException;

/**
 * امضا (JWS RS256) و رمزنگاری (JWE RSA-OAEP-256 + A256GCM) طبق API نسخه ۲ سامانه مؤدیان.
 * OpenSSL در PHP فقط OAEP با SHA-1 دارد؛ پدینگ OAEP-SHA256 دستی ساخته می‌شود.
 */
class MoadianCrypto
{
    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function json($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON encode failed: '.json_last_error_msg());
        }

        return $json;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function normalizePrivateKeyPem(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new RuntimeException('کلید خصوصی خالی است.');
        }
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key);

        return "-----BEGIN PRIVATE KEY-----\n".chunk_split($body, 64, "\n").'-----END PRIVATE KEY-----';
    }

    public static function normalizePublicKeyPem(string $key): string
    {
        $key = trim($key);
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($body, 64, "\n").'-----END PUBLIC KEY-----';
    }

    /** بدنهٔ base64 گواهی (DER) برای x5c */
    public static function certificateBase64(string $certificate): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $certificate);

        return preg_replace('/\s+/', '', (string) $body);
    }

    public static function signatureHeader(string $certificateBase64): array
    {
        return [
            'alg' => 'RS256',
            'typ' => 'jose',
            'x5c' => [$certificateBase64],
            'sigT' => gmdate('Y-m-d\TH:i:s\Z'),
            'crit' => ['sigT'],
            'cty' => 'text/plain',
        ];
    }

    public static function jws(array $payload, string $privateKeyPem, string $certificateBase64): string
    {
        $header = self::signatureHeader($certificateBase64);
        $input = self::base64url(self::json($header)).'.'.self::base64url(self::json($payload));

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new MoadianConfigException('کلید خصوصی معتبر نیست.');
        }
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('امضای JWS ناموفق بود: '.(string) openssl_error_string());
        }

        return $input.'.'.self::base64url($signature);
    }

    public static function jwe(string $plaintext, string $serverPublicKey, string $keyId): string
    {
        $header = ['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'kid' => $keyId];
        $encodedHeader = self::base64url(self::json($header));

        $cek = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag, $encodedHeader, 16);
        if ($cipher === false) {
            throw new RuntimeException('رمزنگاری AES-GCM ناموفق بود.');
        }

        $encryptedKey = self::rsaOaepSha256Encrypt($cek, self::normalizePublicKeyPem($serverPublicKey));

        return $encodedHeader.'.'
            .self::base64url($encryptedKey).'.'
            .self::base64url($iv).'.'
            .self::base64url($cipher).'.'
            .self::base64url($tag);
    }

    public static function rsaOaepSha256Encrypt(string $message, string $publicKeyPem): string
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new RuntimeException('کلید عمومی سامانه قابل خواندن نیست.');
        }
        $details = openssl_pkey_get_details($key);
        $k = (int) ceil(((int) ($details['bits'] ?? 0)) / 8);
        $hLen = 32;
        $mLen = strlen($message);
        if ($k < 2 * $hLen + 2 || $mLen > $k - 2 * $hLen - 2) {
            throw new RuntimeException('طول کلید RSA برای OAEP کافی نیست.');
        }

        $lHash = hash('sha256', '', true);
        $ps = str_repeat("\x00", $k - $mLen - 2 * $hLen - 2);
        $db = $lHash.$ps."\x01".$message;
        $seed = random_bytes($hLen);
        $maskedDb = $db ^ self::mgf1($seed, $k - $hLen - 1);
        $maskedSeed = $seed ^ self::mgf1($maskedDb, $hLen);
        $encoded = "\x00".$maskedSeed.$maskedDb;

        if (! openssl_public_encrypt($encoded, $encrypted, $key, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('رمزنگاری RSA ناموفق بود: '.(string) openssl_error_string());
        }

        return $encrypted;
    }

    private static function mgf1(string $seed, int $length): string
    {
        $out = '';
        for ($counter = 0; strlen($out) < $length; $counter++) {
            $out .= hash('sha256', $seed.pack('N', $counter), true);
        }

        return substr($out, 0, $length);
    }

    /**
     * @return array{private_key: string, public_key: string, csr: string}
     */
    public static function generateKeyAndCsr(array $subject): array
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new RuntimeException('ساخت کلید ناموفق بود: '.(string) openssl_error_string());
        }
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);

        $dn = array_filter([
            'commonName' => $subject['common_name'] ?? null,
            'serialNumber' => $subject['serial_number'] ?? null,
            'organizationName' => $subject['organization'] ?? 'Non-Governmental',
            'organizationalUnitName' => $subject['organizational_unit'] ?? ($subject['common_name'] ?? null),
            'countryName' => 'IR',
        ], fn ($v) => $v !== null && $v !== '');

        $csr = openssl_csr_new($dn, $res, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new RuntimeException('ساخت CSR ناموفق بود: '.(string) openssl_error_string());
        }
        openssl_csr_export($csr, $csrPem);

        return [
            'private_key' => $privatePem,
            'public_key' => (string) ($details['key'] ?? ''),
            'csr' => $csrPem,
        ];
    }

    public static function publicKeyFromPrivate(string $privateKeyPem): ?string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);

        return isset($details['key']) ? (string) $details['key'] : null;
    }
}
