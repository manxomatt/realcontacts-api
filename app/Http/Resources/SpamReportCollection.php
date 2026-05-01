<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Collection untuk daftar laporan spam dengan pagination kustom + summary.
 * Setiap item di-wrap oleh SpamReportResource.
 */
class SpamReportCollection extends ResourceCollection
{
    // Resource yang di-wrap oleh collection ini
    public $collects = SpamReportResource::class;

    /**
     * Override format pagination bawaan Laravel.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
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
     * Berisi summary laporan + standar success/message RealContacts.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'summary' => $this->computeSummary(),
            'message' => 'Daftar laporan spam berhasil diambil',
        ];
    }

    // ─── Helper ────────────────────────────────────────────────────────────────

    /**
     * Hitung ringkasan laporan dari item halaman saat ini.
     *
     * Catatan: total_reports dihitung dari paginator (lintas halaman),
     * verified_reports dan dominant_category dari item halaman aktif.
     *
     * @return array<string, mixed>
     */
    private function computeSummary(): array
    {
        // Ambil model Eloquent dari dalam setiap SpamReportResource
        $reports = $this->collection->map(fn($resource) => $resource->resource);

        // Total laporan seluruh halaman — gunakan total() dari paginator jika tersedia
        $totalAll = method_exists($this->resource, 'total')
            ? $this->resource->total()
            : $reports->count();

        // Laporan terverifikasi dari halaman saat ini
        $verified = $reports->where('status', 'verified')->count();

        // Hitung frekuensi tiap report_type, urutkan descending
        $categoryCounts = $reports
            ->map(fn($r) => $r->report_type)
            ->countBy()
            ->sortDesc()
            ->all();

        // PHP 8.5 array_first() — kategori dengan laporan terbanyak di halaman ini
        $dominant = !empty($categoryCounts)
            ? array_first(array_keys($categoryCounts))
            : null;

        return [
            'total_reports'     => $totalAll,
            'verified_reports'  => $verified,
            'dominant_category' => $dominant,
        ];
    }
}
