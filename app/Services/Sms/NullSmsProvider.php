<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * NullSmsProvider
 *
 * No-op SMS provider yang tidak mengirim pesan apapun.
 * Digunakan saat OTP_SMS_ENABLED=false di .env, perfect untuk development
 * atau ketika SMS provider belum dikonfigurasi.
 *
 * OTP tetap di-generate dan disimpan ke database/Redis, cukup tidak dikirim
 * ke nomor tujuan. Cocok untuk testing manual atau development environment.
 *
 * @author RealContacts API
 * @version 1.0
 */
class NullSmsProvider implements SmsProviderInterface
{
    /**
     * Tidak mengirim SMS apapun.
     *
     * Method ini di-call oleh OtpService->send(), tapi tidak melakukan
     * aksi pengiriman. Selalu return true untuk membuat flow OTP tetap jalan.
     *
     * @param string $phoneNumber Nomor tujuan (tidak digunakan)
     * @param string $message     Pesan (tidak digunakan)
     * @return bool               Selalu true
     */
    public function send(string $phoneNumber, string $message): bool
    {
        // Log bahwa SMS tidak dikirim (untuk debugging)
        Log::info('SMS tidak dikirim (NullSmsProvider aktif)', [
            'phone' => $this->maskNumber($phoneNumber),
            'reason' => 'OTP_SMS_ENABLED=false di .env',
        ]);

        // Return true agar OtpService menganggap pengiriman sukses
        // Walau sebenarnya tidak ada yang terkirim
        return true;
    }

    /**
     * Mask nomor telepon untuk security logging.
     *
     * @param string $phoneNumber Format: +62xxxxxxxxxxxx
     * @return string             Format: +62****567890
     */
    private function maskNumber(string $phoneNumber): string
    {
        // Ambil 4 digit terakhir saja
        $lastFour = substr($phoneNumber, -4);
        return substr_replace($phoneNumber, '****', 4, -4);
    }
}
