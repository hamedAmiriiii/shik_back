<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFormalInvoiceProfileTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('formal_invoice_seller_profiles')) {
            Schema::create('formal_invoice_seller_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id')->unique();
                $table->string('legal_name', 191)->nullable();
                $table->string('brand_name', 191)->nullable();
                $table->string('province', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('address', 500)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('economic_code', 64)->nullable();
                $table->string('national_id', 64)->nullable();
                $table->string('registration_number', 64)->nullable();
                $table->text('fixed_notes')->nullable();
                $table->timestamps();

                $table->foreign('atelier_id')->references('id')->on('ateliers')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('formal_invoice_buyer_profiles')) {
            Schema::create('formal_invoice_buyer_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('atelier_id');
                $table->string('phone', 20);
                $table->string('full_name', 191)->nullable();
                $table->string('province', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('address', 500)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->string('economic_code', 64)->nullable();
                $table->string('national_id', 64)->nullable();
                $table->string('registration_number', 64)->nullable();
                $table->timestamps();

                $table->unique(['atelier_id', 'phone']);
                $table->foreign('atelier_id')->references('id')->on('ateliers')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('formal_invoice_buyer_profiles');
        Schema::dropIfExists('formal_invoice_seller_profiles');
    }
}
