<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Membuat tabel user_contacts untuk menyimpan kontak user.
     * Setiap user bisa punya banyak kontak dengan nomor telepon berbeda.
     */
    public function up(): void
    {
        Schema::create('user_contacts', function (Blueprint $table): void {
            $table->id();

            // Foreign key ke users
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Nomor telepon kontak
            $table->string('phone_number')
                ->index();

            // Nama custom yang user beri
            $table->string('custom_name')
                ->nullable();

            // Catatan privat (tidak disync ke cloud/server lain)
            $table->text('notes')
                ->nullable();

            // Apakah kontak ini favorit
            $table->boolean('is_favorite')
                ->default(false);

            // Sumber kontak: manual, phone_book, call_log
            $table->enum('contact_source', ['manual', 'phone_book', 'call_log'])
                ->default('manual');

            // Kapan terakhir ada interaksi (call/SMS) dari nomor ini
            $table->timestamp('last_interacted_at')
                ->nullable();

            // Timestamps (created_at, updated_at)
            $table->timestamps();

            // ─── Index ─────────────────────────────────────────────────────
            // Unique: satu user tidak bisa punya 2 entry untuk nomor yang sama
            $table->unique(['user_id', 'phone_number']);

            // Index untuk query kontak favorit dengan cepat
            $table->index(['user_id', 'is_favorite']);

            // Index untuk sort berdasarkan interaksi terakhir
            $table->index(['last_interacted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_contacts');
    }
};
