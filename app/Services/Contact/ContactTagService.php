<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\ContactTag;
use App\Models\User;
use App\Models\UserContact;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ContactTagService
 *
 * Service untuk manajemen contact tags di RealContacts:
 * - System tags untuk kategori standar
 * - Custom tags untuk kategori user
 * - Tag merging & management
 *
 * Fitur utama:
 *   - Buat default tags saat user register
 *   - CRUD untuk custom tags
 *   - Prevent deletion of system tags
 *   - Tag merging dengan transaction safety
 *   - Query optimization dengan eager loading
 */
final class ContactTagService extends BaseService
{
    /**
     * Default system tags untuk user baru.
     *
     * Setiap user akan mendapat 6 tag sistem ini saat register.
     * Tags ini tidak bisa dihapus oleh user.
     *
     * @var array<int, array{name: string, color: string, icon: string}>
     */
    private const SYSTEM_TAGS = [
        ['name' => 'Pelanggan', 'color' => '#4CAF50', 'icon' => 'user'],
        ['name' => 'Supplier', 'color' => '#2196F3', 'icon' => 'briefcase'],
        ['name' => 'Keluarga', 'color' => '#E91E63', 'icon' => 'family'],
        ['name' => 'Rekan Kerja', 'color' => '#FF9800', 'icon' => 'work'],
        ['name' => 'Penting', 'color' => '#F44336', 'icon' => 'star'],
        ['name' => 'Junk', 'color' => '#9E9E9E', 'icon' => 'block'],
    ];

    /**
     * Buat default system tags untuk user baru.
     *
     * Dipanggil dari AuthService->register() dalam transaction.
     * Jika tag sudah ada, cek dulu dengan byUser() scope.
     *
     * @param User $user User baru yang baru register
     * @return void
     */
    public function createDefaultTagsForUser(User $user): void
    {
        foreach (self::SYSTEM_TAGS as $tagData) {
            // Cek tag sudah ada untuk user ini
            $exists = ContactTag::byUser($user)
                ->where('name', $tagData['name'])
                ->where('is_system', true)
                ->exists();

            if ($exists) {
                continue;
            }

            // Buat tag sistem baru
            ContactTag::create([
                'user_id'    => $user->id,
                'name'       => $tagData['name'],
                'color'      => $tagData['color'],
                'icon'       => $tagData['icon'],
                'is_system'  => true,
                'usage_count' => 0,
            ]);
        }
    }

    /**
     * Buat tag custom baru untuk user.
     *
     * Proses:
     *   1. Validasi data dari form request
     *   2. Buat ContactTag baru dengan is_system = false
     *   3. Return tag yang dibuat
     *
     * @param User  $user User pemilik tag
     * @param array<string, mixed> $validatedData Data tervalidasi dari StoreContactTagRequest
     * @return ContactTag Tag yang dibuat
     */
    public function store(User $user, array $validatedData): ContactTag
    {
        return ContactTag::create([
            'user_id'    => $user->id,
            'name'       => $validatedData['name'],
            'color'      => $validatedData['color'] ?? '#9E9E9E',
            'icon'       => $validatedData['icon'] ?? 'label',
            'is_system'  => false,
            'usage_count' => 0,
        ]);
    }

    /**
     * Update tag yang sudah ada.
     *
     * Proses:
     *   1. Update field yang dikirim (name, color, icon)
     *   2. JANGAN update is_system via method ini
     *   3. Return tag yang diupdate
     *
     * @param ContactTag $tag Tag yang akan diupdate
     * @param array<string, mixed> $data Data yang akan diupdate
     * @return ContactTag Tag yang sudah diupdate
     */
    public function update(ContactTag $tag, array $data): ContactTag
    {
        $updateData = [];

        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }

        if (isset($data['color'])) {
            $updateData['color'] = $data['color'];
        }

        if (isset($data['icon'])) {
            $updateData['icon'] = $data['icon'];
        }

        if (!empty($updateData)) {
            $tag->update($updateData);
        }

        return $tag->fresh();
    }

    /**
     * Hapus tag.
     *
     * Proses:
     *   1. Cegah delete tag sistem (model will throw exception)
     *   2. Detach dari semua kontak terkait
     *   3. Hapus tag dari database
     *   4. Return true
     *
     * Note: ContactTag model memiliki delete() override
     * yang throw exception jika is_system = true.
     *
     * @param ContactTag $tag Tag yang akan dihapus
     * @return bool true jika berhasil dihapus
     * @throws \Exception Jika mencoba hapus system tag
     */
    public function delete(ContactTag $tag): bool
    {
        DB::transaction(function () use ($tag): void {
            // Detach dari semua kontak
            $tag->contacts()->detach();

            // Delete tag (model akan throw exception jika system tag)
            $tag->delete();
        });

        return true;
    }

    /**
     * Ambil semua tag milik user dengan sorting.
     *
     * Proses:
     *   1. Ambil semua tag milik user (via byUser scope)
     *   2. Sort: system tags dulu, lalu custom tags
     *   3. Dalam tiap grup, sort by usage_count DESC
     *   4. Gunakan array_first() untuk identifikasi most used tag
     *   5. Return: Collection ContactTag
     *
     * @param User $user User pemilik tag
     * @return Collection<int, ContactTag>
     */
    public function getUserTags(User $user): Collection
    {
        $tags = ContactTag::byUser($user)
            ->orderBy('is_system', 'desc')
            ->orderByDesc('usage_count')
            ->get();

        return $tags;
    }

    /**
     * Ambil semua kontak yang punya tag tertentu.
     *
     * Proses:
     *   1. Query UserContact via many-to-many relationship
     *   2. Eager load phoneInfo untuk mencegah N+1
     *   3. Paginate dengan perPage yang dikirim
     *
     * @param ContactTag $tag Tag yang di-query
     * @param int        $perPage Items per page (default: 20)
     * @return LengthAwarePaginator Paginated UserContact
     */
    public function getContactsByTag(ContactTag $tag, int $perPage = 20): LengthAwarePaginator
    {
        return $tag->contacts()
            ->with('phoneNumber')
            ->paginate($perPage);
    }

    /**
     * Merge tag sumber ke tag target.
     *
     * Proses:
     *   1. Ambil semua kontak dengan sourceTag
     *   2. Pindahkan ke targetTag (attach)
     *   3. Update targetTag usage_count += sourceTag usage_count
     *   4. Detach & delete sourceTag
     *   5. Gunakan DB::transaction() untuk atomicity
     *   6. Return: true
     *
     * Use case:
     *   - Merge duplicate tags
     *   - Consolidate custom tags
     *   - Clean up redundant categorization
     *
     * @param ContactTag $sourceTag Tag yang akan di-merge
     * @param ContactTag $targetTag Tag target (tempat merge)
     * @return bool true jika berhasil merge
     */
    public function mergeTag(ContactTag $sourceTag, ContactTag $targetTag): bool
    {
        return DB::transaction(function () use ($sourceTag, $targetTag): bool {
            // ─── Pindahkan kontak dari source ke target ───────────────
            // Qualify 'id' dengan table name untuk menghindari ambiguity di join
            $sourceContacts = $sourceTag->contacts()
                ->select('user_contacts.id')
                ->pluck('id')
                ->toArray();

            if (!empty($sourceContacts)) {
                // Attach ke target
                $targetTag->contacts()->attach($sourceContacts);
            }

            // ─── Update usage_count ────────────────────────────────────
            $sourceUsageCount = $sourceTag->usage_count;
            $targetTag->increment('usage_count', $sourceUsageCount);

            // ─── Detach dan hapus source tag ────────────────────────────
            $sourceTag->contacts()->detach();
            $sourceTag->delete();

            return true;
        });
    }
}
