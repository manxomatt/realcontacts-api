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
     * Membuat tabel contact_tags untuk menyimpan tag yang dibuat user.
     * Tag digunakan untuk kategorisasi kontak (Pelanggan, Supplier, Keluarga, dll).
     *
     * Per-user: setiap user punya set tag masing-masing.
     * Termasuk system tags yang tidak bisa dihapus.
     */
    public function up(): void
    {
        Schema::create('contact_tags', function (Blueprint $table): void {
            $table->id();

            // Foreign key ke users
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Nama tag: "Pelanggan", "Supplier", "Keluarga", dll (max 50 karakter)
            $table->string('name', 50);

            // Hex color untuk UI display: "#FF5733"
            $table->string('color')
                ->nullable();

            // Nama icon untuk UI Flutter: "people", "business", "home", dll
            $table->string('icon')
                ->nullable();

            // Apakah tag ini bawaan sistem (tidak bisa dihapus)
            $table->boolean('is_system')
                ->default(false);

            // Berapa banyak kontak yang pakai tag ini
            $table->integer('usage_count')
                ->default(0);

            // Timestamps (created_at, updated_at)
            $table->timestamps();

            // ─── Index ─────────────────────────────────────────────────────
            // Unique: satu user tidak bisa punya 2 tag dengan nama yang sama
            $table->unique(['user_id', 'name']);

            // Index untuk query tag sistem dengan cepat
            $table->index(['user_id', 'is_system']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_tags');
    }
};
