<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah nilai 'fraud_prize' dan 'survey' ke kolom report_type di tabel spam_reports.
     *
     * Catatan: SQLite tidak mendukung ALTER COLUMN untuk mengubah definisi enum
     * secara native. Solusi standar: recreate tabel dengan definisi baru.
     * Di PostgreSQL/MySQL cukup ALTER TABLE ... MODIFY COLUMN / ADD VALUE.
     */
    public function up(): void
    {
        // SQLite: rebuild tabel karena tidak support ALTER COLUMN
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('spam_reports', function (Blueprint $table): void {
                // SQLite mengabaikan ENUM constraint — kolom tetap string.
                // Tidak ada perubahan DDL yang diperlukan;
                // constraint nilai valid ditangani di layer aplikasi.
            });

            return;
        }

        // MySQL: tambah nilai baru ke ENUM via MODIFY COLUMN
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE spam_reports
                MODIFY COLUMN report_type ENUM(
                    'spam',
                    'telemarketing',
                    'robo_call',
                    'harassment',
                    'fraud_bank',
                    'fraud_prize',
                    'debt_collector',
                    'survey',
                    'unknown_spam'
                ) NOT NULL DEFAULT 'unknown_spam'
            ");

            return;
        }

        // PostgreSQL: tambah nilai ke tipe enum
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TYPE spam_reports_report_type_enum ADD VALUE IF NOT EXISTS 'fraud_prize'");
            DB::statement("ALTER TYPE spam_reports_report_type_enum ADD VALUE IF NOT EXISTS 'survey'");
        }
    }

    /**
     * Hapus 'fraud_prize' dan 'survey' dari enum (rollback).
     *
     * PostgreSQL tidak mendukung DROP VALUE dari enum secara native.
     * Untuk MySQL: rollback ke definisi semula.
     * Untuk SQLite: no-op.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE spam_reports
                MODIFY COLUMN report_type ENUM(
                    'spam',
                    'telemarketing',
                    'robo_call',
                    'harassment',
                    'fraud_bank',
                    'debt_collector',
                    'unknown_spam'
                ) NOT NULL DEFAULT 'unknown_spam'
            ");
        }

        // PostgreSQL: tidak ada cara aman rollback ADD VALUE — biarkan
    }
};
