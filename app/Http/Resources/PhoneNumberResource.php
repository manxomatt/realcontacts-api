<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhoneNumberResource extends JsonResource
{
    // Nonaktifkan wrapper 'data' bawaan Laravel agar format
    // response dikelola sepenuhnya oleh with() di bawah
    public static $wrap = null;

    /**
     * Transformasi model PhoneNumber menjadi array response API.
     * Field internal (id, created_at raw, updated_at) tidak ditampilkan.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // ─── Data Nomor Telepon ───────────────────────────────

            // Selalu tampilkan dalam format internasional yang sudah dinormalisasi
            'phone_number'   => $this->phone_number,

            // Nama pemilik — tampilkan "Unknown" jika tidak diketahui
            'owner_name'     => $this->owner_name ?? 'Unknown',

            'operator_name'  => $this->operator_name,
            'country_code'   => $this->country_code,

            // ─── Data Spam ────────────────────────────────────────

            // Skor spam mentah 0-100
            'spam_score'     => $this->spam_score,

            // Level bahaya dari accessor: 'safe' | 'warning' | 'danger'
            'spam_level'     => $this->spam_level,

            // Derived field — true jika skor >= 70 (threshold berbahaya)
            'is_spam'        => $this->spam_score >= 70,

            // ─── Metadata ─────────────────────────────────────────

            // Kategori nomor: personal | business | spam | telemarketing | unknown
            'category'       => $this->category ?? 'unknown',

            'is_verified'    => $this->is_verified,

            // Jumlah total laporan yang diterima nomor ini
            'total_reports'  => $this->total_reports,

            // Waktu relatif, lebih ramah dibaca daripada timestamp mentah
            'last_updated'   => $this->last_updated?->diffForHumans(),

            // ─── Relasi (hanya muncul jika di-eager load) ─────────

            // Hindari N+1: hanya sertakan jika withLoad('spamReports') dipanggil
            // Bungkus dalam whenLoaded closure agar MissingValue tidak dikirim ke ::collection()
            'spam_reports'   => $this->whenLoaded(
                'spamReports',
                fn($reports) => SpamReportResource::collection($reports)
            ),

            // Hanya sertakan jika withLoad('businessProfile') dipanggil
            // Bungkus dalam whenLoaded closure agar MissingValue tidak masuk ke constructor
            'business_profile' => $this->whenLoaded(
                'businessProfile',
                fn($profile) => new BusinessProfileResource($profile)
            ),
        ];
    }

    /**
     * Data tambahan yang disertakan di top-level response.
     * Memenuhi format standar RealContacts: {success, data, message}.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Data nomor telepon berhasil diambil',
        ];
    }

    /**
     * Bungkus data resource dalam key 'data' secara manual
     * agar konsisten dengan format standar.
     *
     * @return array<string, mixed>
     */
    public function toResponse($request): mixed
    {
        // Bungkus toArray() dalam key 'data' sesuai format standar RealContacts
        return response()->json([
            'success' => true,
            'data'    => $this->toArray($request),
            'message' => 'Data nomor telepon berhasil diambil',
        ]);
    }
}

