<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Collection untuk daftar nomor yang diblokir user.
 * Setiap item di-wrap oleh BlockedNumberResource.
 */
class BlockedNumberCollection extends ResourceCollection
{
    // Resource yang di-wrap oleh collection ini
    public $collects = BlockedNumberResource::class;

    /**
     * Override format pagination bawaan Laravel.
     *
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'total'        => $paginated['total'],
                'per_page'     => $paginated['per_page'],
                'total_pages'  => $paginated['last_page'],
            ],
            'links' => [
                'next' => $paginated['next_page_url'],
                'prev' => $paginated['prev_page_url'],
            ],
        ];
    }

    /**
     * Data tambahan di level yang sama dengan 'data'.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Daftar blokir berhasil diambil',
        ];
    }
}
