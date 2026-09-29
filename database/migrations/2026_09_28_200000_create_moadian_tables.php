<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سامانه مؤدیان — طرح: docs/moadian-integration-design.md
 * همه جدول‌ها جدیدند؛ فقط یک ستون nullable به products اضافه می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('moadian_shop_settings')) {
            Schema::create('moadian_shop_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('environment', 20)->default('sandbox');
                $table->string('connection_mode', 20)->default('self_tsp');
                $table->string('memory_id', 20)->nullable();
                $table->text('private_key')->nullable();
                $table->text('certificate')->nullable();
                $table->string('tsp_provider', 100)->nullable();
                $table->text('tsp_credentials')->nullable();
                $table->boolean('price_includes_vat')->default(true);
                $table->unsignedTinyInteger('default_invoice_type')->default(2);
                $table->boolean('auto_type1_with_buyer')->default(true);
                $table->decimal('default_vat_rate', 5, 2)->default(10);
                $table->string('default_sstid', 13)->nullable();
                $table->string('default_sstt', 200)->nullable();
                $table->string('unit_code_piece', 10)->nullable();
                $table->string('unit_code_kg', 10)->nullable();
                $table->string('unit_code_meter', 10)->nullable();
                $table->unsignedSmallInteger('late_threshold_days')->nullable();
                $table->unsignedBigInteger('start_purchase_id')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->text('paused_reason')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('moadian_stuff_ids')) {
            Schema::create('moadian_stuff_ids', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->index();
                $table->string('sstid', 13);
                $table->string('title', 200);
                $table->decimal('vat_rate', 5, 2)->default(0);
                $table->decimal('other_tax_rate', 5, 2)->default(0);
                $table->string('other_tax_subject', 200)->nullable();
                $table->string('unit_code', 10)->nullable();
                $table->timestamps();
                $table->unique(['atelier_id', 'sstid']);
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'moadian_sstid')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('moadian_sstid', 13)->nullable()->index();
            });
        }

        if (! Schema::hasTable('moadian_serial_counters')) {
            Schema::create('moadian_serial_counters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('memory_id', 20);
                $table->unsignedBigInteger('last_serial')->default(0);
                $table->timestamps();
                $table->unique(['atelier_id', 'memory_id']);
            });
        }

        if (! Schema::hasTable('moadian_documents')) {
            Schema::create('moadian_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->index();
                $table->unsignedBigInteger('purchase_id')->nullable()->index();
                $table->unsignedTinyInteger('subject');
                $table->unsignedTinyInteger('invoice_type');
                $table->unsignedTinyInteger('pattern')->default(1);
                $table->unsignedBigInteger('reference_document_id')->nullable();
                $table->string('reference_taxid', 22)->nullable();
                $table->string('memory_id', 20);
                $table->unsignedBigInteger('serial');
                $table->string('inno', 10);
                $table->string('taxid', 22);
                $table->unsignedBigInteger('indatim');
                $table->unsignedBigInteger('indati2m')->nullable();
                $table->boolean('insr')->default(false);
                $table->longText('payload');
                $table->string('source_fingerprint', 64)->nullable();
                $table->decimal('tprdis', 20, 0)->default(0);
                $table->decimal('tdis', 20, 0)->default(0);
                $table->decimal('tadis', 20, 0)->default(0);
                $table->decimal('tvam', 20, 0)->default(0);
                $table->decimal('todam', 20, 0)->default(0);
                $table->decimal('tbill', 20, 0)->default(0);
                $table->unsignedTinyInteger('setm')->default(1);
                $table->string('status', 20)->default('queued')->index();
                $table->string('uid', 64)->nullable()->index();
                $table->string('reference_number', 64)->nullable()->index();
                $table->json('errors')->nullable();
                $table->json('warnings')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('last_inquiry_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->string('triggered_by', 20)->default('sale');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();
                $table->unique(['atelier_id', 'taxid']);
            });
        }

        if (! Schema::hasTable('moadian_document_items')) {
            Schema::create('moadian_document_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('moadian_document_id')->index();
                $table->string('line_key', 80);
                $table->unsignedBigInteger('purchased_product_id')->nullable();
                $table->string('sstid', 13);
                $table->string('sstt', 400)->nullable();
                $table->string('mu', 10)->nullable();
                $table->decimal('am', 18, 3);
                $table->decimal('fee', 20, 0);
                $table->decimal('prdis', 20, 0);
                $table->decimal('dis', 20, 0)->default(0);
                $table->decimal('adis', 20, 0);
                $table->decimal('vra', 5, 2)->default(0);
                $table->decimal('vam', 20, 0)->default(0);
                $table->decimal('odr', 5, 2)->default(0);
                $table->decimal('odam', 20, 0)->default(0);
                $table->decimal('tsstam', 20, 0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('moadian_sale_links')) {
            Schema::create('moadian_sale_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->index();
                $table->unsignedBigInteger('purchase_id')->unique();
                $table->unsignedBigInteger('head_document_id')->nullable();
                $table->string('fingerprint', 64)->nullable();
                $table->boolean('closed')->default(false);
                $table->timestamp('checked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('moadian_api_logs')) {
            Schema::create('moadian_api_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->nullable()->index();
                $table->string('action', 50);
                $table->string('method', 10);
                $table->string('url', 500);
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('document_ids')->nullable();
                $table->longText('request')->nullable();
                $table->longText('response')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('moadian_api_logs');
        Schema::dropIfExists('moadian_sale_links');
        Schema::dropIfExists('moadian_document_items');
        Schema::dropIfExists('moadian_documents');
        Schema::dropIfExists('moadian_serial_counters');
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'moadian_sstid')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['moadian_sstid']);
                $table->dropColumn('moadian_sstid');
            });
        }
        Schema::dropIfExists('moadian_stuff_ids');
        Schema::dropIfExists('moadian_shop_settings');
    }
};
