<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phone_number_id')         // Relasi ke tabel phone_numbers
                  ->constrained()
                  ->cascadeOnDelete()
                  ->unique();                            // Satu nomor hanya boleh punya satu profil bisnis
            $table->string('business_name');             // Nama bisnis
            $table->string('category', 50)->nullable();  // Kategori bisnis
            $table->text('description')->nullable();     // Deskripsi singkat bisnis
            $table->string('website')->nullable();       // URL website
            $table->string('email')->nullable();         // Email bisnis
            $table->string('address')->nullable();       // Alamat lengkap
            $table->string('city', 100)->nullable();     // Kota
            $table->string('province', 100)->nullable(); // Provinsi
            $table->boolean('is_verified')->default(false); // Status verifikasi
            $table->timestamp('verified_at')->nullable(); // Waktu verifikasi
            $table->json('operating_hours')->nullable();  // Jam operasional per hari (JSON)
            $table->decimal('rating', 3, 1)->nullable();  // Rating 1.0-5.0, null jika belum ada
            $table->string('maps_url')->nullable();       // URL Google Maps
            $table->boolean('is_claimed')->default(false); // Sudah diklaim pemilik asli
            $table->timestamps();

            $table->index('category');
            $table->index('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
