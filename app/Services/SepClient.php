<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SepClient
{
    /**
     * دریافت توکن پرداخت از سامان کیش.
     *
     * @return array{token: string, res_num: string}
     */
    public function requestToken(int $amountRial, string $resNum, string $redirectUrl, ?string $cellNumber = null): array
    {
        $payload = [
            'action' => 'token',
            'TerminalId' => $this->terminalId(),
            'Amount' => $amountRial,
            'ResNum' => $resNum,
            'RedirectUrl' => $redirectUrl,
        ];
        if (is_string($cellNumber) && $cellNumber !== '') {
            $payload['CellNumber'] = preg_replace('/\D+/', '', $cellNumber) ?: $cellNumber;
        }

        $json = $this->postJson($this->tokenUrl(), $payload);
        $status = (int) ($json['status'] ?? $json['Status'] ?? -1);
        $token = (string) ($json['token'] ?? $json['Token'] ?? '');
        if ($status !== 1 || $token === '') {
            throw new RuntimeException($this->errorMessage($json, 'دریافت توکن درگاه سامان ناموفق بود.'));
        }

        return [
            'token' => $token,
            'res_num' => $resNum,
        ];
    }

    /**
     * تأیید تراکنش پس از بازگشت از درگاه.
     *
     * @return array{ok: bool, ref_id: string|null, card_pan: string|null, trace_no: string|null, amount: int|null}
     */
    public function verify(string $refNum): array
    {
        $json = $this->postJson($this->verifyUrl(), [
            'RefNum' => $refNum,
            'TerminalNumber' => (int) $this->terminalId(),
        ]);

        $success = (bool) ($json['Success'] ?? $json['success'] ?? false);
        $resultCode = (string) ($json['ResultCode'] ?? $json['resultCode'] ?? '');
        $transactionDetail = is_array($json['TransactionDetail'] ?? null)
            ? $json['TransactionDetail']
            : (is_array($json['transactionDetail'] ?? null) ? $json['transactionDetail'] : []);

        // ResultCode 0 یا Success=true یعنی تأیید موفق (تکرار تأیید هم معمولاً 2 است ولی Success می‌تواند true باشد)
        $ok = $success || $resultCode === '0' || $resultCode === '00';
        if (! $ok) {
            $msg = (string) ($json['ResultDescription'] ?? $json['resultDescription'] ?? '');
            throw new RuntimeException($msg !== '' ? $msg : 'تأیید پرداخت سامان ناموفق بود.');
        }

        $amount = $transactionDetail['OrginalAmount']
            ?? $transactionDetail['OriginalAmount']
            ?? $transactionDetail['AffectiveAmount']
            ?? $json['Amount']
            ?? null;

        return [
            'ok' => true,
            'ref_id' => isset($transactionDetail['StraceNo'])
                ? (string) $transactionDetail['StraceNo']
                : (isset($json['TraceNo']) ? (string) $json['TraceNo'] : $refNum),
            'card_pan' => isset($transactionDetail['MaskedPan'])
                ? (string) $transactionDetail['MaskedPan']
                : (isset($json['SecurePan']) ? (string) $json['SecurePan'] : null),
            'trace_no' => isset($transactionDetail['StraceNo'])
                ? (string) $transactionDetail['StraceNo']
                : null,
            'amount' => $amount !== null ? (int) $amount : null,
        ];
    }

    public function payUrl(): string
    {
        return rtrim((string) config('sep.pay_url'), '/');
    }

    public function terminalId(): string
    {
        $id = trim((string) config('sep.terminal_id'));
        if ($id === '') {
            throw new RuntimeException('شماره ترمینال سامان تنظیم نشده است.');
        }

        return $id;
    }

    protected function tokenUrl(): string
    {
        return (string) config('sep.token_url');
    }

    protected function verifyUrl(): string
    {
        return (string) config('sep.verify_url');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $payload): array
    {
        $response = Http::timeout(30)->acceptJson()->asJson()->post($url, $payload);
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('پاسخ نامعتبر از درگاه سامان.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function errorMessage(array $json, string $fallback): string
    {
        foreach (['errorDesc', 'ErrorDesc', 'error', 'message', 'Message', 'description'] as $key) {
            if (! empty($json[$key]) && is_string($json[$key])) {
                return $json[$key];
            }
        }
        $status = $json['status'] ?? $json['Status'] ?? null;
        if ($status !== null) {
            return $fallback.' (کد '.$status.')';
        }

        return $fallback;
    }
}
