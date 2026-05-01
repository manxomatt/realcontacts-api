<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreContactTagRequest
 *
 * Validasi request untuk membuat custom tag baru.
 *
 * Proses:
 *   1. rules() - validasi basic fields
 *   2. withValidator() - validasi custom (20 tag limit)
 */
class StoreContactTagRequest extends FormRequest
{
    /**
     * Daftar icon yang valid untuk tag.
     * Sesuaikan dengan icon set yang digunakan di Flutter.
     *
     * @var array<string>
     */
    private const VALID_ICONS = [
        'label',
        'star',
        'bookmark',
        'flag',
        'heart',
        'user',
        'users',
        'building',
        'briefcase',
        'shopping_cart',
        'phone',
        'mail',
        'map',
        'calendar',
        'clock',
        'tag',
        'home',
        'restaurant',
        'family',
        'medical',
        'school',
        'work',
        'favorite',
        'important',
    ];

    /**
     * Determine jika user authorized untuk membuat request ini.
     */
    public function authorize(): bool
    {
        // User yang sudah authenticated bisa membuat tag
        return $this->user() !== null;
    }

    /**
     * Get the validation rules yang apply ke request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Nama tag wajib diisi.
             * String, min 2, max 50 karakter.
             * Unik per user (tidak boleh ada tag dengan nama yang sama untuk user ini).
             */
            'name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                Rule::unique('contact_tags')
                    ->where('user_id', (int) $this->user()?->id),
            ],

            /**
             * Warna hex untuk UI representation, opsional.
             * Format: #RRGGBB (6 hex digits setelah #)
             * Default: #607D8B (grey-blue)
             * Regex: /^#[0-9A-Fa-f]{6}$/
             */
            'color' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],

            /**
             * Icon name untuk UI representation, opsional.
             * Max 50 karakter.
             * Harus salah satu dari VALID_ICONS konstanta.
             */
            'icon' => [
                'nullable',
                'string',
                'max:50',
                Rule::in(self::VALID_ICONS),
            ],
        ];
    }

    /**
     * Configure validasi tambahan (custom validation).
     *
     * Cek: User belum mencapai batas 20 tag custom.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $user = $this->user();

            if (!$user) {
                return;
            }

            // Hitung custom tags (bukan system tags) untuk user ini
            $customTagCount = \App\Models\ContactTag::byUser($user)
                ->custom()
                ->count();

            // ─── Cek batas maksimal 20 tag custom ───────────────────────
            if ($customTagCount >= 20) {
                $validator->errors()->add(
                    'name',
                    'Batas maksimal 20 tag custom tercapai.',
                );
            }
        });
    }

    /**
     * Get custom pesan error untuk validasi.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama tag wajib diisi.',
            'name.string' => 'Nama tag harus berupa teks.',
            'name.min' => 'Nama tag minimal 2 karakter.',
            'name.max' => 'Nama tag maksimal 50 karakter.',
            'name.unique' => 'Anda sudah punya tag dengan nama ini.',
            'color.string' => 'Warna harus berupa teks.',
            'color.regex' => 'Warna harus format hex: #RRGGBB (contoh: #4CAF50).',
            'icon.string' => 'Icon harus berupa teks.',
            'icon.max' => 'Icon maksimal 50 karakter.',
            'icon.in' => 'Icon tidak valid. Pilih dari daftar icon yang tersedia.',
        ];
    }

    /**
     * Get list of valid icons untuk referensi frontend.
     *
     * @return array<string>
     */
    public static function validIcons(): array
    {
        return self::VALID_ICONS;
    }
}
