<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 20)->unique();       // Nomor telepon asli yang diinput
            $table->string('normalized_number', 20)->unique();  // Format internasional +62xxx
            $table->string('country_code', 5)->default('+62');  // Kode negara
            $table->string('owner_name')->nullable();           // Nama pemilik nomor
            $table->string('operator_name', 50)->nullable();    // Nama operator seluler
            $table->string('category', 50)->nullable();         // Kategori: personal, business, spam
            $table->unsignedTinyInteger('spam_score')->default(0); // Skor spam 0-100
            $table->unsignedInteger('total_reports')->default(0);  // Jumlah total laporan
            $table->boolean('is_verified')->default(false);        // Status verifikasi
            $table->timestamp('last_updated')->nullable();         // Terakhir diperbarui
            $table->timestamps();

            $table->index('category');
            $table->index('spam_score');
            $table->index('is_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};
