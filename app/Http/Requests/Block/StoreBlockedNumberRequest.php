<?php

declare(strict_types=1);

namespace App\Http\Requests\Block;

use App\Models\BlockedNumber;
use App\Models\PhoneNumber;
use App\Rules\PhoneNumberFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreBlockedNumberRequest extends FormRequest
{
    // Batas maksimal nomor yang dapat diblokir per user
    private const MAX_BLOCKED = 500;

    // ─── Otorisasi ─────────────────────────────────────────────────────────────

    /**
     * Hanya user yang sudah login yang dapat memblokir nomor.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::unauthorized(
                'Silakan login untuk memblokir nomor telepon.'
            )
        );
    }

    // ─── Normalisasi ───────────────────────────────────────────────────────────

    /**
     * Normalisasi input sebelum validasi:
     * - phone_number: konversi ke format internasional
     * - block_type: default ke 'manual' jika tidak diisi
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone_number');
        if ($phone !== null) {
            $this->merge([
                'phone_number' => PhoneNumberFormat::normalize((string) $phone),
            ]);
        }

        // Isi default block_type jika tidak dikirim
        if ($this->missing('block_type') || $this->input('block_type') === null) {
            $this->merge(['block_type' => 'manual']);
        }
    }

    // ─── Validasi ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ─── Nomor yang diblokir ───────────────────────────────────────────
            'phone_number' => [
                'required',
                'string',
                new PhoneNumberFormat(),
            ],

            // ─── Alasan pemblokiran ────────────────────────────────────────────
            'reason' => [
                'nullable',
                'string',
                'max:255',
            ],

            // ─── Tipe pemblokiran ──────────────────────────────────────────────
            'block_type' => [
                'nullable',
                'string',
                'in:manual,category,schedule',
            ],

            // ─── Kategori — wajib jika block_type = 'category' ────────────────
            'category' => [
                'required_if:block_type,category',
                'nullable',
                'string',
                'in:telemarketing,spam,unknown,international',
            ],

            // ─── Jadwal — wajib jika block_type = 'schedule' ──────────────────
            'schedule'            => [
                'required_if:block_type,schedule',
                'nullable',
                'array',
            ],
            'schedule.start_time' => [
                'required_with:schedule',
                'string',
                // Format HH:mm (00:00 – 23:59)
                'regex:/^([01]\d|2[0-3]):([0-5]\d)$/',
            ],
            'schedule.end_time'   => [
                'required_with:schedule',
                'string',
                'regex:/^([01]\d|2[0-3]):([0-5]\d)$/',
            ],
            // days opsional — default semua hari jika tidak diisi
            'schedule.days'       => [
                'nullable',
                'array',
                'min:1',
                'max:7',
            ],
            'schedule.days.*'     => [
                'string',
                'in:mon,tue,wed,thu,fri,sat,sun',
            ],
        ];
    }

    /**
     * Hook withValidator() — cek bisnis rules setelah field lulus validasi.
     * 1. User tidak boleh memblokir nomornya sendiri
     * 2. Batas maksimal 500 nomor per user
     * 3. Nomor belum ada di blocklist user
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Lewati jika ada error field sebelumnya
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $user  = $this->user();
            $phone = $this->input('phone_number');

            // ── Cek 1: User tidak boleh memblokir nomornya sendiri ────────────
            if ($user->phone_number !== null) {
                $ownNormalized = PhoneNumberFormat::normalize($user->phone_number);
                if ($ownNormalized === $phone) {
                    $v->errors()->add(
                        'phone_number',
                        'Anda tidak dapat memblokir nomor Anda sendiri.'
                    );
                    return;
                }
            }

            // ── Cek 2: Batas maksimal 500 nomor diblokir ─────────────────────
            $totalBlocked = BlockedNumber::where('user_id', $user->id)->count();
            if ($totalBlocked >= self::MAX_BLOCKED) {
                $v->errors()->add(
                    'phone_number',
                    'Batas maksimal ' . self::MAX_BLOCKED . ' nomor yang dapat diblokir telah tercapai.'
                );
                return;
            }

            // ── Cek 3: Nomor belum ada di blocklist user ──────────────────────
            $phoneRecord = PhoneNumber::where('phone_number', $phone)->first();

            if ($phoneRecord !== null) {
                $sudahDiblokir = BlockedNumber::where('user_id', $user->id)
                    ->where('phone_number_id', $phoneRecord->id)
                    ->exists();

                if ($sudahDiblokir) {
                    $v->errors()->add(
                        'phone_number',
                        'Nomor ini sudah ada di daftar blokir Anda.'
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
            // ─── phone_number ──────────────────────────────────────────────────
            'phone_number.required' => 'Nomor telepon yang akan diblokir wajib diisi.',

            // ─── reason ────────────────────────────────────────────────────────
            'reason.max' => 'Alasan pemblokiran maksimal 255 karakter.',

            // ─── block_type ────────────────────────────────────────────────────
            'block_type.in' => 'Tipe blokir tidak valid. Pilih: manual, category, atau schedule.',

            // ─── category ──────────────────────────────────────────────────────
            'category.required_if' => 'Kategori wajib dipilih ketika tipe blokir adalah category.',
            'category.in'          => 'Kategori tidak valid. Pilih: telemarketing, spam, unknown, atau international.',

            // ─── schedule ──────────────────────────────────────────────────────
            'schedule.required_if'            => 'Jadwal wajib diisi ketika tipe blokir adalah schedule.',
            'schedule.array'                  => 'Format jadwal tidak valid, harus berupa objek.',
            'schedule.start_time.required_with' => 'Waktu mulai wajib diisi pada jadwal.',
            'schedule.start_time.regex'         => 'Format waktu mulai tidak valid. Gunakan format HH:mm (contoh: 22:00).',
            'schedule.end_time.required_with'   => 'Waktu selesai wajib diisi pada jadwal.',
            'schedule.end_time.regex'           => 'Format waktu selesai tidak valid. Gunakan format HH:mm (contoh: 07:00).',
            'schedule.days.array'               => 'Daftar hari harus berupa array.',
            'schedule.days.min'                 => 'Pilih minimal 1 hari.',
            'schedule.days.max'                 => 'Maksimal 7 hari yang dapat dipilih.',
            'schedule.days.*.in'                => 'Nilai hari tidak valid. Gunakan: mon, tue, wed, thu, fri, sat, atau sun.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone_number'        => 'nomor telepon',
            'reason'              => 'alasan pemblokiran',
            'block_type'          => 'tipe blokir',
            'category'            => 'kategori blokir',
            'schedule'            => 'jadwal blokir',
            'schedule.start_time' => 'waktu mulai',
            'schedule.end_time'   => 'waktu selesai',
            'schedule.days'       => 'hari aktif',
            'schedule.days.*'     => 'hari',
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
