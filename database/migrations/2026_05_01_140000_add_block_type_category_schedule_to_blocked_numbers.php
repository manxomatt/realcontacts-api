<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom block_type, category, dan schedule ke tabel blocked_numbers.
     */
    public function up(): void
    {
        Schema::table('blocked_numbers', function (Blueprint $table): void {
            // Tipe pemblokiran: manual (default), berdasarkan kategori, atau terjadwal
            $table->string('block_type', 20)
                  ->default('manual')
                  ->after('reason');

            // Kategori yang diblokir — hanya relevan jika block_type = 'category'
            $table->string('category', 50)
                  ->nullable()
                  ->after('block_type');

            // Jadwal pemblokiran (JSON) — hanya relevan jika block_type = 'schedule'
            // Contoh: {"start_time":"22:00","end_time":"07:00","days":["mon","tue"]}
            $table->json('schedule')
                  ->nullable()
                  ->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('blocked_numbers', function (Blueprint $table): void {
            $table->dropColumn(['block_type', 'category', 'schedule']);
        });
    }
};
