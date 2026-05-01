<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Tampilkan profil user yang sedang login.
     */
    public function show(Request $request): JsonResponse
    {
        // TODO: return UserResource
        return ApiResponse::success(
            $request->user()->only([
                'id', 'name', 'phone_number', 'badge_level',
                'contribution_points', 'is_premium', 'notification_preferences',
            ]),
            'Profil berhasil dimuat'
        );
    }

    /**
     * Perbarui profil user (partial update).
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::success(
            $request->validated(),
            'Profil berhasil diperbarui — implementasi Service pending'
        );
    }
}
