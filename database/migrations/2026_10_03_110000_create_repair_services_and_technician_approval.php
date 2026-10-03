<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateRepairServicesAndTechnicianApproval extends Migration
{
    private const DEFAULT_SERVICES = ['لوازم خانگی', 'تأسیسات و لوله‌کشی', 'برق ساختمان', 'کولر و پکیج', 'سایر'];

    public function up()
    {
        if (! Schema::hasTable('repair_services')) {
            Schema::create('repair_services', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });

            $names = self::DEFAULT_SERVICES;
            if (Schema::hasTable('repair_settings')) {
                $raw = DB::table('repair_settings')->where('key', 'categories')->value('value');
                $fromSetting = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $raw) ?: [])));
                if ($fromSetting !== []) {
                    $names = $fromSetting;
                }
            }
            foreach ($names as $i => $name) {
                DB::table('repair_services')->insert([
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasTable('repair_technician_services')) {
            Schema::create('repair_technician_services', function (Blueprint $table) {
                $table->unsignedBigInteger('technician_id');
                $table->unsignedBigInteger('service_id');
                $table->primary(['technician_id', 'service_id']);
                $table->index('service_id');
            });
        }

        if (! Schema::hasColumn('repair_users', 'approval_status')) {
            Schema::table('repair_users', function (Blueprint $table) {
                $table->string('approval_status', 20)->default('approved')->after('role');
                $table->string('approval_note', 1000)->nullable()->after('approval_status');
            });
        }

        if (! Schema::hasColumn('repair_requests', 'service_id')) {
            Schema::table('repair_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('service_id')->nullable()->after('technician_id');
                $table->index('service_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('repair_requests', 'service_id')) {
            Schema::table('repair_requests', function (Blueprint $table) {
                $table->dropIndex(['service_id']);
                $table->dropColumn('service_id');
            });
        }
        if (Schema::hasColumn('repair_users', 'approval_status')) {
            Schema::table('repair_users', function (Blueprint $table) {
                $table->dropColumn(['approval_status', 'approval_note']);
            });
        }
        Schema::dropIfExists('repair_technician_services');
        Schema::dropIfExists('repair_services');
    }
}
