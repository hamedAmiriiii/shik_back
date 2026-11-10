<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddShopNumberToInvoices extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        if (! Schema::hasColumn('invoices', 'number')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedInteger('number')->nullable()->after('id');
            });
        }

        $groups = DB::table('invoices')->select('atelier_id')->distinct()->pluck('atelier_id');
        foreach ($groups as $atelierId) {
            $query = DB::table('invoices')->orderBy('id');
            if ($atelierId === null) {
                $query->whereNull('atelier_id');
            } else {
                $query->where('atelier_id', $atelierId);
            }

            $number = 1;
            foreach ($query->pluck('id') as $id) {
                DB::table('invoices')->where('id', $id)->update(['number' => $number]);
                $number++;
            }
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['atelier_id', 'number'], 'invoices_atelier_number_unique');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'number')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_atelier_number_unique');
            $table->dropColumn('number');
        });
    }
}
