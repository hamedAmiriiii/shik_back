<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Support\Responsable;

class InsufficientShopSmsQuotaException extends Exception implements Responsable
{
    /** @var int */
    public $required;

    /** @var int */
    public $available;

    /** @var int */
    public $charsPerSms;

    public function __construct($required, $available, $charsPerSms = 70)
    {
        $this->required = (int) $required;
        $this->available = (int) $available;
        $this->charsPerSms = (int) $charsPerSms;

        parent::__construct('اعتبار پیامک کافی نیست.');
    }

    public static function sideEffectNotice(): string
    {
        return 'عملیات انجام شد ولی شارژ پیامک تمام شده و پیامک ارسال نشد.';
    }

    /**
     * @return array{sms_sent: bool, sms_quota_exhausted: bool, sms_error: ?string}
     */
    public static function sideEffectFields(bool $exhausted, bool $attempted = true): array
    {
        if (! $attempted) {
            return [
                'sms_sent' => false,
                'sms_quota_exhausted' => false,
                'sms_error' => null,
            ];
        }

        if ($exhausted) {
            return [
                'sms_sent' => false,
                'sms_quota_exhausted' => true,
                'sms_error' => self::sideEffectNotice(),
            ];
        }

        return [
            'sms_sent' => true,
            'sms_quota_exhausted' => false,
            'sms_error' => null,
        ];
    }

    public function toResponse($request)
    {
        return response()->json([
            'message' => 'اعتبار پیامک کافی نیست. برای شارژ با پشتیبانی تماس بگیرید.',
            'required_sms_parts' => $this->required,
            'available_sms_parts' => $this->available,
            'chars_per_sms' => $this->charsPerSms,
        ], 422);
    }
}
