<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateBusinessProfileRequest;
use App\Http\Responses\ApiResponse;
use App\Models\BusinessProfile;
use Illuminate\Http\JsonResponse;

class BusinessProfileController extends Controller
{
    /**
     * Tampilkan profil bisnis.
     */
    public function show(BusinessProfile $businessProfile): JsonResponse
    {
        // TODO: return BusinessProfileResource
        return ApiResponse::success(
            $businessProfile->only([
                'id', 'business_name', 'category', 'address',
                'operating_hours', 'maps_url', 'is_verified', 'is_claimed',
            ]),
            'Profil bisnis berhasil dimuat'
        );
    }

    /**
     * Perbarui profil bisnis (partial update — hanya pemilik).
     */
    public function update(UpdateBusinessProfileRequest $request, BusinessProfile $businessProfile): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::success(
            $request->validated(),
            'Profil bisnis berhasil diperbarui — implementasi Service pending'
        );
    }
}
