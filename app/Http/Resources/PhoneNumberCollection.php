<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Collection untuk daftar nomor telepon dengan pagination kustom.
 * Setiap item di-wrap oleh PhoneNumberResource.
 */
class PhoneNumberCollection extends ResourceCollection
{
    // Resource yang di-wrap oleh collection ini
    public $collects = PhoneNumberResource::class;

    /**
     * Override format pagination bawaan Laravel.
     * Menghasilkan meta + links dengan key yang lebih ringkas.
     *
     * @param  array<string, mixed>  $paginated  Data mentah dari paginator
     * @param  array<string, mixed>  $default    Format default Laravel (tidak digunakan)
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
     * Data tambahan yang di-merge di level yang sama dengan 'data'.
     * success + message memenuhi format standar RealContacts.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Daftar nomor telepon berhasil diambil',
        ];
    }
}
