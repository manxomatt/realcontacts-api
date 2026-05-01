<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Helper untuk membuat JSON response yang konsisten di seluruh API RealContacts.
 *
 * Format standar:
 * {
 *   "success": true|false,
 *   "message": "...",
 *   "data": {}|null,
 *   "errors": {}|null   // hanya muncul saat error
 * }
 */
final class ApiResponse
{
    // ─── Success ───────────────────────────────────────────────────────────────

    /**
     * Response sukses generik.
     *
     * @param  JsonResource|ResourceCollection|Arrayable|array<mixed>|null  $data
     */
    public static function success(
        JsonResource|ResourceCollection|Arrayable|array|null $data = null,
        string $message = 'Berhasil',
        int $statusCode = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => self::resolveData($data),
        ], $statusCode);
    }

    /**
     * Response 201 Created — digunakan setelah menyimpan data baru.
     *
     * @param  JsonResource|ResourceCollection|Arrayable|array<mixed>|null  $data
     */
    public static function created(
        JsonResource|ResourceCollection|Arrayable|array|null $data = null,
        string $message = 'Data berhasil dibuat',
    ): JsonResponse {
        return self::success($data, $message, 201);
    }

    /**
     * Response 204 No Content — digunakan setelah menghapus data.
     * Body kosong sesuai standar HTTP 204.
     */
    public static function noContent(string $message = 'Data berhasil dihapus'): JsonResponse
    {
        // Tetap kembalikan body minimal agar klien mendapat konfirmasi
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => null,
        ], 200);
    }

    // ─── Error ────────────────────────────────────────────────────────────────

    /**
     * Response error generik.
     *
     * @param  array<string, mixed>|null  $errors  Detail error (misal: validation errors)
     */
    public static function error(
        string $message,
        int $statusCode = 500,
        array|null $errors = null,
    ): JsonResponse {
        $body = [
            'success' => false,
            'message' => $message,
            'data'    => null,
        ];

        // Field 'errors' hanya muncul jika ada isinya (hindari noise di response sukses)
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $statusCode);
    }

    /**
     * Response 404 Not Found.
     */
    public static function notFound(
        string $message = 'Data tidak ditemukan',
    ): JsonResponse {
        return self::error($message, 404);
    }

    /**
     * Response 401 Unauthorized — token tidak ada atau tidak valid.
     */
    public static function unauthorized(
        string $message = 'Tidak terotorisasi',
    ): JsonResponse {
        return self::error($message, 401);
    }

    /**
     * Response 403 Forbidden — user login tapi tidak punya akses.
     */
    public static function forbidden(
        string $message = 'Akses ditolak',
    ): JsonResponse {
        return self::error($message, 403);
    }

    /**
     * Response 422 Unprocessable Entity — validation error.
     * Format errors: {"field": ["pesan error", ...]}
     *
     * @param  array<string, string[]>  $errors  Dari $validator->errors()->toArray()
     */
    public static function validationError(
        array $errors,
        string $message = 'Data yang dikirim tidak valid',
    ): JsonResponse {
        return self::error($message, 422, $errors);
    }

    /**
     * Response 429 Too Many Requests — rate limit tercapai.
     */
    public static function tooManyRequests(
        string $message = 'Terlalu banyak permintaan, coba lagi nanti',
    ): JsonResponse {
        return self::error($message, 429);
    }

    // ─── Internal ─────────────────────────────────────────────────────────────

    /**
     * Normalisasi berbagai tipe $data ke array biasa.
     * JsonResource->toArray() tidak dipanggil di sini karena sudah ditangani
     * oleh Laravel saat response di-render — cukup pass object-nya langsung.
     *
     * @param  JsonResource|ResourceCollection|Arrayable|array<mixed>|null  $data
     * @return JsonResource|ResourceCollection|array<mixed>|null
     */
    private static function resolveData(
        JsonResource|ResourceCollection|Arrayable|array|null $data,
    ): JsonResource|ResourceCollection|array|null {
        return match (true) {
            $data instanceof Arrayable => $data->toArray(),
            default                   => $data,
        };
    }
}
