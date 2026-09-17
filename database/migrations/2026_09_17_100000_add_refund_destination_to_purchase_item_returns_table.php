<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRefundDestinationToPurchaseItemReturnsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('purchase_item_returns')) {
            return;
        }

        if (! Schema::hasColumn('purchase_item_returns', 'cash_refunded')) {
            Schema::table('purchase_item_returns', function (Blueprint $table) {
                $table->decimal('cash_refunded', 15, 2)->default(0)->after('credit_earned_reversed');
            });
        }
        if (! Schema::hasColumn('purchase_item_returns', 'card_refunded')) {
            Schema::table('purchase_item_returns', function (Blueprint $table) {
                $table->decimal('card_refunded', 15, 2)->default(0)->after('cash_refunded');
            });
        }
        if (! Schema::hasColumn('purchase_item_returns', 'card_refund_destination')) {
            Schema::table('purchase_item_returns', function (Blueprint $table) {
                $table->string('card_refund_destination', 32)->nullable()->after('card_refunded');
            });
        }
        if (! Schema::hasColumn('purchase_item_returns', 'shop_account_id')) {
            Schema::table('purchase_item_returns', function (Blueprint $table) {
                $table->unsignedBigInteger('shop_account_id')->nullable()->after('card_refund_destination');
            });
        }
    }

    public function down()
    {
        if (! Schema::hasTable('purchase_item_returns')) {
            return;
        }

        Schema::table('purchase_item_returns', function (Blueprint $table) {
            $cols = [];
            foreach (['shop_account_id', 'card_refund_destination', 'card_refunded', 'cash_refunded'] as $col) {
                if (Schema::hasColumn('purchase_item_returns', $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
}
