<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * کد معرف بازاریاب فقط عدد ۴ رقمی است.
 * کد قدیمی 4Z3T6F → 4366 (لندینگ منو).
 */
class MarketerCodesFourDigits extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('marketers')) {
            return;
        }

        $legacyMap = [
            '4Z3T6F' => '4366',
        ];

        foreach ($legacyMap as $old => $new) {
            $owner = DB::table('marketers')->where('code', $old)->first();
            if (! $owner) {
                continue;
            }
            $takenByOther = DB::table('marketers')
                ->where('code', $new)
                ->where('id', '!=', $owner->id)
                ->exists();
            if ($takenByOther) {
                continue;
            }
            DB::table('marketers')->where('id', $owner->id)->update(['code' => $new]);
        }

        $rows = DB::table('marketers')->orderBy('id')->get(['id', 'code']);
        $used = $rows->pluck('code')->filter(fn ($c) => preg_match('/^\d{4}$/', (string) $c))->flip()->all();

        foreach ($rows as $row) {
            $code = (string) $row->code;
            if (preg_match('/^\d{4}$/', $code)) {
                continue;
            }

            $new = null;
            for ($i = 0; $i < 300; $i++) {
                $candidate = (string) random_int(1000, 9999);
                if (! isset($used[$candidate])) {
                    $new = $candidate;
                    break;
                }
            }
            if ($new === null) {
                throw new RuntimeException('امکان تبدیل کد بازاریاب به ۴ رقم وجود ندارد.');
            }

            DB::table('marketers')->where('id', $row->id)->update(['code' => $new]);
            $used[$new] = true;
        }
    }

    public function down()
    {
        // برگشت‌پذیر نیست — کدهای حروفی قبلی بازیابی نمی‌شوند
    }
}
