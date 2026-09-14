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
