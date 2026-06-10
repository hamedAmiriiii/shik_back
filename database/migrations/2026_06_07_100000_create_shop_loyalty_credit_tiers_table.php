<?php

use App\Models\Atelier;
use App\Services\ShopLoyaltyCreditTierService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopLoyaltyCreditTiersTable extends Migration
{
    public function up()
    {
        Schema::create('shop_loyalty_credit_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atelier_id');
            $table->unsignedTinyInteger('sort_order');
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->decimal('percent', 5, 2);
            $table->timestamps();

            $table->unique(['atelier_id', 'sort_order']);
            $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
        });

        if (Schema::hasTable('ateliers')) {
            Atelier::query()->pluck('id')->each(function ($atelierId) {
                ShopLoyaltyCreditTierService::ensureDefaultsForAtelier((int) $atelierId);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('shop_loyalty_credit_tiers');
    }
}
