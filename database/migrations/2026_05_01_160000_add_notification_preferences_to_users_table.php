<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom notification_preferences ke tabel users.
     * Disimpan sebagai JSON — fleksibel untuk menambah preferensi baru di masa depan.
     * Default: semua notifikasi aktif.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_preferences')
                  ->nullable()
                  ->after('badge_level')
                  ->comment('Preferensi notifikasi user: spam_alert, call_blocked, weekly_digest');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });
    }
};
