<?php

namespace App\Services\Moadian;

use App\Models\MoadianDocument;
use App\Models\MoadianShopSetting;

class MoadianSender
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(private MoadianShopSetting $settings, private MoadianGateway $gateway)
    {
    }

    /**
     * @return array{sent:int, failed:int, deferred:int}
     */
    public function send(): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'deferred' => 0];
        $batchSize = max(1, min(100, (int) config('services.moadian.batch_size', 20)));

        $candidates = MoadianDocument::query()
            ->where('atelier_id', $this->settings->atelier_id)
            ->where('status', MoadianDocument::STATUS_QUEUED)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($batchSize * 3)
            ->get();

        $batch = [];
        foreach ($candidates as $doc) {
            if ($doc->reference_document_id) {
                $refStatus = MoadianDocument::query()->whereKey($doc->reference_document_id)->value('status');
                if ($refStatus !== MoadianDocument::STATUS_SUCCESS) {
                    continue;
                }
            }
            $errors = MoadianValidator::validate($doc->payloadArray());
            if ($errors) {
                $doc->forceFill(['status' => MoadianDocument::STATUS_FAILED, 'errors' => $errors])->save();
                $stats['failed']++;

                continue;
            }
            $batch[MoadianCrypto::uuid()] = $doc;
            if (count($batch) >= $batchSize) {
                break;
            }
        }
        if ($batch === []) {
            return $stats;
        }

        $invoices = [];
        foreach ($batch as $traceId => $doc) {
            $invoices[] = ['requestTraceId' => $traceId, 'payload' => $doc->payloadArray()];
        }
        $docIds = array_map(fn (MoadianDocument $d) => $d->id, array_values($batch));

        try {
            $results = $this->gateway->sendInvoices($invoices, $docIds);
        } catch (MoadianConfigException $e) {
            throw $e;
        } catch (\Throwable $e) {
            foreach ($batch as $doc) {
                $this->defer($doc, $e->getMessage());
                $stats['deferred']++;
            }

            return $stats;
        }

        foreach ($batch as $traceId => $doc) {
            $result = $results[$traceId] ?? null;
            if ($result && ! empty($result['reference_number'])) {
                $doc->forceFill([
                    'status' => MoadianDocument::STATUS_SENT,
                    'uid' => $traceId,
                    'reference_number' => $result['reference_number'],
                    'sent_at' => now(),
                    'attempts' => $doc->attempts + 1,
                    'next_attempt_at' => null,
                    'errors' => null,
                ])->save();
                $stats['sent']++;
            } elseif ($result && ! empty($result['error'])) {
                $doc->forceFill([
                    'status' => MoadianDocument::STATUS_FAILED,
                    'attempts' => $doc->attempts + 1,
                    'errors' => [['code' => 'send', 'message' => $result['error']]],
                ])->save();
                $stats['failed']++;
            } else {
                $this->defer($doc, 'پاسخ سامانه برای این صورتحساب دریافت نشد.');
                $stats['deferred']++;
            }
        }

        return $stats;
    }

    private function defer(MoadianDocument $doc, string $message): void
    {
        $attempts = $doc->attempts + 1;
        $error = [['code' => 'network', 'message' => mb_substr($message, 0, 500)]];
        if ($attempts >= self::MAX_ATTEMPTS) {
            $doc->forceFill(['status' => MoadianDocument::STATUS_FAILED, 'attempts' => $attempts, 'errors' => $error])->save();

            return;
        }
        $minutes = min(360, 2 ** $attempts);
        $doc->forceFill([
            'attempts' => $attempts,
            'next_attempt_at' => now()->addMinutes($minutes),
            'errors' => $error,
        ])->save();
    }
}
