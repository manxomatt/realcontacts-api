<?php

declare(strict_types=1);

namespace App\Http\Resources\Collections;

use App\Http\Resources\ContactTagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * ContactTagCollection
 *
 * Resource collection untuk contact tags dengan summary statistics.
 *
 * Output format:
 * {
 *   "summary": {
 *     "total_tags": int,
 *     "system_tags": int,
 *     "custom_tags": int,
 *     "most_used_tag": string | null
 *   },
 *   "data": [ContactTagResource, ...]
 * }
 *
 * Berguna untuk:
 *   - Dashboard: lihat stats tag usage
 *   - Tag manager: organize dan delete tags
 *   - Analytics: track tag adoption rate
 */
class ContactTagCollection extends ResourceCollection
{
    /**
     * Resource class yang digunakan untuk wrapping items.
     *
     * @var string
     */
    public $collects = ContactTagResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /**
         * Hitung summary statistics dari collection.
         */
        $total = $this->collection->count();

        /**
         * Filter system tags.
         */
        $systemTags = $this->collection->filter(
            fn ($tag) => $tag->is_system === true
        );

        /**
         * Filter custom tags.
         */
        $customTags = $this->collection->filter(
            fn ($tag) => $tag->is_system === false
        );

        /**
         * Cari most_used_tag menggunakan Laravel collection methods
         * setelah sorting by usage_count DESC.
         *
         * PHP 8.5 array_first() tidak cocok untuk ini, jadi gunakan
         * Laravel collection ->first() dengan callback filter.
         *
         * Jika collection kosong atau semua usage_count = 0, return null.
         */
        $mostUsedTag = $this->collection
            ->sortByDesc('usage_count')
            ->first(fn ($tag) => $tag->usage_count > 0)?->name ?? null;

        return [
            /**
             * Summary statistics untuk dashboard/analytics.
             */
            'summary' => [
                'total_tags' => $total,
                'system_tags' => $systemTags->count(),
                'custom_tags' => $customTags->count(),
                'most_used_tag' => $mostUsedTag,
            ],

            /**
             * Array of ContactTagResource items.
             * Automatic wrapping by ResourceCollection.
             */
            'data' => $this->collection,
        ];
    }
}
