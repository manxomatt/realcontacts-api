<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom moderasi ke tabel spam_reports.
     *
     * moderator_id    : user admin yang melakukan moderasi
     * moderated_at    : timestamp saat dimoderasi
     * rejection_reason: alasan penolakan laporan
     * moderator_notes : catatan internal moderator
     */
    public function up(): void
    {
        Schema::table('spam_reports', function (Blueprint $table): void {
            $table->foreignId('moderator_id')
                  ->nullable()
                  ->after('reporter_user_id')
                  ->constrained('users')
                  ->nullOnDelete()
                  ->comment('Admin yang memoderasi laporan');

            $table->timestamp('moderated_at')
                  ->nullable()
                  ->after('moderator_id')
                  ->comment('Waktu moderasi dilakukan');

            $table->string('rejection_reason', 255)
                  ->nullable()
                  ->after('moderated_at')
                  ->comment('Alasan penolakan — wajib diisi saat reject');

            $table->text('moderator_notes')
                  ->nullable()
                  ->after('rejection_reason')
                  ->comment('Catatan internal moderator');

            $table->index('moderator_id');
            $table->index('moderated_at');
        });
    }

    public function down(): void
    {
        Schema::table('spam_reports', function (Blueprint $table): void {
            $table->dropIndex(['moderator_id']);
            $table->dropIndex(['moderated_at']);
            $table->dropConstrainedForeignId('moderator_id');
            $table->dropColumn(['moderated_at', 'rejection_reason', 'moderator_notes']);
        });
    }
};
