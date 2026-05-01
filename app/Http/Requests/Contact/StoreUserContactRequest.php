<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use App\Rules\PhoneNumberFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreUserContactRequest
 *
 * Validasi request untuk membuat kontak baru di user_contacts.
 *
 * Proses:
 *   1. prepareForValidation() - normalisasi phone_number
 *   2. rules() - validasi basic
 *   3. withValidator() - validasi custom (duplicate, limit)
 */
class StoreUserContactRequest extends FormRequest
{
    /**
     * Determine jika user authorized untuk membuat request ini.
     */
    public function authorize(): bool
    {
        // User yang sudah authenticated bisa membuat kontak
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
             * Nomor telepon wajib, harus format valid.
             * Gunakan PhoneNumberFormat rule untuk validasi format.
             */
            'phone_number' => ['required', 'string', new PhoneNumberFormat()],

            /**
             * Nama custom yang diberi user, opsional.
             * Min 2, max 100 karakter. Tidak boleh hanya spasi (sudah di-trim).
             */
            'custom_name' => ['nullable', 'string', 'min:2', 'max:100'],

            /**
             * Catatan privat tentang kontak, opsional.
             * Max 500 karakter.
             */
            'notes' => ['nullable', 'string', 'max:500'],

            /**
             * Apakah kontak ini favorit.
             * Opsional, default false di model.
             */
            'is_favorite' => ['nullable', 'boolean'],

            /**
             * Sumber kontak: manual, phone_book, atau call_log.
             * Opsional, default 'manual' di model.
             */
            'contact_source' => [
                'nullable',
                Rule::in(['manual', 'phone_book', 'call_log']),
            ],

            /**
             * Array of tag IDs yang ingin diterapkan ke kontak.
             * Setiap tag harus exist di contact_tags dan milik user yang login.
             * Opsional jika tidak ada tag yang ingin ditambahkan saat create.
             */
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'integer',
                // Cek tag exists dan belongs to authenticated user
                Rule::exists('contact_tags', 'id')
                    ->where('user_id', (int) $this->user()?->id),
            ],
        ];
    }

    /**
     * Prepare data untuk validasi.
     *
     * Trim & normalize phone_number, custom_name untuk menghilangkan
     * extra whitespace sebelum validasi.
     */
    protected function prepareForValidation(): void
    {
        // Trim dan normalisasi phone_number
        if ($this->has('phone_number')) {
            $this->merge([
                'phone_number' => trim($this->input('phone_number')),
            ]);
        }

        // Trim custom_name, convert kosong ke null
        if ($this->has('custom_name')) {
            $trimmed = trim($this->input('custom_name') ?? '');
            $this->merge([
                'custom_name' => $trimmed ?: null,
            ]);
        }
    }

    /**
     * Configure validasi tambahan (custom validation).
     *
     * Cek:
     *   1. Nomor belum ada di user_contacts user ini
     *   2. User belum mencapai batas 5.000 kontak
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $user = $this->user();
            $phoneNumber = $this->input('phone_number');

            // ─── Cek 1: Duplikasi nomor untuk user ini ──────────────────
            if ($user) {
                $duplicate = \App\Models\UserContact::forUser($user)
                    ->where('phone_number', $phoneNumber)
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add(
                        'phone_number',
                        'Nomor ini sudah ada di daftar kontak Anda.',
                    );
                }
            }

            // ─── Cek 2: Batas maksimal 5.000 kontak per user ────────────
            if ($user) {
                $contactCount = \App\Models\UserContact::forUser($user)->count();

                if ($contactCount >= 5000) {
                    $validator->errors()->add(
                        'phone_number',
                        'Batas maksimal 5.000 kontak tercapai.',
                    );
                }
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
            'phone_number.required' => 'Nomor telepon wajib diisi.',
            'phone_number.string' => 'Nomor telepon harus berupa teks.',
            'custom_name.string' => 'Nama kontak harus berupa teks.',
            'custom_name.min' => 'Nama kontak minimal 2 karakter.',
            'custom_name.max' => 'Nama kontak maksimal 100 karakter.',
            'notes.string' => 'Catatan harus berupa teks.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
            'is_favorite.boolean' => 'Status favorit harus boolean (true/false).',
            'contact_source.in' => 'Sumber kontak harus salah satu dari: manual, phone_book, call_log.',
            'tag_ids.array' => 'Tag IDs harus berupa array.',
            'tag_ids.*.integer' => 'Setiap tag ID harus berupa integer.',
            'tag_ids.*.exists' => 'Beberapa tag tidak ditemukan atau bukan milik Anda.',
        ];
    }
}
