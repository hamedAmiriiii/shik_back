<?php

use App\Models\Atelier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

class ActivateOneWeekTrialForAllAteliers extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('ateliers', 'shop_access_ends_at')) {
            return;
        }

        $now = now();
        $weekEnd = $now->copy()->addDays(Atelier::TRIAL_DAYS);

        Atelier::query()
            ->orderBy('id')
            ->each(function (Atelier $atelier) use ($now, $weekEnd) {
                $starts = $atelier->shop_access_starts_at;
                if ($starts === null || $starts->gt($now)) {
                    $starts = $now;
                }

                $ends = $atelier->shop_access_ends_at;
                if ($ends === null || $ends->lt($weekEnd)) {
                    $ends = $weekEnd;
                }

                $atelier->forceFill([
                    'shop_access_starts_at' => $starts,
                    'shop_access_ends_at' => $ends,
                    'shop_access_suspended' => false,
                ])->saveQuietly();
            });
    }

    public function down()
    {
        // بدون بازگشت خودکار
    }
}
