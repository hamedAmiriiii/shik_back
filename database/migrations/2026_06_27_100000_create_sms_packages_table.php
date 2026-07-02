<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateSmsPackagesTable extends Migration
{
    public function up()
    {
        Schema::create('sms_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sms_count');
            $table->unsignedBigInteger('price_rial')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('sms_packages')->insert([
            [
                'name' => 'بسته ۳۰۰۰ پیامکی',
                'sms_count' => 3000,
                'price_rial' => 5000000,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'بسته ۶۰۰۰ پیامکی',
                'sms_count' => 6000,
                'price_rial' => 10000000,
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('sms_packages');
    }
}
