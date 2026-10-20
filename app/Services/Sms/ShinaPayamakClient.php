<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class ShinaPayamakClient
{
    protected string $baseUrl;

    protected string $apiToken;

    protected string $gateway;

    public function __construct(?string $apiToken = null, ?string $gateway = null, ?string $baseUrl = null)
    {
        $this->apiToken = $apiToken ?: (string) config('sms.shina.api_token');
        $this->gateway = $gateway ?: (string) config('sms.shina.gateway');
        $this->baseUrl = rtrim($baseUrl ?: (string) config('sms.shina.base_url'), '/');
    }

    public function getBalance(): array
    {
        $response = Http::timeout(20)->get($this->baseUrl.'/'.$this->apiToken.'/account/balance.json');
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function send(string $to, string $text): array
    {
        $response = Http::timeout(20)->get($this->baseUrl.'/'.$this->apiToken.'/sms/send.json', [
            'gateway' => $this->gateway,
            'to' => $to,
            'text' => $text,
        ]);
        $json = $response->json();

        return [
            'json' => is_array($json) ? $json : [],
            'http_ok' => $response->successful(),
        ];
    }

    public function status(?string $batchId = null, ?string $referenceId = null): ?array
    {
        if (($referenceId === null || $referenceId === '') && ($batchId === null || $batchId === '')) {
            return null;
        }

        $query = [];
        if ($referenceId !== null && $referenceId !== '') {
            $query['reference_id'] = $referenceId;
        } else {
            $query['batch_id'] = $batchId;
        }

        $response = Http::timeout(20)->get(
            $this->baseUrl.'/'.$this->apiToken.'/sms/status.json',
            $query
        );
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    public function logSender(): string
    {
        return (string) config('sms.shina.log_sender', $this->gateway);
    }
}
