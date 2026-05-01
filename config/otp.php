<?php

declare(strict_types=1);

/**
 * OTP Configuration
 *
 * Konfigurasi untuk One-Time Password (OTP) system yang digunakan
 * dalam authentication dan verification flows.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | OTP SMS Sending
    |--------------------------------------------------------------------------
    |
    | Tentukan apakah OTP akan dikirim via SMS atau hanya di-generate
    | dan disimpan ke database/Redis.
    |
    | true:  OTP dikirim via SMS provider (Twilio, Vonage, dll)
    |        - Butuh SMS provider yang sudah dikonfigurasi
    |        - Production: set ini ke true
    |
    | false: OTP tidak dikirim (NullSmsProvider aktif)
    |        - OTP tetap di-generate dan disimpan, cukup tidak dikirim
    |        - Perfect untuk development, testing, dan local environment
    |        - User bisa langsung copy OTP dari logs atau database
    |
    */

    'sms_enabled' => (bool) env('OTP_SMS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | OTP Length
    |--------------------------------------------------------------------------
    |
    | Panjang kode OTP dalam digit (biasanya 4-8).
    | Default: 6 digit (standard industry)
    |
    */

    'length' => (int) env('OTP_LENGTH', 6),

    /*
    |--------------------------------------------------------------------------
    | OTP TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | Berapa lama OTP valid (dalam detik).
    | Default: 300 detik (5 menit)
    |
    | Contoh:
    | - 300:  5 menit (recommended)
    | - 600:  10 menit (lebih santai)
    | - 1800: 30 menit (terlalu lama, security risk)
    |
    */

    'ttl' => (int) env('OTP_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | OTP Max Attempts
    |--------------------------------------------------------------------------
    |
    | Berapa percobaan verify sebelum nomor di-blacklist.
    | Default: 3 attempt
    |
    | Setelah melampaui limit ini, nomor akan diblacklist selama OTP_TTL.
    | Melindungi dari brute-force attack.
    |
    */

    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | SMS Provider Driver
    |--------------------------------------------------------------------------
    |
    | Provider SMS yang akan digunakan.
    | Supported: 'twilio', 'vonage', 'aws-sns', 'local'
    |
    | 'local' = NullSmsProvider (development/testing)
    |
    */

    'sms_provider' => env('SMS_PROVIDER', 'local'),

];
