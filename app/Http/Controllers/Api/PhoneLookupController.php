<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Phone\BatchPhoneLookupRequest;
use App\Http\Requests\Phone\PhoneLookupRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class PhoneLookupController extends Controller
{
    /**
     * Lookup satu nomor telepon.
     */
    public function show(PhoneLookupRequest $request, string $number): JsonResponse
    {
        // TODO: implementasi PhoneLookupService
        return ApiResponse::success(
            ['normalized' => $request->normalizedNumber()],
            'Lookup berhasil — implementasi Service pending'
        );
    }

    /**
     * Lookup banyak nomor sekaligus (premium only).
     */
    public function batch(BatchPhoneLookupRequest $request): JsonResponse
    {
        // TODO: implementasi PhoneLookupService
        return ApiResponse::success(
            ['numbers' => $request->normalizedNumbers()],
            'Batch lookup berhasil — implementasi Service pending'
        );
    }
}
