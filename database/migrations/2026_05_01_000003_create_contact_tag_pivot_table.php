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
     * Membuat pivot table contact_tag_user_contact untuk relasi many-to-many
     * antara user_contacts (kontak) dan contact_tags (tag).
     *
     * Satu kontak bisa punya banyak tag.
     * Satu tag bisa dipakai di banyak kontak.
     */
    public function up(): void
    {
        Schema::create('contact_tag_user_contact', function (Blueprint $table): void {
            $table->id();

            // Foreign key ke user_contacts
            $table->foreignId('user_contact_id')
                ->constrained('user_contacts')
                ->cascadeOnDelete();

            // Foreign key ke contact_tags
            $table->foreignId('contact_tag_id')
                ->constrained('contact_tags')
                ->cascadeOnDelete();

            // Kapan tag ini ditambahkan ke kontak
            $table->timestamp('tagged_at')
                ->useCurrent();

            // ─── Index ─────────────────────────────────────────────────────
            // Unique: satu kontak tidak bisa ditag dua kali dengan tag yang sama
            $table->unique(['user_contact_id', 'contact_tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_tag_user_contact');
    }
};
