<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDiningModeAndCustomerNoteToPurchases extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'dining_mode')) {
                $table->string('dining_mode', 20)->nullable()->after('table_label');
            }
            if (! Schema::hasColumn('purchases', 'customer_note')) {
                $table->text('customer_note')->nullable()->after('dining_mode');
            }
        });
    }

    public function down()
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'customer_note')) {
                $table->dropColumn('customer_note');
            }
            if (Schema::hasColumn('purchases', 'dining_mode')) {
                $table->dropColumn('dining_mode');
            }
        });
    }
}
