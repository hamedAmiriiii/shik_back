<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('client_id', 64)->nullable()->after('atelier_id');
            $table->unique(['atelier_id', 'client_id'], 'purchases_atelier_client_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_atelier_client_id_unique');
            $table->dropColumn('client_id');
        });
    }
};
