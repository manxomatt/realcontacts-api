<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom reporter_user_id dan evidence_type ke tabel spam_reports.
     *
     * reporter_user_id : FK eksplisit ke user yang melaporkan (berbeda dari user_id anonim)
     * evidence_type    : jenis bukti yang dilampirkan pelapor
     *
     * Buat juga tabel spam_report_votes untuk tracking siapa sudah upvote/downvote.
     */
    public function up(): void
    {
        // Tambah kolom ke spam_reports
        Schema::table('spam_reports', function (Blueprint $table): void {
            $table->foreignId('reporter_user_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('users')
                  ->nullOnDelete()
                  ->comment('User yang melaporkan (bisa berbeda dari user_id)');

            $table->string('evidence_type', 30)
                  ->nullable()
                  ->after('description')
                  ->comment('Jenis bukti: call_recording, screenshot, personal_experience');

            $table->index('reporter_user_id');
        });

        // Tabel voting untuk spam report
        Schema::create('spam_report_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spam_report_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->enum('type', ['upvote', 'downvote']);
            $table->timestamps();

            // Satu user hanya bisa vote satu kali per laporan
            $table->unique(['spam_report_id', 'user_id']);
            $table->index('spam_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spam_report_votes');

        Schema::table('spam_reports', function (Blueprint $table): void {
            $table->dropIndex(['reporter_user_id']);
            $table->dropConstrainedForeignId('reporter_user_id');
            $table->dropColumn(['reporter_user_id', 'evidence_type']);
        });
    }
};
