<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom claimed_by_user_id ke tabel business_profiles
     * untuk melacak user mana yang telah mengklaim profil bisnis.
     */
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->foreignId('claimed_by_user_id')
                  ->nullable()
                  ->after('is_claimed')
                  ->constrained('users')
                  ->nullOnDelete()         // Jika user dihapus, klaim dilepas tapi profil tetap
                  ->comment('ID user yang mengklaim profil bisnis ini');

            $table->index('claimed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropForeign(['claimed_by_user_id']);
            $table->dropIndex(['claimed_by_user_id']);
            $table->dropColumn('claimed_by_user_id');
        });
    }
};
