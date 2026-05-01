<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ResendOtpRequest
 *
 * Validasi request untuk mengirim ulang OTP.
 * Dipakai oleh user yang sudah terdaftar dan ingin OTP baru.
 */
class ResendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone_number');

        if ($phone !== null) {
            $this->merge(['phone_number' => PhoneNumberFormat::normalize((string) $phone)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone_number' => [
                'required',
                'string',
                'min:9',
                'max:16',
                new PhoneNumberFormat(),
                // Nomor harus sudah terdaftar — tidak perlu cek unik
                'exists:users,phone_number',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone_number.required' => 'Nomor telepon wajib diisi.',
            'phone_number.min'      => 'Nomor telepon terlalu pendek.',
            'phone_number.max'      => 'Nomor telepon terlalu panjang.',
            'phone_number.exists'   => 'Nomor telepon tidak ditemukan. Silakan registrasi terlebih dahulu.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::validationError($validator->errors()->toArray())
        );
    }
}
