<?php

namespace App\Services\Moadian;

use App\Models\MoadianDocument;

/**
 * اعتبارسنجی محلی پیش از ارسال؛ خطاهای قطعی را زودتر و به فارسی نشان می‌دهد.
 */
class MoadianValidator
{
    /**
     * @return array<int, array{code:string, message:string}>
     */
    public static function validate(array $payload): array
    {
        $errors = [];
        $header = $payload['header'] ?? [];
        $body = $payload['body'] ?? [];
        $subject = (int) ($header['ins'] ?? 0);

        if (! preg_match('/^[0-9A-F]{22}$/', (string) ($header['taxid'] ?? ''))) {
            $errors[] = self::err('taxid', 'شماره منحصربه‌فرد مالیاتی نامعتبر است.');
        }
        $tins = (string) ($header['tins'] ?? '');
        if (! preg_match('/^\d{10,14}$/', $tins)) {
            $errors[] = self::err('tins', 'کد اقتصادی/شناسه ملی فروشنده نامعتبر است.');
        }
        if ($subject !== MoadianDocument::SUBJECT_ORIGINAL && empty($header['irtaxid'])) {
            $errors[] = self::err('irtaxid', 'شماره صورتحساب مرجع برای اصلاحی/ابطالی/برگشتی مشخص نیست.');
        }
        if ((int) ($header['inty'] ?? 0) === 1 && empty($header['tinb'])) {
            $errors[] = self::err('tinb', 'صورتحساب نوع اول بدون مشخصات خریدار قابل ارسال نیست.');
        }

        if ($subject === MoadianDocument::SUBJECT_CANCEL) {
            return $errors;
        }

        if ($body === []) {
            $errors[] = self::err('body', 'صورتحساب هیچ ردیفی ندارد.');
        }
        foreach ($body as $i => $row) {
            $n = $i + 1;
            if (! preg_match('/^\d{13}$/', (string) ($row['sstid'] ?? ''))) {
                $errors[] = self::err('sstid', "ردیف {$n} («".($row['sstt'] ?? '')."»): شناسه کالا/خدمت باید ۱۳ رقم باشد.");
            }
            if ((float) ($row['am'] ?? 0) <= 0) {
                $errors[] = self::err('am', "ردیف {$n}: تعداد/مقدار باید بیشتر از صفر باشد.");
            }
            if ((int) ($row['fee'] ?? 0) <= 0) {
                $errors[] = self::err('fee', "ردیف {$n}: مبلغ واحد باید بیشتر از صفر باشد.");
            }
        }
        if ((int) ($header['tbill'] ?? 0) <= 0) {
            $errors[] = self::err('tbill', 'مبلغ کل صورتحساب صفر است.');
        }

        return $errors;
    }

    private static function err(string $code, string $message): array
    {
        return ['code' => 'local.'.$code, 'message' => $message];
    }
}
