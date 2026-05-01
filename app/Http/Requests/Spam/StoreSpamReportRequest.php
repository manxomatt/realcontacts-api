<?php

declare(strict_types=1);

namespace App\Http\Requests\Spam;

use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class StoreSpamReportRequest extends FormRequest
{
    // ─── Otorisasi ─────────────────────────────────────────────────────────────

    /**
     * Hanya user yang sudah login yang boleh melaporkan nomor.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::unauthorized(
                'Silakan login untuk melaporkan nomor telepon.'
            )
        );
    }

    // ─── Normalisasi ───────────────────────────────────────────────────────────

    /**
     * Normalisasi input sebelum validasi:
     * - phone_number: hapus spasi/strip, konversi ke format internasional
     * - description: trim whitespace
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone_number');
        if ($phone !== null) {
            $this->merge([
                'phone_number' => PhoneNumberFormat::normalize((string) $phone),
            ]);
        }

        $description = $this->input('description');
        if ($description !== null) {
            $this->merge([
                'description' => trim((string) $description),
            ]);
        }
    }

    // ─── Validasi ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ─── Nomor yang dilaporkan ─────────────────────────────────────────
            'phone_number' => [
                'required',
                'string',
                new PhoneNumberFormat(),
            ],

            // ─── Kategori laporan ──────────────────────────────────────────────
            // Harus sesuai nilai enum yang ada di tabel spam_reports
            // Catatan: fraud_prize & survey belum ada di DB schema — tambahkan
            // migration baru jika ingin menambah kategori ini.
            'category' => [
                'required',
                'string',
                'in:' . implode(',', SpamReport::REPORT_TYPES),
            ],

            // ─── Deskripsi ─────────────────────────────────────────────────────
            'description' => [
                'nullable',
                'string',
                'min:10',
                'max:500',
                // Cegah spam promosi — tolak jika mengandung URL
                'not_regex:/(https?:\/\/|www\.)[^\s]+/i',
            ],

            // ─── Bukti pendukung ───────────────────────────────────────────────
            // Metadata opsional — belum ada kolom DB, digunakan oleh controller
            // untuk menentukan flow upload jika diperlukan.
            'evidence_type' => [
                'nullable',
                'string',
                'in:call_recording,screenshot,personal_experience',
            ],
        ];
    }

    /**
     * Hook withValidator() — cek bisnis rules setelah validasi field lulus.
     * 1. Cek duplikat laporan dalam 24 jam terakhir
     * 2. Cek apakah nomor ada di whitelist (is_verified = true, spam_score = 0)
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Lewati jika ada error sebelumnya
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $user  = $this->user();
            $phone = $this->input('phone_number');

            // Cari phone_number_id berdasarkan nomor yang dinormalisasi
            $phoneRecord = PhoneNumber::where('phone_number', $phone)->first();

            if ($phoneRecord === null) {
                // Nomor belum di DB — izinkan (controller akan membuat record baru)
                return;
            }

            // ── Cek 1: Duplikat laporan dalam 24 jam ──────────────────────────
            $sudahLapor = SpamReport::where('user_id', $user->id)
                ->where('phone_number_id', $phoneRecord->id)
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if ($sudahLapor) {
                $v->errors()->add(
                    'phone_number',
                    'Anda sudah melaporkan nomor ini hari ini.'
                );
                return;
            }

            // ── Cek 2: Nomor sudah terverifikasi aman (whitelist) ─────────────
            // Kriteria safe: is_verified = true DAN spam_score = 0
            if ($phoneRecord->is_verified && $phoneRecord->spam_score === 0) {
                $v->errors()->add(
                    'phone_number',
                    'Nomor ini sudah terverifikasi aman dan tidak dapat dilaporkan.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $validCategories = implode(', ', SpamReport::REPORT_TYPES);

        return [
            // ─── phone_number ──────────────────────────────────────────────────
            'phone_number.required' => 'Nomor telepon yang dilaporkan wajib diisi.',
            'phone_number.string'   => 'Nomor telepon harus berupa teks.',

            // ─── category ──────────────────────────────────────────────────────
            'category.required' => 'Kategori laporan wajib dipilih.',
            'category.in'       => "Kategori tidak valid. Pilih salah satu: {$validCategories}.",

            // ─── description ───────────────────────────────────────────────────
            'description.min'       => 'Deskripsi laporan minimal 10 karakter.',
            'description.max'       => 'Deskripsi laporan maksimal 500 karakter.',
            'description.not_regex' => 'Deskripsi tidak boleh mengandung URL atau tautan.',

            // ─── evidence_type ─────────────────────────────────────────────────
            'evidence_type.in' => 'Tipe bukti tidak valid. Pilih: call_recording, screenshot, atau personal_experience.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone_number'  => 'nomor telepon',
            'category'      => 'kategori laporan',
            'description'   => 'deskripsi',
            'evidence_type' => 'tipe bukti',
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
     * Kembalikan data validated dengan tambahan reporter_user_id.
     * Shortcut untuk digunakan di Controller — menggantikan ->validated().
     *
     * @return array<string, mixed>
     */
    public function validatedWithReporter(): array
    {
        return array_merge($this->validated(), [
            'reporter_user_id' => $this->user()->id,
        ]);
    }
}
