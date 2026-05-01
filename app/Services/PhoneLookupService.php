<?php

namespace App\Services;

use App\Repositories\PhoneNumberRepository;

class PhoneLookupService extends BaseService
{
    public function __construct(
        protected PhoneNumberRepository $phoneRepo
    ) {}

    // Cari nomor telepon berdasarkan query
    public function lookup(string $phoneNumber): array
    {
        $result = $this->phoneRepo->findByNumber($phoneNumber);

        if (! $result) {
            return $this->error('Nomor tidak ditemukan');
        }

        return $this->success($result, 'Data nomor ditemukan');
    }
}
