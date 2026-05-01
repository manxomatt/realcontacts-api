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
        Schema::create('spam_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phone_number_id')           // Nomor yang dilaporkan
                  ->constrained()
                  ->cascadeOnDelete();
            $table->foreignId('user_id')                   // User pelapor (opsional, null = anonim)
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->enum('report_type', [                  // Kategori laporan spam
                'spam',
                'telemarketing',
                'robo_call',
                'harassment',
                'fraud_bank',
                'debt_collector',
                'unknown_spam',
            ])->default('unknown_spam');
            $table->text('description')->nullable();        // Keterangan tambahan dari pelapor
            $table->unsignedInteger('upvotes')->default(0); // Jumlah suara setuju laporan valid
            $table->unsignedInteger('downvotes')->default(0); // Jumlah suara laporan tidak valid
            $table->enum('status', ['pending', 'verified', 'rejected'])
                  ->default('pending');                    // Status moderasi laporan
            $table->timestamps();

            $table->index('phone_number_id');
            $table->index('report_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spam_reports');
    }
};
