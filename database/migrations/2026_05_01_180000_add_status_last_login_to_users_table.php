<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom status dan last_login_at ke tabel users.
     *
     * status     : siklus hidup akun (unverified → active → suspended)
     * last_login_at : timestamp login terakhir untuk audit
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 20)
                  ->default('unverified')
                  ->after('phone_number')
                  ->comment('Status akun: unverified, active, suspended');

            $table->timestamp('last_login_at')
                  ->nullable()
                  ->after('status')
                  ->comment('Waktu login terakhir');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'last_login_at']);
        });
    }
};
