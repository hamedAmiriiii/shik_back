<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddShopMultitenancyColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ateliers', function (Blueprint $table) {
            $table->timestamp('shop_access_starts_at')->nullable();
            $table->timestamp('shop_access_ends_at')->nullable();
            $table->boolean('shop_access_suspended')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('shop_staff_role', 32)->nullable()->after('atelier_id');
        });

        Schema::table('confirmation_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('atelier_id')->nullable()->after('id');
            $table->index(['atelier_id', 'phone']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('atelier_id')->nullable()->after('id');
        });

        $defaultAtelierId = DB::table('ateliers')->orderBy('id')->value('id');

        if ($defaultAtelierId) {
            DB::table('customers')->whereNull('atelier_id')->update(['atelier_id' => $defaultAtelierId]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->unique(['atelier_id', 'phone'], 'customers_atelier_id_phone_unique');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        $tables = [
            'products',
            'categories',
            'manufacturers',
            'purchases',
            'carts',
            'invoices',
            'expenses',
            'returned_products',
            'shop_sms_logs',
        ];

        foreach ($tables as $tbl) {
            Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                $table->unsignedBigInteger('atelier_id')->nullable()->after('id');
            });
            if ($defaultAtelierId) {
                DB::table($tbl)->whereNull('atelier_id')->update(['atelier_id' => $defaultAtelierId]);
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['atelier_id', 'barcode'], 'products_atelier_id_barcode_unique');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('manufacturers', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('manufacturers', function (Blueprint $table) {
            $table->unique(['atelier_id', 'name'], 'manufacturers_atelier_id_name_unique');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('returned_products', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('shop_sms_logs', function (Blueprint $table) {
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedBigInteger('atelier_id')->nullable()->after('id');
        });

        if ($defaultAtelierId) {
            DB::table('settings')->whereNull('atelier_id')->update(['atelier_id' => $defaultAtelierId]);
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['atelier_id', 'key'], 'settings_atelier_id_key_unique');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });

        Schema::table('user_shiksho', function (Blueprint $table) {
            $table->unsignedBigInteger('atelier_id')->nullable()->after('id');
        });

        if ($defaultAtelierId) {
            DB::table('user_shiksho')->whereNull('atelier_id')->update(['atelier_id' => $defaultAtelierId]);
        }

        Schema::table('user_shiksho', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->unique(['atelier_id', 'phone'], 'user_shiksho_atelier_id_phone_unique');
            $table->foreign('atelier_id')->references('id')->on('ateliers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_shiksho', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropUnique('user_shiksho_atelier_id_phone_unique');
            $table->unique('phone');
            $table->dropColumn('atelier_id');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropUnique('settings_atelier_id_key_unique');
            $table->unique('key');
            $table->dropColumn('atelier_id');
        });

        Schema::table('shop_sms_logs', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('returned_products', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('manufacturers', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropUnique('manufacturers_atelier_id_name_unique');
            $table->unique('name');
            $table->dropColumn('atelier_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropUnique('products_atelier_id_barcode_unique');
            $table->unique('barcode');
            $table->dropColumn('atelier_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['atelier_id']);
            $table->dropUnique('customers_atelier_id_phone_unique');
            $table->unique('phone');
            $table->dropColumn('atelier_id');
        });

        Schema::table('confirmation_codes', function (Blueprint $table) {
            $table->dropIndex(['atelier_id', 'phone']);
            $table->dropColumn('atelier_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('shop_staff_role');
        });

        Schema::table('ateliers', function (Blueprint $table) {
            $table->dropColumn(['shop_access_starts_at', 'shop_access_ends_at', 'shop_access_suspended']);
        });
    }
}
