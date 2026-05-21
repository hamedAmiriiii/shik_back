<?php

use App\Models\Atelier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ateliers', 'shop_access_ends_at')) {
            return;
        }

        Atelier::query()
            ->whereNull('shop_access_ends_at')
            ->whereNull('shop_access_starts_at')
            ->orderBy('id')
            ->each(function (Atelier $atelier) {
                $starts = $atelier->created_at ?? now();
                $atelier->forceFill(Atelier::trialAccessAttributes($starts))->saveQuietly();
            });
    }

    public function down(): void
    {
        // بدون بازگشت خودکار
    }
};
