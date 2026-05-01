<?php

declare(strict_types=1);

namespace App\Http\Requests\Business;

use App\Models\BusinessProfile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateBusinessProfileRequest extends FormRequest
{
    // ─── Otorisasi ─────────────────────────────────────────────────────────────

    /**
     * Hanya pemilik profil bisnis (claimed_by_user_id) yang boleh mengedit.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        /** @var BusinessProfile|null $profile */
        $profile = $this->route('businessProfile') ?? $this->route('business_profile');

        // Jika belum ada model di route, izinkan — validasi di controller
        if ($profile === null) {
            return true;
        }

        return $profile->claimed_by_user_id === $user->id;
    }

    protected function failedAuthorization(): never
    {
        if ($this->user() === null) {
            throw new HttpResponseException(
                \App\Http\Responses\ApiResponse::unauthorized(
                    'Silakan login untuk mengedit profil bisnis.'
                )
            );
        }

        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::forbidden(
                'Anda tidak memiliki izin untuk mengedit profil bisnis ini.'
            )
        );
    }

    // ─── Validasi ──────────────────────────────────────────────────────────────

    /**
     * Semua field opsional — mendukung partial update (PATCH).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ─── Nama bisnis ───────────────────────────────────────────────────
            'business_name' => [
                'sometimes',
                'nullable',
                'string',
                'min:3',
                'max:150',
            ],

            // ─── Kategori bisnis ───────────────────────────────────────────────
            'category' => [
                'sometimes',
                'nullable',
                'string',
                'in:restaurant,courier,ojol,retail,healthcare,finance,education,other',
            ],

            // ─── Alamat ────────────────────────────────────────────────────────
            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:300',
            ],

            // ─── Jam operasional ───────────────────────────────────────────────
            // Array of items, masing-masing punya day, open, close
            'operating_hours'         => [
                'sometimes',
                'nullable',
                'array',
            ],
            'operating_hours.*.day'   => [
                'required',
                'string',
                'in:mon,tue,wed,thu,fri,sat,sun',
            ],
            'operating_hours.*.open'  => [
                'required',
                'nullable',
                'string',
                // Format HH:mm atau null (tutup)
                'regex:/^([01]\d|2[0-3]):([0-5]\d)$/',
            ],
            'operating_hours.*.close' => [
                'required',
                'nullable',
                'string',
                'regex:/^([01]\d|2[0-3]):([0-5]\d)$/',
            ],

            // ─── URL Google Maps ───────────────────────────────────────────────
            'maps_url' => [
                'sometimes',
                'nullable',
                'string',
                'url',
                // Hanya izinkan domain Google Maps
                'regex:/^https?:\/\/(maps\.google\.com|goo\.gl)\/.+/',
            ],
        ];
    }

    /**
     * Hook withValidator() — validasi close > open untuk setiap hari.
     * Lewati jika operating_hours tidak dikirim atau salah satu null (hari tutup).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $hours = $this->input('operating_hours');
            if (! is_array($hours)) {
                return;
            }

            foreach ($hours as $index => $entry) {
                $open  = $entry['open']  ?? null;
                $close = $entry['close'] ?? null;
                $day   = $entry['day']   ?? "hari ke-{$index}";

                // Jika salah satu null, berarti tutup — lewati perbandingan
                if ($open === null || $close === null) {
                    continue;
                }

                // Bandingkan sebagai string HH:mm — cukup untuk 00:00-23:59
                if ($close <= $open) {
                    $v->errors()->add(
                        "operating_hours.{$index}.close",
                        "Waktu tutup untuk hari '{$day}' harus lebih besar dari waktu buka."
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // ─── business_name ─────────────────────────────────────────────────
            'business_name.min' => 'Nama bisnis minimal 3 karakter.',
            'business_name.max' => 'Nama bisnis maksimal 150 karakter.',

            // ─── category ──────────────────────────────────────────────────────
            'category.in' => 'Kategori tidak valid. Pilih: restaurant, courier, ojol, retail, healthcare, finance, education, atau other.',

            // ─── address ───────────────────────────────────────────────────────
            'address.max' => 'Alamat maksimal 300 karakter.',

            // ─── operating_hours ───────────────────────────────────────────────
            'operating_hours.array'           => 'Format jam operasional tidak valid, harus berupa array.',
            'operating_hours.*.day.required'  => 'Nama hari wajib diisi pada setiap jadwal.',
            'operating_hours.*.day.in'        => 'Nama hari tidak valid. Gunakan: mon, tue, wed, thu, fri, sat, atau sun.',
            'operating_hours.*.open.required' => 'Waktu buka wajib diisi (isi null jika tutup).',
            'operating_hours.*.open.regex'    => 'Format waktu buka tidak valid. Gunakan format HH:mm (contoh: 08:00).',
            'operating_hours.*.close.required' => 'Waktu tutup wajib diisi (isi null jika tutup).',
            'operating_hours.*.close.regex'    => 'Format waktu tutup tidak valid. Gunakan format HH:mm (contoh: 22:00).',

            // ─── maps_url ──────────────────────────────────────────────────────
            'maps_url.url'   => 'Format URL Google Maps tidak valid.',
            'maps_url.regex' => 'URL peta hanya boleh dari maps.google.com atau goo.gl.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'business_name'            => 'nama bisnis',
            'category'                 => 'kategori',
            'address'                  => 'alamat',
            'operating_hours'          => 'jam operasional',
            'operating_hours.*.day'    => 'hari',
            'operating_hours.*.open'   => 'waktu buka',
            'operating_hours.*.close'  => 'waktu tutup',
            'maps_url'                 => 'URL peta',
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
