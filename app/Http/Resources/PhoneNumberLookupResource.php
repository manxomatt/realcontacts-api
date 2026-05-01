<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk endpoint GET /api/phone/{number}.
 * Menampilkan data lengkap hasil lookup nomor telepon.
 */
class PhoneNumberLookupResource extends JsonResource
{
    // Matikan wrapper 'data' bawaan — dikelola manual di toResponse()
    public static $wrap = null;

    /**
     * Transformasi model PhoneNumber ke struktur lookup lengkap.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // PHP 8.5 pipe operator |> untuk normalisasi tampilan nomor
            // Pastikan selalu dalam format +62xxx meski data sudah bersih
            'phone_number' => $this->phone_number
                |> (fn(string $n): string => preg_replace('/[^\d+]/', '', $n))
                |> (fn(string $n): string => str_starts_with($n, '0')
                    ? '+62' . substr($n, 1)
                    : $n)
                |> (fn(string $n): string => str_starts_with($n, '62') && ! str_starts_with($n, '+')
                    ? '+' . $n
                    : $n),

            // Nama pemilik — null jika tidak diketahui, ditampilkan apa adanya
            'owner_name' => $this->whenNotNull($this->owner_name),

            // ─── Operator ──────────────────────────────────────────────────────
            'operator' => [
                'name' => $this->operator_name ?? 'Unknown',
                // Tentukan tipe jaringan dari nama operator
                'type' => $this->resolveOperatorType($this->operator_name),
            ],

            // ─── Info Spam ─────────────────────────────────────────────────────
            'spam_info' => [
                'score'    => $this->spam_score,

                // Dari accessor spamLevel() di model PhoneNumber
                'level'    => $this->spam_level,

                // Kategori nomor: personal|business|spam|telemarketing|unknown
                'category' => $this->category ?? 'unknown',

                'total_reports' => $this->total_reports,

                // PHP 8.5 array_first() — kategori laporan terbanyak tanpa loop
                // Dihitung dari koleksi spamReports yang sudah di-eager load
                'dominant_category' => $this->whenLoaded(
                    'spamReports',
                    fn () => array_first(
                        $this->spamReports
                            ->groupBy('report_type')
                            ->sortByDesc(fn ($group) => $group->count())
                            ->keys()
                            ->toArray()
                    )
                ),
            ],

            // ─── Profil Bisnis (opsional) ──────────────────────────────────────
            // whenLoaded() mencegah N+1: hanya muncul jika ->with('businessProfile') dipanggil
            'business_profile' => $this->whenLoaded(
                'businessProfile',
                fn () => $this->businessProfile
                    ? new BusinessProfileResource($this->businessProfile)
                    : null
            ),

            // ─── Data Komunitas ────────────────────────────────────────────────
            'community' => [
                // total_reports sebagai proxy jumlah interaksi komunitas
                'total_lookups'    => $this->total_reports,

                // Waktu laporan terakhir dari koleksi yang sudah di-eager load
                // Gunakan whenLoaded() agar tidak trigger query baru
                'last_reported_at' => $this->whenLoaded(
                    'spamReports',
                    fn () => $this->spamReports->sortByDesc('created_at')
                        ->first()
                        ?->created_at
                        ?->diffForHumans()
                ),
            ],

            // ─── Meta ──────────────────────────────────────────────────────────
            'meta' => [
                // Field opsional — hanya muncul jika tidak null
                'is_whatsapp_registered' => $this->whenNotNull(
                    // Kolom ini belum ada di schema; null jika data tidak tersedia
                    $this->whatsapp_registered ?? null
                ),

                'is_verified'  => $this->is_verified,

                // Nama negara berdasarkan country_code
                'country'      => $this->resolveCountryName($this->country_code),
                'country_code' => $this->country_code,
            ],
        ];
    }

    /**
     * Top-level wrapper sesuai format standar RealContacts.
     *
     * @return array<string, mixed>
     */
    public function toResponse($request): mixed
    {
        return response()->json([
            'success' => true,
            'data'    => $this->toArray($request),
            'message' => 'Lookup nomor telepon berhasil',
        ]);
    }

    // ─── Helper Methods ────────────────────────────────────────────────────────

    // Tentukan tipe jaringan operator berdasarkan nama
    private function resolveOperatorType(?string $operatorName): string
    {
        return match (true) {
            str_contains((string) $operatorName, 'Smartfren') => 'CDMA/LTE',
            $operatorName === null                             => 'Unknown',
            default                                            => 'GSM',
        };
    }

    // Petakan kode negara ke nama negara Indonesia
    private function resolveCountryName(?string $countryCode): string
    {
        return match ($countryCode) {
            '+62'  => 'Indonesia',
            '+65'  => 'Singapore',
            '+60'  => 'Malaysia',
            '+1'   => 'United States',
            '+44'  => 'United Kingdom',
            '+61'  => 'Australia',
            default => 'Unknown',
        };
    }
}

