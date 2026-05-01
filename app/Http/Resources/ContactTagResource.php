<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ContactTagResource
 *
 * API resource untuk merepresentasikan contact tag dengan informasi
 * lengkap tentang penggunaan dan status deletion protection.
 *
 * Field 'can_delete' memudahkan Flutter menentukan UI (show/hide
 * delete button) tanpa perlu logic di frontend.
 *
 * Fields yang disembunyikan: id, user_id, timestamps
 */
class ContactTagResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Tag ID untuk referensi di operasi tagging.
             */
            'id' => $this->id,

            /**
             * Nama tag yang ditampilkan ke user.
             * Contoh: 'Pelanggan', 'Supplier', 'VIP Clients'
             */
            'name' => $this->name,

            /**
             * Warna hex untuk UI representation.
             * Default '#9E9E9E' (abu-abu) jika null.
             * Format: #RRGGBB, contoh: '#4CAF50', '#2196F3'
             */
            'color' => $this->color ?? '#9E9E9E',

            /**
             * Icon name untuk UI representation.
             * Default 'label' jika null.
             * Gunakan icon set yang sama dengan Flutter client.
             */
            'icon' => $this->icon ?? 'label',

            /**
             * Apakah ini system tag (tidak bisa dihapus).
             * True untuk: Pelanggan, Supplier, Keluarga, Rekan Kerja, Penting, Junk
             * False untuk custom tags yang dibuat user.
             */
            'is_system' => (bool) $this->is_system,

            /**
             * Berapa kontak yang pakai tag ini.
             * Membantu user understand tag importance.
             * Increment saat tag ditambah ke kontak.
             * Decrement saat tag dihapus dari kontak.
             */
            'usage_count' => (int) $this->usage_count,

            /**
             * Apakah tag ini bisa dihapus.
             * True jika is_system = false (custom tag)
             * False jika is_system = true (system tag, protected)
             *
             * Flutter menggunakan flag ini untuk:
             *   - Show/hide delete button
             *   - Show tooltip "System tag cannot be deleted"
             *   - Disable delete action
             */
            'can_delete' => !$this->is_system,
        ];
    }
}
