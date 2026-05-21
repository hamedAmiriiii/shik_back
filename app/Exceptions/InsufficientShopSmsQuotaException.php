<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Support\Responsable;

class InsufficientShopSmsQuotaException extends Exception implements Responsable
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
        public readonly int $charsPerSms = 70
    ) {
        parent::__construct('اعتبار پیامک کافی نیست.');
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
