<?php

use App\Models\RepairBrand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateRepairBrandsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('repair_brands')) {
            Schema::create('repair_brands', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });

            foreach (RepairBrand::DEFAULTS as $i => $name) {
                DB::table('repair_brands')->insert([
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('repair_requests') && ! Schema::hasColumn('repair_requests', 'brand_id')) {
            Schema::table('repair_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('service_id');
                $table->string('brand')->nullable()->after('brand_id');
                $table->index('brand_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('repair_requests') && Schema::hasColumn('repair_requests', 'brand_id')) {
            Schema::table('repair_requests', function (Blueprint $table) {
                $table->dropIndex(['brand_id']);
                $table->dropColumn(['brand_id', 'brand']);
            });
        }
        Schema::dropIfExists('repair_brands');
    }
}
