<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

class UpdateProfileRequest extends FormRequest
{
    // ─── Otorisasi ─────────────────────────────────────────────────────────────

    /**
     * Hanya user yang sudah login yang dapat memperbarui profil.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::unauthorized(
                'Silakan login untuk memperbarui profil.'
            )
        );
    }

    // ─── Normalisasi ───────────────────────────────────────────────────────────

    /**
     * Normalisasi input sebelum validasi:
     * - Trim semua string input
     * - Normalisasi new_phone_number ke format internasional
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        // Trim semua field string yang dikirim
        foreach (['name', 'otp_code'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $trimmed[$field] = trim($value);
            }
        }

        // Normalisasi nomor telepon baru
        $newPhone = $this->input('new_phone_number');
        if (is_string($newPhone) && trim($newPhone) !== '') {
            $trimmed['new_phone_number'] = PhoneNumberFormat::normalize(trim($newPhone));
        }

        if (!empty($trimmed)) {
            $this->merge($trimmed);
        }
    }

    // ─── Validasi ──────────────────────────────────────────────────────────────

    /**
     * Semua field opsional — mendukung partial update (PATCH).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            // ─── Nama ──────────────────────────────────────────────────────────
            // Hanya huruf, spasi, titik, apostrof — tolak karakter spesial lain
            'name' => [
                'sometimes',
                'nullable',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\pL\s.\'-]+$/u',
            ],

            // ─── Nomor telepon baru ────────────────────────────────────────────
            // Unik di tabel users kecuali nomor user itu sendiri
            'new_phone_number' => [
                'sometimes',
                'nullable',
                'string',
                new PhoneNumberFormat(),
                "unique:users,phone_number,{$userId}",
            ],

            // ─── Kode OTP ──────────────────────────────────────────────────────
            // Wajib hanya jika new_phone_number dikirim dan tidak kosong
            'otp_code' => [
                'required_with:new_phone_number',
                'nullable',
                'string',
                'digits:6',
            ],

            // ─── Preferensi notifikasi ─────────────────────────────────────────
            'notification_preferences'                => [
                'sometimes',
                'nullable',
                'array',
            ],
            'notification_preferences.spam_alert'    => [
                'sometimes',
                'boolean',
            ],
            'notification_preferences.call_blocked'  => [
                'sometimes',
                'boolean',
            ],
            'notification_preferences.weekly_digest' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Hook withValidator() — validasi OTP Redis untuk nomor telepon baru.
     * Dijalankan hanya jika new_phone_number ada dan field validation lulus.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $newPhone = $this->input('new_phone_number');

            // Lewati jika tidak mengganti nomor telepon
            if (empty($newPhone)) {
                return;
            }

            $otpCode   = $this->input('otp_code');
            $cacheKey  = 'otp:' . $newPhone;

            // ── Cek 1: OTP belum kedaluwarsa ─────────────────────────────────
            if (! Cache::has($cacheKey)) {
                $v->errors()->add(
                    'otp_code',
                    'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.'
                );
                return;
            }

            // ── Cek 2: OTP cocok ──────────────────────────────────────────────
            $storedOtp = Cache::get($cacheKey);
            if ((string) $storedOtp !== (string) $otpCode) {
                $v->errors()->add(
                    'otp_code',
                    'Kode OTP tidak valid.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // ─── name ──────────────────────────────────────────────────────────
            'name.min'   => 'Nama minimal 2 karakter.',
            'name.max'   => 'Nama maksimal 100 karakter.',
            'name.regex' => 'Nama hanya boleh mengandung huruf, spasi, titik, atau apostrof.',

            // ─── new_phone_number ──────────────────────────────────────────────
            'new_phone_number.unique' => 'Nomor telepon ini sudah digunakan oleh akun lain.',

            // ─── otp_code ──────────────────────────────────────────────────────
            'otp_code.required_with' => 'Kode OTP wajib diisi saat mengganti nomor telepon.',
            'otp_code.digits'        => 'Kode OTP harus tepat 6 angka.',

            // ─── notification_preferences ──────────────────────────────────────
            'notification_preferences.array'                    => 'Format preferensi notifikasi tidak valid.',
            'notification_preferences.spam_alert.boolean'       => 'Nilai spam_alert harus true atau false.',
            'notification_preferences.call_blocked.boolean'     => 'Nilai call_blocked harus true atau false.',
            'notification_preferences.weekly_digest.boolean'    => 'Nilai weekly_digest harus true atau false.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name'                                   => 'nama',
            'new_phone_number'                       => 'nomor telepon baru',
            'otp_code'                               => 'kode OTP',
            'notification_preferences'               => 'preferensi notifikasi',
            'notification_preferences.spam_alert'    => 'notifikasi spam',
            'notification_preferences.call_blocked'  => 'notifikasi panggilan diblokir',
            'notification_preferences.weekly_digest' => 'ringkasan mingguan',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::validationError(
                $validator->errors()->toArray()
            )
        );
    }
}
