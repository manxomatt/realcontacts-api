<?php

namespace App\Services;

// Kelas dasar untuk semua service di aplikasi
abstract class BaseService
{
    // Response sukses terstandarisasi
    protected function success(mixed $data = null, string $message = 'OK'): array
    {
        return [
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ];
    }

    // Response gagal terstandarisasi
    protected function error(string $message = 'Error', mixed $data = null): array
    {
        return [
            'success' => false,
            'data'    => $data,
            'message' => $message,
        ];
    }
}
