<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumberFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterRequest extends FormRequest
{
    /**
     * Endpoint registrasi bersifat publik — selalu izinkan.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi input sebelum validasi dijalankan.
     * - Hapus spasi, titik, dan strip dari phone_number
     * - Konversi format lokal 08xx → internasional +62xx
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone_number');

        if ($phone !== null) {
            $this->merge(['phone_number' => PhoneNumberFormat::normalize((string) $phone)]);
        }

        // Pastikan country_code ada dan dalam format benar
        if ($this->input('country_code') === null) {
            $this->merge(['country_code' => '+62']);
        }
    }

    /**
     * Aturan validasi untuk registrasi pengguna baru.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ─── Nama ──────────────────────────────────────────────────────────
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            // ─── Nomor Telepon ─────────────────────────────────────────────────
            // Divalidasi setelah prepareForValidation() menormalisasi format
            'phone_number' => [
                'required',
                'string',
                'min:9',
                'max:16',          // +62 (3) + 13 digit = 16 karakter maks
                new PhoneNumberFormat(),
                'unique:users,phone_number',
            ],

            // ─── Kode Negara (opsional) ────────────────────────────────────────
            'country_code' => [
                'nullable',
                'string',
                'regex:/^\+[0-9]{1,4}$/',  // Format: '+62', '+1', '+44', dst.
                'max:5',
            ],
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
            // ─── name ──────────────────────────────────────────────────────────
            'name.required' => 'Nama wajib diisi.',
            'name.string'   => 'Nama harus berupa teks.',
            'name.min'      => 'Nama minimal 2 karakter.',
            'name.max'      => 'Nama maksimal 100 karakter.',

            // ─── phone_number ──────────────────────────────────────────────────
            'phone_number.required' => 'Nomor telepon wajib diisi.',
            'phone_number.string'   => 'Nomor telepon harus berupa teks.',
            'phone_number.min'      => 'Nomor telepon terlalu pendek (minimal 9 digit).',
            'phone_number.max'      => 'Nomor telepon terlalu panjang (maksimal 15 digit).',
            'phone_number.unique'   => 'Nomor telepon sudah terdaftar.',

            // ─── country_code ──────────────────────────────────────────────────
            'country_code.string' => 'Kode negara harus berupa teks.',
            'country_code.regex'  => 'Format kode negara tidak valid. Contoh: +62, +1, +44.',
            'country_code.max'    => 'Kode negara maksimal 5 karakter.',
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
            'name'         => 'nama',
            'phone_number' => 'nomor telepon',
            'country_code' => 'kode negara',
        ];
    }

    /**
     * Override penanganan validasi gagal.
     * Menggunakan ApiResponse::validationError agar format konsisten
     * dengan seluruh response API RealContacts.
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
