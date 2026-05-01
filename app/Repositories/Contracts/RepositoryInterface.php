<?php

namespace App\Repositories\Contracts;

interface RepositoryInterface
{
    public function all(array $columns = ['*']): mixed;
    public function find(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}
