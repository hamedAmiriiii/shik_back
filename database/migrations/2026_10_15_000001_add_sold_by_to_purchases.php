<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'sold_by_user_id')) {
                $table->unsignedBigInteger('sold_by_user_id')->nullable()->after('atelier_id');
            }
            if (! Schema::hasColumn('purchases', 'sold_by_name')) {
                $table->string('sold_by_name', 120)->nullable()->after('sold_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'sold_by_name')) {
                $table->dropColumn('sold_by_name');
            }
            if (Schema::hasColumn('purchases', 'sold_by_user_id')) {
                $table->dropColumn('sold_by_user_id');
            }
        });
    }
};
