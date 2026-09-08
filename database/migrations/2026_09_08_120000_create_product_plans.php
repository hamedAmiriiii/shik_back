<?php

use App\Models\ProductPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductPlans extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('product_plans')) {
            Schema::create('product_plans', function (Blueprint $table) {
                $table->id();
                $table->string('product_slug', 32)->index();
                $table->string('name');
                $table->unsignedInteger('max_users')->default(0);
                $table->unsignedInteger('max_videos')->default(4);
                $table->unsignedInteger('duration_days');
                $table->string('duration_label', 32)->nullable();
                $table->unsignedBigInteger('price_rial');
                $table->json('features')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['product_slug', 'max_users', 'duration_days'], 'product_plans_unique_tier');
            });
        }

        foreach (ProductPlan::yadinoCatalog() as $row) {
            $plan = ProductPlan::query()->firstOrNew([
                'product_slug' => $row['product_slug'],
                'max_users' => $row['max_users'],
                'duration_days' => $row['duration_days'],
            ]);
            $plan->fill($row);
            $plan->save();
        }
    }

    public function down()
    {
        Schema::dropIfExists('product_plans');
    }
}
