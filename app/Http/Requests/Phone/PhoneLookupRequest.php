<?php

declare(strict_types=1);

namespace App\Http\Requests\Phone;

use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

class PhoneLookupRequest extends FormRequest
{
    // Batas lookup harian untuk user gratis
    private const FREE_DAILY_LIMIT = 5;

    // ─── Otorisasi ─────────────────────────────────────────────────────────────

    /**
     * Endpoint lookup hanya untuk user yang sudah login via Sanctum.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Override response saat authorize() mengembalikan false.
     * Gunakan format standar ApiResponse.
     */
    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::unauthorized(
                'Silakan login untuk menggunakan fitur pencarian nomor.'
            )
        );
    }

    // ─── Normalisasi ───────────────────────────────────────────────────────────

    /**
     * Merge route parameter {number} ke dalam input request
     * agar bisa divalidasi oleh rules() seperti field biasa.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'number' => $this->normalizeNumber(
                (string) $this->route('number')
            ),
        ]);
    }

    // ─── Validasi ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Diambil dari route parameter, sudah dinormalisasi di validationData()
            'number' => [
                'required',
                'string',
                'min:8',
                'max:16',   // +62 (3) + 13 digit = 16 karakter maks
                // Hanya angka dan tanda '+' — spasi/strip sudah dihapus di normalizeNumber()
                'regex:/^\+?[0-9]{8,15}$/',
            ],
        ];
    }

    /**
     * Hook withValidator() — dijalankan setelah rules() lulus.
     * Cek rate limit harian untuk user gratis.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Lewati cek jika field sudah gagal validasi
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();

            // User premium tidak dibatasi
            if ($user->is_premium) {
                return;
            }

            // Key cache: lookup_count:{user_id}:{tanggal_hari_ini}
            // TTL di-set hingga akhir hari agar counter reset tengah malam
            $cacheKey = sprintf(
                'lookup_count:%d:%s',
                $user->id,
                now()->toDateString()   // "2026-05-01"
            );

            $count = (int) Cache::get($cacheKey, 0);

            if ($count >= self::FREE_DAILY_LIMIT) {
                $v->errors()->add(
                    'number',
                    'Batas pencarian harian tercapai. Upgrade ke Premium untuk pencarian unlimited.'
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
            'number.required' => 'Nomor telepon wajib diisi.',
            'number.string'   => 'Nomor telepon harus berupa teks.',
            'number.min'      => 'Nomor telepon terlalu pendek (minimal 8 digit).',
            'number.max'      => 'Nomor telepon terlalu panjang (maksimal 15 digit).',
            'number.regex'    => 'Format nomor tidak valid. Hanya angka dan awalan + yang diperbolehkan.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'number' => 'nomor telepon',
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
     * Normalisasi nomor telepon dari URL parameter.
     * - Hapus semua karakter selain digit dan '+'
     * - Konversi 08xx → +62xx
     * - Konversi 628xx (tanpa +) → +628xx
     *
     * Menggunakan pipe operator |> PHP 8.5.
     */
    public function normalizeNumber(string $number): string
    {
        return PhoneNumberFormat::normalize($number);
    }

    /**
     * Kembalikan nomor yang sudah dinormalisasi dari validated data.
     * Shortcut untuk digunakan di Controller setelah ->validated().
     */
    public function normalizedNumber(): string
    {
        return $this->normalizeNumber(
            (string) $this->route('number')
        );
    }
}
