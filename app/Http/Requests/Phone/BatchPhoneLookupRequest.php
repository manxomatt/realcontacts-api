<?php

declare(strict_types=1);

namespace App\Http\Requests\Phone;

use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BatchPhoneLookupRequest extends FormRequest
{
    // Batas maksimal nomor per batch request
    private const MAX_NUMBERS = 10;

    // Minimal 2 nomor — jika 1, pakai endpoint GET /api/phone/{number}
    private const MIN_NUMBERS = 2;

    // ─── Otorisasi ─────────────────────────────────────────────────────────────

    /**
     * Hanya user yang sudah login DAN memiliki akun Premium.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->is_premium === true;
    }

    /**
     * Override response 403 saat bukan Premium.
     */
    protected function failedAuthorization(): never
    {
        $user = $this->user();

        // Bedakan: belum login (401) vs sudah login tapi bukan premium (403)
        if ($user === null) {
            throw new HttpResponseException(
                \App\Http\Responses\ApiResponse::unauthorized(
                    'Silakan login untuk menggunakan fitur ini.'
                )
            );
        }

        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::forbidden(
                'Fitur batch lookup hanya tersedia untuk pengguna Premium.'
            )
        );
    }

    // ─── Normalisasi ───────────────────────────────────────────────────────────

    /**
     * Normalisasi semua nomor dalam array sebelum validasi.
     * - Hapus karakter selain digit dan '+'
     * - Konversi 08xx → +62xx, 628xx → +628xx
     * - Hapus duplikat (unik per array)
     */
    protected function prepareForValidation(): void
    {
        $numbers = $this->input('numbers');

        if (!is_array($numbers)) {
            return;
        }

        // Normalisasi tiap nomor dalam array menggunakan pipe operator PHP 8.5
        $normalized = array_map(
            fn(mixed $number): string => $this->normalizeNumber((string) $number),
            $numbers
        );

        // Hapus duplikat dan re-index
        $unique = array_values(array_unique($normalized));

        // PHP 8.5 — gunakan array_first() dan array_last() untuk logging/debug
        // (juga memperlihatkan range nomor yang akan di-lookup)
        $first = array_first($unique);
        $last  = array_last($unique);

        $this->merge([
            'numbers'      => $unique,
            'total_numbers' => count($unique),
            '_range_first'  => $first,  // metadata internal, tidak di-expose ke client
            '_range_last'   => $last,
        ]);
    }

    // ─── Validasi ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ─── Array nomor ───────────────────────────────────────────────────
            'numbers' => [
                'required',
                'array',
                'min:' . self::MIN_NUMBERS,
                'max:' . self::MAX_NUMBERS,
            ],

            // ─── Setiap elemen array ───────────────────────────────────────────
            'numbers.*' => [
                'required',
                'string',
                'min:8',
                'max:16',
                new PhoneNumberFormat(),
                // Validasi unik dalam array — duplikat sudah dihapus di
                // prepareForValidation(), rule ini sebagai safety net
                'distinct:strict',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // ─── numbers ───────────────────────────────────────────────────────
            'numbers.required' => 'Daftar nomor telepon wajib diisi.',
            'numbers.array'    => 'Format data harus berupa array.',
            'numbers.min'      => 'Minimal ' . self::MIN_NUMBERS . ' nomor telepon diperlukan. Untuk 1 nomor, gunakan endpoint pencarian tunggal.',
            'numbers.max'      => 'Maksimal ' . self::MAX_NUMBERS . ' nomor telepon per request.',

            // ─── numbers.* ─────────────────────────────────────────────────────
            'numbers.*.required'  => 'Setiap nomor telepon wajib diisi.',
            'numbers.*.string'    => 'Nomor telepon harus berupa teks.',
            'numbers.*.min'       => 'Nomor telepon terlalu pendek (minimal 8 digit).',
            'numbers.*.max'       => 'Nomor telepon terlalu panjang (maksimal 15 digit).',
            'numbers.*.distinct'  => 'Terdapat nomor telepon duplikat dalam daftar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'numbers'   => 'daftar nomor telepon',
            'numbers.*' => 'nomor telepon',
        ];
    }

    /**
     * Override penanganan validasi gagal — format standar ApiResponse.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::validationError(
                $validator->errors()->toArray()
            )
        );
    }

    // ─── Helper ────────────────────────────────────────────────────────────────

    /**
     * Normalisasi satu nomor telepon menggunakan pipe operator PHP 8.5.
     * - Hapus semua karakter kecuali digit dan '+'
     * - 08xx → +62xx
     * - 628xx (tanpa +) → +628xx
     */
    public function normalizeNumber(string $number): string
    {
        return PhoneNumberFormat::normalize($number);
    }

    /**
     * Kembalikan array nomor yang sudah dinormalisasi dan tervalidasi.
     * Shortcut untuk digunakan di Controller.
     *
     * @return string[]
     */
    public function normalizedNumbers(): array
    {
        return (array) $this->validated('numbers');
    }
}
