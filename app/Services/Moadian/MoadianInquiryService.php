<?php

namespace App\Services\Moadian;

use App\Models\MoadianDocument;
use App\Models\MoadianShopSetting;

class MoadianInquiryService
{
    public function __construct(private MoadianShopSetting $settings, private MoadianGateway $gateway)
    {
    }

    /**
     * @return array{success:int, failed:int, pending:int}
     */
    public function inquire(int $limit = 50): array
    {
        $stats = ['success' => 0, 'failed' => 0, 'pending' => 0];

        $docs = MoadianDocument::query()
            ->where('atelier_id', $this->settings->atelier_id)
            ->where('status', MoadianDocument::STATUS_SENT)
            ->whereNotNull('reference_number')
            ->where('sent_at', '<=', now()->subSeconds(15))
            ->where(function ($q) {
                $q->whereNull('last_inquiry_at')->orWhere('last_inquiry_at', '<=', now()->subMinute());
            })
            ->orderBy('last_inquiry_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        if ($docs->isEmpty()) {
            return $stats;
        }

        $results = $this->gateway->inquiryByReferenceNumbers(
            $docs->pluck('reference_number')->all(),
            $docs->pluck('id')->all()
        );

        foreach ($docs as $doc) {
            $result = $results[$doc->reference_number] ?? null;
            $update = ['last_inquiry_at' => now()];
            $status = $result['status'] ?? '';

            if ($status === 'SUCCESS') {
                $update += [
                    'status' => MoadianDocument::STATUS_SUCCESS,
                    'finalized_at' => now(),
                    'errors' => null,
                    'warnings' => ($result['warnings'] ?? []) ?: $doc->warnings,
                ];
                $stats['success']++;
            } elseif ($status === 'FAILED') {
                $update += [
                    'status' => MoadianDocument::STATUS_FAILED,
                    'errors' => ($result['errors'] ?? []) ?: [['code' => 'failed', 'message' => 'سامانه صورتحساب را رد کرد.']],
                    'warnings' => ($result['warnings'] ?? []) ?: $doc->warnings,
                ];
                $stats['failed']++;
            } elseif ($status === 'NOT_FOUND' && $doc->sent_at && $doc->sent_at->lt(now()->subDays(2))) {
                $update += [
                    'status' => MoadianDocument::STATUS_FAILED,
                    'errors' => [['code' => 'not_found', 'message' => 'صورتحساب در سامانه پیدا نشد؛ دوباره ارسال کنید.']],
                ];
                $stats['failed']++;
            } else {
                $stats['pending']++;
            }

            $doc->forceFill($update)->save();
        }

        return $stats;
    }
}
