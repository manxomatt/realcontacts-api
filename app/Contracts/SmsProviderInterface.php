<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * SmsProviderInterface
 *
 * Kontrak untuk semua provider SMS yang digunakan aplikasi.
 * Abstraksi ini memungkinkan penggantian provider (Twilio, Vonage,
 * WA Business API, dll.) tanpa mengubah OtpService.
 */
interface SmsProviderInterface
{
    /**
     * Kirim pesan SMS ke nomor tujuan.
     *
     * @param  string $phoneNumber Nomor tujuan dalam format internasional (+62xxx)
     * @param  string $message     Isi pesan yang akan dikirim
     * @return bool                true jika API provider menerima pesan, false jika gagal
     */
    public function send(string $phoneNumber, string $message): bool;
}
