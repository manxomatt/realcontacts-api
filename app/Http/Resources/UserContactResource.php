<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserContactResource
 *
 * API resource untuk merepresentasikan user contact dengan detail
 * lengkap termasuk tags, phone info, dan metadata interaksi.
 *
 * Format output memilih display_name (accessor) untuk privacy,
 * menggunakan whenLoaded() untuk menghindari N+1 queries.
 *
 * Fields yang disembunyikan: id, user_id, timestamps
 */
class UserContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'phone_number' => $this->phone_number,

            /**
             * Display name dengan priority:
             *   1. custom_name (user-given)
             *   2. owner_name dari PhoneNumber (if business)
             *   3. masked phone (+6281****7890)
             *
             * Ini accessor dari UserContact model, bukan raw field.
             */
            'display_name' => $this->display_name,

            /**
             * Custom name yang user berikan (optional).
             * Null jika user belum memberikan nama custom.
             */
            'custom_name' => $this->custom_name,

            /**
             * Catatan privat tentang kontak (optional).
             */
            'notes' => $this->notes,

            /**
             * Apakah kontak ini di-favorite.
             */
            'is_favorite' => (bool) $this->is_favorite,

            /**
             * Sumber kontak: 'manual' | 'phone_book' | 'call_log'
             */
            'contact_source' => $this->contact_source,

            /**
             * Waktu interaksi terakhir dalam format human-readable.
             * Contoh: '2 hours ago', '3 days ago', null jika belum pernah.
             */
            'last_interacted_at' => $this->last_interacted_at?->diffForHumans(),

            /**
             * Tag-tag yang diterapkan ke kontak ini.
             * Gunakan whenLoaded() untuk menghindari N+1 queries.
             *
             * Jika relationship tidak di-load, field ini tidak muncul.
             * Load dengan: UserContact::with('tags')->get()
             */
            'tags' => ContactTagResource::collection(
                $this->whenLoaded('tags')
            ),

            /**
             * Informasi nomor telepon dari PhoneNumber model.
             * Termasuk: spam_level, operator, is_verified, dll.
             *
             * Gunakan whenLoaded() untuk menghindari N+1 queries.
             * Load dengan: UserContact::with('phoneNumber')->get()
             */
            'phone_info' => $this->whenLoaded('phoneNumber', function (): array|null {
                return $this->phoneNumber ? [
                    'spam_level' => $this->phoneNumber->spam_level,
                    'operator' => $this->phoneNumber->operator,
                    'is_verified' => (bool) $this->phoneNumber->is_verified,
                ] : null;
            }),

            /**
             * Tanggal kontak ditambahkan, format: 'd M Y'
             * Contoh: '15 Jan 2025', '01 May 2026'
             */
            'added_at' => $this->created_at->format('d M Y'),
        ];
    }
}
