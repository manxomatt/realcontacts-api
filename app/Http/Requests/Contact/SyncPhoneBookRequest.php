<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use App\Rules\PhoneNumberFormat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SyncPhoneBookRequest
 *
 * Validasi request untuk sync kontak dari phone book ke backend.
 *
 * Flutter mengirim daftar kontak dari phone dalam batch.
 * Request ini mem-validate dan men-deduplicate sebelum diproses.
 *
 * Proses:
 *   1. rules() - validasi struktur
 *   2. withValidator() - deduplicate phone numbers
 */
class SyncPhoneBookRequest extends FormRequest
{
    /**
     * Determine jika user authorized untuk sync kontak.
     */
    public function authorize(): bool
    {
        // User yang sudah authenticated bisa sync
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
             * Daftar kontak yang akan di-sync, wajib diisi.
             * Array dengan min 1 item, max 500 item per request.
             */
            'contacts' => ['required', 'array', 'min:1', 'max:500'],

            /**
             * Nomor telepon untuk setiap kontak.
             * Wajib, string, format valid (gunakan PhoneNumberFormat rule).
             */
            'contacts.*.phone_number' => [
                'required',
                'string',
                new PhoneNumberFormat(),
            ],

            /**
             * Nama kontak dari phone book.
             * Wajib, string, max 100 karakter.
             */
            'contacts.*.name' => ['required', 'string', 'max:100'],

            /**
             * Waktu interaksi terakhir, opsional.
             * Format ISO 8601 (contoh: 2026-05-01T13:00:37Z, 2026-05-01 13:00:37).
             */
            'contacts.*.last_interacted_at' => [
                'nullable',
                'date_format:Y-m-d H:i:s|Y-m-dTH:i:sZ',
            ],
        ];
    }

    /**
     * Configure validasi tambahan (custom validation).
     *
     * Deduplicate: Hapus item duplikat berdasarkan phone_number.
     * Kirim hanya item pertama jika ada duplikat.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // Cek ada duplikat phone_number dalam request
            $contacts = $this->input('contacts', []);

            if (!is_array($contacts) || empty($contacts)) {
                return;
            }

            $phoneNumbers = [];
            $duplicates = [];

            foreach ($contacts as $index => $contact) {
                if (!isset($contact['phone_number'])) {
                    continue;
                }

                $phone = (string) $contact['phone_number'];

                if (in_array($phone, $phoneNumbers, strict: true)) {
                    // Phone number sudah ada, mark as duplicate
                    $duplicates[] = $index;
                } else {
                    $phoneNumbers[] = $phone;
                }
            }

            // ─── Deduplicate: hapus dari input ──────────────────────────
            if (!empty($duplicates)) {
                // Sort descending agar index valid saat delete
                rsort($duplicates, sort_flags: SORT_NUMERIC);

                $contacts = $this->input('contacts', []);
                foreach ($duplicates as $index) {
                    unset($contacts[$index]);
                }

                // Re-index array (hapus gaps)
                $this->merge([
                    'contacts' => array_values($contacts),
                ]);

                // Info: dedup dilakukan, tidak error
                // Tapi bisa log ke validator.addWarning() jika tersedia
                // Untuk sekarang, silent dedup
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
            'contacts.required' => 'Daftar kontak wajib diisi.',
            'contacts.array' => 'Daftar kontak harus berupa array.',
            'contacts.min' => 'Minimal 1 kontak harus dikirim.',
            'contacts.max' => 'Maksimal 500 kontak per request. Kirim dalam batch terpisah jika lebih.',
            'contacts.*.phone_number.required' => 'Nomor telepon wajib diisi untuk setiap kontak.',
            'contacts.*.phone_number.string' => 'Nomor telepon harus berupa teks.',
            'contacts.*.name.required' => 'Nama kontak wajib diisi.',
            'contacts.*.name.string' => 'Nama kontak harus berupa teks.',
            'contacts.*.name.max' => 'Nama kontak maksimal 100 karakter.',
            'contacts.*.last_interacted_at.date_format' => 'Format tanggal interaksi tidak valid. Gunakan ISO 8601 (YYYY-MM-DD HH:mm:ss).',
        ];
    }
}
