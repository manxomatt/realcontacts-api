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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')                   // Pemilik kontak
                  ->constrained()
                  ->cascadeOnDelete();
            $table->foreignId('phone_number_id')           // Referensi ke phone_numbers (opsional)
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->string('name');                        // Nama tampilan kontak
            $table->string('phone_raw', 30);               // Nomor asli yang diinputkan user
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['user_id', 'phone_raw']);      // Satu user tidak boleh simpan nomor yang sama dua kali
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
