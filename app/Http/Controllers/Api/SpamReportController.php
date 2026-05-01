<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Spam\StoreSpamReportRequest;
use App\Http\Requests\Spam\UpdateSpamReportRequest;
use App\Http\Responses\ApiResponse;
use App\Models\SpamReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpamReportController extends Controller
{
    /**
     * Daftar laporan spam.
     */
    public function index(Request $request): JsonResponse
    {
        // TODO: implementasi repository + SpamReportCollection
        return ApiResponse::success([], 'Daftar laporan — implementasi pending');
    }

    /**
     * Kirim laporan spam baru.
     */
    public function store(StoreSpamReportRequest $request): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::created(
            $request->validatedWithReporter(),
            'Laporan spam berhasil dikirim — implementasi Service pending'
        );
    }

    /**
     * Approve / reject laporan (admin only).
     */
    public function update(UpdateSpamReportRequest $request, SpamReport $spamReport): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::success(
            $request->validated(),
            'Laporan berhasil dimoderasi — implementasi Service pending'
        );
    }
}
