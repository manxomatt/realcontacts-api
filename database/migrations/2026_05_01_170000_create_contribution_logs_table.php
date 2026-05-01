<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel log penambahan dan pengurangan poin kontribusi user.
     * Digunakan oleh ContributionService untuk audit trail dan leaderboard.
     */
    public function up(): void
    {
        Schema::create('contribution_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->integer('points');                          // Positif = tambah, negatif = kurang
            $table->string('reason', 100);                     // Alasan perubahan poin
            $table->unsignedInteger('total_after');            // Total poin setelah transaksi
            $table->timestamps();

            $table->index('user_id');
            $table->index('created_at');                       // Untuk query leaderboard per periode
            $table->index(['user_id', 'created_at']);          // Composite untuk filter user + periode
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_logs');
    }
};
