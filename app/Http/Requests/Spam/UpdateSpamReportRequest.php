<?php

declare(strict_types=1);

namespace App\Http\Requests\Spam;

use App\Models\SpamReport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateSpamReportRequest extends FormRequest
{
    // ─── Otorisasi ─────────────────────────────────────────────────────────────

    /**
     * Hanya admin yang boleh mengubah status laporan spam.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->is_admin === true;
    }

    protected function failedAuthorization(): never
    {
        // Bedakan antara belum login vs. bukan admin
        if ($this->user() === null) {
            throw new HttpResponseException(
                \App\Http\Responses\ApiResponse::unauthorized(
                    'Silakan login untuk mengakses fitur ini.'
                )
            );
        }

        throw new HttpResponseException(
            \App\Http\Responses\ApiResponse::forbidden(
                'Anda tidak memiliki akses untuk moderasi laporan.'
            )
        );
    }

    // ─── Validasi ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ─── Status moderasi ───────────────────────────────────────────────
            'status' => [
                'required',
                'string',
                'in:verified,rejected',
            ],

            // ─── Alasan penolakan ──────────────────────────────────────────────
            // Wajib diisi hanya jika status = 'rejected'
            'rejection_reason' => [
                'required_if:status,rejected',
                'nullable',
                'string',
                'min:10',
                'max:255',
            ],

            // ─── Catatan moderator ─────────────────────────────────────────────
            'moderator_notes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Hook withValidator() — validasi state laporan sebelum diproses.
     * Laporan yang sudah verified/rejected tidak bisa dimodifikasi lagi.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Lewati jika ada error field sebelumnya
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Ambil model via route model binding (key bisa 'spamReport' atau 'spam_report')
            /** @var SpamReport|null $report */
            $report = $this->route('spamReport') ?? $this->route('spam_report');

            if ($report === null) {
                return;
            }

            // Laporan yang sudah final tidak bisa diproses ulang
            if ($report->status !== 'pending') {
                $v->errors()->add(
                    'status',
                    'Laporan ini sudah diproses sebelumnya dan tidak dapat diubah lagi.'
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
            // ─── status ────────────────────────────────────────────────────────
            'status.required' => 'Status moderasi wajib dipilih.',
            'status.in'       => 'Status tidak valid. Pilih: verified atau rejected.',

            // ─── rejection_reason ──────────────────────────────────────────────
            'rejection_reason.required_if' => 'Alasan penolakan wajib diisi ketika laporan ditolak.',
            'rejection_reason.min'         => 'Alasan penolakan minimal 10 karakter.',
            'rejection_reason.max'         => 'Alasan penolakan maksimal 255 karakter.',

            // ─── moderator_notes ───────────────────────────────────────────────
            'moderator_notes.max' => 'Catatan moderator maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status'           => 'status moderasi',
            'rejection_reason' => 'alasan penolakan',
            'moderator_notes'  => 'catatan moderator',
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
