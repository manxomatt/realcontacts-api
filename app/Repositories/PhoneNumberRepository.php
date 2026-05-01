<?php

namespace App\Repositories;

use App\Models\PhoneNumber;

class PhoneNumberRepository extends BaseRepository
{
    public function __construct(PhoneNumber $model)
    {
        parent::__construct($model);
    }

    // Cari nomor telepon berdasarkan nomor
    public function findByNumber(string $number): mixed
    {
        return $this->model
            ->where('number', $number)
            ->first();
    }
}
