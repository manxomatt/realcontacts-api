<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * RequestOtpRequest
 *
 * Validasi request untuk mengirim OTP pertama kali
 * (belum tentu user terdaftar — dipakai setelah registrasi).
 */
class RequestOtpRequest extends FormRequest
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
            'phone_number' => ['required', 'string', 'min:9', 'max:16', new PhoneNumberFormat()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone_number.required' => 'Nomor telepon wajib diisi.',
            'phone_number.min'      => 'Nomor telepon terlalu pendek.',
            'phone_number.max'      => 'Nomor telepon terlalu panjang.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::validationError($validator->errors()->toArray())
        );
    }
}
