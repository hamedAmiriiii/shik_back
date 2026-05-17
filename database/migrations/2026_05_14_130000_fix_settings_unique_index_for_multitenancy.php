<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * اگر مایگریشن چندفروشگاهی روی settings کامل اجرا نشده باشد، UNIQUE فقط روی `key`
 * باعث خطای Duplicate entry هنگام ensureDefaultsForAtelier برای فروشگاه دوم می‌شود.
 */
class FixSettingsUniqueIndexForMultitenancy extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! Schema::hasColumn('settings', 'atelier_id')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->unsignedBigInteger('atelier_id')->nullable()->after('id');
            });
        }

        $defaultAtelierId = DB::table('ateliers')->orderBy('id')->value('id');
        if ($defaultAtelierId) {
            DB::table('settings')->whereNull('atelier_id')->update(['atelier_id' => $defaultAtelierId]);
        }

        $alreadyComposite = collect(DB::select('SHOW INDEX FROM `settings`'))
            ->pluck('Key_name')
            ->contains('settings_atelier_id_key_unique');

        if ($alreadyComposite) {
            $this->ensureSettingsForeignKey();

            return;
        }

        $grouped = collect(DB::select('SHOW INDEX FROM `settings`'))->groupBy('Key_name');
        foreach ($grouped as $indexName => $rows) {
            if ($indexName === 'PRIMARY') {
                continue;
            }
            $cols = collect($rows)->sortBy('Seq_in_index')->pluck('Column_name')->values()->all();
            if ($cols === ['key']) {
                Schema::table('settings', function (Blueprint $table) use ($indexName) {
                    $table->dropUnique($indexName);
                });

                break;
            }
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->unique(['atelier_id', 'key'], 'settings_atelier_id_key_unique');
        });

        $this->ensureSettingsForeignKey();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }

    private function ensureSettingsForeignKey(): void
    {
        if (! Schema::hasTable('ateliers') || ! Schema::hasColumn('settings', 'atelier_id')) {
            return;
        }

        $exists = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            ['settings', 'settings_atelier_id_foreign', 'FOREIGN KEY']
        );

        if ($exists) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });
    }
}
