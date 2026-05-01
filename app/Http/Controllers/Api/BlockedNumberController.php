<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Block\StoreBlockedNumberRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockedNumberController extends Controller
{
    /**
     * Daftar nomor yang diblokir oleh user yang sedang login.
     */
    public function index(Request $request): JsonResponse
    {
        // TODO: implementasi repository + resource
        return ApiResponse::success([], 'Daftar blokir — implementasi pending');
    }

    /**
     * Blokir nomor telepon baru.
     */
    public function store(StoreBlockedNumberRequest $request): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::success(
            $request->validated(),
            'Nomor berhasil diblokir — implementasi Service pending'
        );
    }

    /**
     * Hapus nomor dari daftar blokir.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::noContent('Nomor berhasil dihapus dari daftar blokir.');
    }
}
