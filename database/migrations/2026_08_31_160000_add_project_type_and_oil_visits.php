<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddProjectTypeAndOilVisits extends Migration
{
    public function up()
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'project_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('project_type', 16)->default('shop')->after('atelier_id')
                    ->comment('shop=فروشگاه وبینو | oil=تعویض روغن');
                $table->index('project_type');
            });
            DB::table('users')->whereNull('project_type')->update(['project_type' => 'shop']);
        }

        if (Schema::hasTable('ateliers') && ! Schema::hasColumn('ateliers', 'project_type')) {
            Schema::table('ateliers', function (Blueprint $table) {
                $table->string('project_type', 16)->default('shop')->after('code')
                    ->comment('shop=فروشگاه وبینو | oil=تعویض روغن');
                $table->unsignedInteger('oil_interval_km')->default(5000)->after('project_type');
                $table->index('project_type');
            });
            DB::table('ateliers')->whereNull('project_type')->update(['project_type' => 'shop']);
        } elseif (Schema::hasTable('ateliers') && ! Schema::hasColumn('ateliers', 'oil_interval_km')) {
            Schema::table('ateliers', function (Blueprint $table) {
                $table->unsignedInteger('oil_interval_km')->default(5000)->after('project_type');
            });
        }

        if (! Schema::hasTable('oil_visits')) {
            Schema::create('oil_visits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('plate', 32);
                $table->string('plate_display', 64);
                $table->string('phone', 11);
                $table->unsignedInteger('km');
                $table->unsignedInteger('next_km');
                $table->boolean('sms_sent')->default(false);
                $table->string('sms_error')->nullable();
                $table->timestamps();

                $table->index(['atelier_id', 'plate']);
                $table->index(['atelier_id', 'phone']);
                $table->index(['atelier_id', 'created_at']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->cascadeOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('oil_visits');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'project_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['project_type']);
                $table->dropColumn('project_type');
            });
        }

        if (Schema::hasTable('ateliers') && Schema::hasColumn('ateliers', 'oil_interval_km')) {
            Schema::table('ateliers', function (Blueprint $table) {
                $table->dropColumn('oil_interval_km');
            });
        }
        if (Schema::hasTable('ateliers') && Schema::hasColumn('ateliers', 'project_type')) {
            Schema::table('ateliers', function (Blueprint $table) {
                $table->dropIndex(['project_type']);
                $table->dropColumn('project_type');
            });
        }
    }
}
