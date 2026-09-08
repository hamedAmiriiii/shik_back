<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ایندکس UNIQUE فقط روی settings.key مانع تنظیمات جدا برای هر فروشگاه است
 * (Duplicate entry 'credit_expiry_days' for key 'key').
 */
class DropLegacyUniqueOnSettingsKey extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $this->dropUniqueIndexesOnKeyOnly();

        if (! Schema::hasColumn('settings', 'atelier_id')) {
            return;
        }

        $hasComposite = collect(DB::select('SHOW INDEX FROM `settings`'))
            ->contains(function ($row) {
                return ($row->Key_name ?? '') === 'settings_atelier_id_key_unique';
            });

        if (! $hasComposite) {
            DB::statement('ALTER TABLE `settings` ADD UNIQUE `settings_atelier_id_key_unique` (`atelier_id`, `key`)');
        }
    }

    public function down()
    {
        //
    }

    private function dropUniqueIndexesOnKeyOnly(): void
    {
        $grouped = collect(DB::select('SHOW INDEX FROM `settings`'))->groupBy('Key_name');

        foreach ($grouped as $indexName => $rows) {
            if ($indexName === 'PRIMARY') {
                continue;
            }
            $first = $rows->first();
            if ((int) ($first->Non_unique ?? 1) === 1) {
                continue;
            }
            $cols = collect($rows)->sortBy('Seq_in_index')->pluck('Column_name')->values()->all();
            if ($cols === ['key']) {
                DB::statement('ALTER TABLE `settings` DROP INDEX `'.str_replace('`', '', (string) $indexName).'`');
            }
        }
    }
}
