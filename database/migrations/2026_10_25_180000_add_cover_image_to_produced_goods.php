<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عکس کلی کالای تولیدی (بدون لایه‌های مواد) برای لیست/سبد؛
 * اگر نباشد از لایه‌های مواد استفاده می‌شود.
 */
class AddCoverImageToProducedGoods extends Migration
{
    public function up()
    {
        if (Schema::hasTable('produced_goods') && ! Schema::hasColumn('produced_goods', 'cover_image_path')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->string('cover_image_path')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('produced_goods', 'cover_image_path')) {
            Schema::table('produced_goods', function (Blueprint $table) {
                $table->dropColumn('cover_image_path');
            });
        }
    }
}
