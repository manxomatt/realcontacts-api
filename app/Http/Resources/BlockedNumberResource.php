<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk nomor yang diblokir oleh user.
 * TIDAK mengekspos: id, user_id, phone_number_id, raw timestamps.
 */
class BlockedNumberResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // ─── Nomor Telepon ─────────────────────────────────────────────────

            // Nomor dalam format internasional (+62...) — diambil dari relasi
            'phone_number' => $this->whenLoaded(
                'phoneNumber',
                fn() => $this->phoneNumber->phone_number
            ),

            // ─── Detail Blokir ─────────────────────────────────────────────────

            // Alasan user memblokir — null jika tidak disertakan
            'reason'     => $this->whenNotNull($this->reason),

            // Waktu relatif kapan pemblokiran dilakukan
            // Contoh: "Diblokir 3 hari yang lalu"
            'blocked_at' => $this->created_at
                ->locale('id')
                ->diffForHumans(parts: 1, prefix: 'Diblokir '),

            // ─── Info Nomor (opsional, hanya jika ->load('phoneNumber') dipanggil) ──

            // Tampilkan owner_name dan spam_level dari PhoneNumber
            // Menggunakan whenLoaded agar tidak memicu query tambahan (N+1)
            'phone_info' => $this->whenLoaded('phoneNumber', function () {
                $phone = $this->phoneNumber;

                return [
                    'owner_name'  => $phone->owner_name ?? 'Unknown',
                    'spam_level'  => $phone->spam_level->value, // Accessor: 'safe'|'warning'|'danger'
                    'operator'    => $phone->operator_name,
                    'is_verified' => $phone->is_verified,
                ];
            }),
        ];
    }

    /**
     * Top-level wrapper sesuai format standar RealContacts.
     */
    public function toResponse($request): mixed
    {
        return response()->json([
            'success' => true,
            'data'    => $this->toArray($request),
            'message' => 'Data nomor diblokir berhasil diambil',
        ]);
    }
}

