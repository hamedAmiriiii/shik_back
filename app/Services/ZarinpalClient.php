<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZarinpalClient
{
    public function request(int $amountRial, string $callbackUrl, string $description, array $metadata = []): string
    {
        $payload = [
            'merchant_id' => $this->merchantId(),
            'amount' => $amountRial,
            'callback_url' => $callbackUrl,
            'description' => mb_substr($description, 0, 255),
        ];
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $json = $this->post($this->requestUrl(), $payload);
        $code = (int) ($json['data']['code'] ?? 0);
        $authority = (string) ($json['data']['authority'] ?? '');
        if ($code !== 100 || $authority === '') {
            throw new RuntimeException($this->errorMessage($json, 'درخواست پرداخت زرین‌پال ناموفق بود.'));
        }

        return $authority;
    }

    /**
     * @return array{code: int, ref_id: string|null, card_pan: string|null}
     */
    public function verify(string $authority, int $amountRial): array
    {
        $json = $this->post($this->verifyUrl(), [
            'merchant_id' => $this->merchantId(),
            'amount' => $amountRial,
            'authority' => $authority,
        ]);
        $code = (int) ($json['data']['code'] ?? ($json['errors']['code'] ?? 0));
        if (! in_array($code, [100, 101], true)) {
            throw new RuntimeException($this->errorMessage($json, 'تأیید پرداخت زرین‌پال ناموفق بود.'));
        }

        return [
            'code' => $code,
            'ref_id' => isset($json['data']['ref_id']) ? (string) $json['data']['ref_id'] : null,
            'card_pan' => $json['data']['card_pan'] ?? null,
        ];
    }

    public function startPayUrl(string $authority): string
    {
        $base = $this->sandbox()
            ? 'https://sandbox.zarinpal.com/pg/StartPay/'
            : 'https://www.zarinpal.com/pg/StartPay/';

        return $base.$authority;
    }

    protected function requestUrl(): string
    {
        return $this->sandbox()
            ? 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
            : 'https://api.zarinpal.com/pg/v4/payment/request.json';
    }

    protected function verifyUrl(): string
    {
        return $this->sandbox()
            ? 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json'
            : 'https://api.zarinpal.com/pg/v4/payment/verify.json';
    }

    protected function merchantId(): string
    {
        $id = trim((string) config('zarinpal.merchant_id'));
        if ($id === '') {
            $id = '28c5b546-aa10-482c-b916-463ff0d798dc';
        }

        return $id;
    }

    protected function sandbox(): bool
    {
        return (bool) config('zarinpal.sandbox');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(string $url, array $payload): array
    {
        $response = Http::timeout(25)->acceptJson()->asJson()->post($url, $payload);
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('پاسخ نامعتبر از زرین‌پال.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function errorMessage(array $json, string $fallback): string
    {
        $message = $json['errors']['message'] ?? $json['data']['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
