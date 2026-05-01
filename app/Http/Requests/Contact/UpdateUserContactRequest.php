<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateUserContactRequest
 *
 * Validasi request untuk update kontak yang sudah ada.
 *
 * Semua field opsional (partial update support).
 * Tag IDs akan di-replace (bukan ditambah).
 *
 * Authorization:
 *   - Cek kontak belongs to authenticated user
 */
class UpdateUserContactRequest extends FormRequest
{
    /**
     * Determine jika user authorized untuk update kontak ini.
     *
     * Pastikan kontak yang akan di-update milik user yang login.
     */
    public function authorize(): bool
    {
        $contact = $this->route('contact');

        if (!$contact) {
            return false;
        }

        // Cek user_id kontak sama dengan authenticated user
        return (int) $contact->user_id === (int) $this->user()?->id;
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
             * Nama custom, opsional.
             * Kirim null untuk menghapus nama custom.
             * String, 2-100 karakter jika diisi.
             */
            'custom_name' => ['nullable', 'string', 'min:2', 'max:100'],

            /**
             * Catatan, opsional.
             * Kirim null untuk menghapus catatan.
             * Max 500 karakter jika diisi.
             */
            'notes' => ['nullable', 'string', 'max:500'],

            /**
             * Status favorit, opsional boolean.
             */
            'is_favorite' => ['nullable', 'boolean'],

            /**
             * Array of tag IDs.
             * Jika dikirim, akan menggantikan semua tag yang ada.
             * Opsional jika tidak ingin update tags.
             */
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'integer',
                // Setiap tag harus exist dan milik user
                \Illuminate\Validation\Rule::exists('contact_tags', 'id')
                    ->where('user_id', (int) $this->user()?->id),
            ],
        ];
    }

    /**
     * Prepare data untuk validasi.
     *
     * Trim custom_name dan notes.
     */
    protected function prepareForValidation(): void
    {
        // Trim custom_name
        if ($this->has('custom_name')) {
            $trimmed = trim($this->input('custom_name') ?? '');
            $this->merge([
                'custom_name' => $trimmed ?: null,
            ]);
        }

        // Trim notes
        if ($this->has('notes')) {
            $trimmed = trim($this->input('notes') ?? '');
            $this->merge([
                'notes' => $trimmed ?: null,
            ]);
        }
    }

    /**
     * Get custom pesan error untuk validasi.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_name.string' => 'Nama kontak harus berupa teks.',
            'custom_name.min' => 'Nama kontak minimal 2 karakter.',
            'custom_name.max' => 'Nama kontak maksimal 100 karakter.',
            'notes.string' => 'Catatan harus berupa teks.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
            'is_favorite.boolean' => 'Status favorit harus boolean (true/false).',
            'tag_ids.array' => 'Tag IDs harus berupa array.',
            'tag_ids.*.integer' => 'Setiap tag ID harus berupa integer.',
            'tag_ids.*.exists' => 'Beberapa tag tidak ditemukan atau bukan milik Anda.',
        ];
    }
}
