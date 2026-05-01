<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

class VerifyOtpRequest extends FormRequest
{
    /**
     * Endpoint verifikasi OTP bersifat publik — selalu izinkan.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi input sebelum validasi dijalankan:
     * - Normalisasi phone_number (hapus spasi/strip, konversi 08xx → +62xx)
     * - Trim spasi dari otp_code
     * - Set device_name default jika tidak dikirim
     */
    protected function prepareForValidation(): void
    {
        // ─── Normalisasi phone_number ──────────────────────────────────────────
        $phone = $this->input('phone_number');

        if ($phone !== null) {
            $this->merge(['phone_number' => PhoneNumberFormat::normalize((string) $phone)]);
        }

        // ─── Trim otp_code ─────────────────────────────────────────────────────
        $otp = $this->input('otp_code');

        if ($otp !== null) {
            $this->merge(['otp_code' => trim((string) $otp)]);
        }

        // ─── Default device_name ───────────────────────────────────────────────
        if (blank($this->input('device_name'))) {
            $this->merge(['device_name' => 'Mobile Device']);
        }
    }

    /**
     * Aturan validasi field.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ─── Nomor Telepon ─────────────────────────────────────────────────
            'phone_number' => [
                'required',
                'string',
                new PhoneNumberFormat(),
                // Wajib sudah terdaftar sebagai user
                'exists:users,phone_number',
            ],

            // ─── Kode OTP ──────────────────────────────────────────────────────
            'otp_code' => [
                'required',
                'string',
                'digits:6',     // tepat 6 karakter, hanya angka
            ],

            // ─── Nama Perangkat (untuk Sanctum token) ─────────────────────────
            'device_name' => [
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    /**
     * Hook after() — dijalankan setelah validasi field lulus.
     * Cek apakah OTP masih valid di Redis/Cache.
     *
     * Key Redis: otp:{phone_number}
     * Value: kode OTP 6 digit yang dikirim via SMS
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Jalankan hanya jika validasi field sebelumnya lulus
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $phone   = $this->input('phone_number');
                $cacheKey = 'otp:' . $phone;

                // Cek apakah OTP masih ada di cache (belum expired)
                if (!Cache::has($cacheKey)) {
                    $validator->errors()->add(
                        'otp_code',
                        'Kode OTP sudah kedaluwarsa, minta kode baru.'
                    );
                }
            },
        ];
    }

    /**
     * Pesan error validasi dalam Bahasa Indonesia.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // ─── phone_number ──────────────────────────────────────────────────
            'phone_number.required' => 'Nomor telepon wajib diisi.',
            'phone_number.string'   => 'Nomor telepon harus berupa teks.',
            'phone_number.exists'   => 'Nomor telepon tidak terdaftar.',

            // ─── otp_code ──────────────────────────────────────────────────────
            'otp_code.required' => 'Kode OTP wajib diisi.',
            'otp_code.string'   => 'Kode OTP harus berupa teks.',
            'otp_code.digits'   => 'Kode OTP harus terdiri dari tepat 6 angka.',

            // ─── device_name ───────────────────────────────────────────────────
            'device_name.string' => 'Nama perangkat harus berupa teks.',
            'device_name.max'    => 'Nama perangkat maksimal 100 karakter.',
        ];
    }

    /**
     * Label field yang lebih ramah untuk pesan error otomatis Laravel.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone_number' => 'nomor telepon',
            'otp_code'     => 'kode OTP',
            'device_name'  => 'nama perangkat',
        ];
    }

    /**
     * Override penanganan validasi gagal — gunakan format standar ApiResponse.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::validationError(
                $validator->errors()->toArray()
            )
        );
    }
}
