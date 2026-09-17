<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ateliers') && ! Schema::hasColumn('ateliers', 'unlimited_products')) {
            Schema::table('ateliers', function (Blueprint $table) {
                $table->boolean('unlimited_products')->default(false)->after('subscription_renewal_days');
            });
        }

        if (Schema::hasTable('shop_plans') && ! Schema::hasColumn('shop_plans', 'unlimited_products')) {
            Schema::table('shop_plans', function (Blueprint $table) {
                $table->boolean('unlimited_products')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasTable('shop_plans')) {
            return;
        }

        $existsQuery = DB::table('shop_plans')->where('name', 'اشتراک طلایی');
        if (Schema::hasColumn('shop_plans', 'project_type')) {
            $existsQuery->where('project_type', 'shop');
        }
        $exists = $existsQuery->exists();

        if (! $exists) {
            $row = [
                'name' => 'اشتراک طلایی',
                'duration_days' => 365,
                'price_rial' => 80000000, // 8 میلیون تومان
                'is_active' => true,
                'sort_order' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('shop_plans', 'project_type')) {
                $row['project_type'] = 'shop';
            }
            if (Schema::hasColumn('shop_plans', 'description')) {
                $row['description'] = 'بدون سقف تعداد کالا — مناسب فروشگاه‌های با بیش از ۱۰۰۰ محصول';
            }
            if (Schema::hasColumn('shop_plans', 'unlimited_products')) {
                $row['unlimited_products'] = true;
            }
            DB::table('shop_plans')->insert($row);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shop_plans') && Schema::hasColumn('shop_plans', 'unlimited_products')) {
            Schema::table('shop_plans', function (Blueprint $table) {
                $table->dropColumn('unlimited_products');
            });
        }
        if (Schema::hasTable('ateliers') && Schema::hasColumn('ateliers', 'unlimited_products')) {
            Schema::table('ateliers', function (Blueprint $table) {
                $table->dropColumn('unlimited_products');
            });
        }
    }
};
