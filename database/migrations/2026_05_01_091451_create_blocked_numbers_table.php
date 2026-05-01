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
        Schema::create('blocked_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();            // Hapus blokir jika user dihapus
            $table->foreignId('phone_number_id')
                  ->constrained()
                  ->cascadeOnDelete();            // Hapus blokir jika nomor dihapus
            $table->string('reason')->nullable(); // Alasan user memblokir (opsional)
            $table->timestamps();

            // Satu user tidak bisa memblokir nomor yang sama dua kali
            $table->unique(['user_id', 'phone_number_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocked_numbers');
    }
};
