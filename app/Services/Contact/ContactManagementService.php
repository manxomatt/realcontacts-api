<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\ContactTag;
use App\Models\User;
use App\Models\UserContact;
use App\Services\BaseService;
use App\Services\Phone\PhoneLookupService;
use App\Services\Phone\PhoneNormalizerService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ContactManagementService
 *
 * Service untuk manajemen contact management RealContacts:
 * create, update, delete, tagging, sync, query.
 *
 * Fitur utama:
 *   - CRUD untuk user contacts
 *   - Tag management dengan usage tracking
 *   - Phone book sync dengan batch processing
 *   - Contact enrichment dari phone number database
 *   - Flexible filtering & pagination
 *   - Transaction-safe operations untuk atomicity
 */
final class ContactManagementService extends BaseService
{
    public function __construct(
        private readonly PhoneNormalizerService $normalizer,
        private readonly PhoneLookupService     $phoneLookup,
    ) {}

    /**
     * Buat user contact baru.
     *
     * Proses:
     *   1. Normalisasi phone_number
     *   2. Lookup/create di phone_numbers
     *   3. Buat UserContact baru
     *   4. Jika ada tag_ids: attach & update usage_count
     *   5. Load relasi (tags, phoneInfo)
     *
     * @param User                $user User pemilik kontak
     * @param array<string, mixed> $validatedData Data tervalidasi dari StoreUserContactRequest
     * @return UserContact Kontak yang baru dibuat
     */
    public function store(User $user, array $validatedData): UserContact
    {
        return DB::transaction(function () use ($user, $validatedData): UserContact {
            // ─── Normalisasi nomor ─────────────────────────────────────
            $phoneNumber = $validatedData['phone_number']
                |> $this->normalizer->normalize(...);

            // ─── Lookup/create di phone_numbers ────────────────────────
            $this->phoneLookup->lookupOrCreate($phoneNumber);

            // ─── Buat UserContact ──────────────────────────────────────
            $contact = UserContact::create([
                'user_id'      => $user->id,
                'phone_number' => $phoneNumber,
                'custom_name'  => $validatedData['custom_name'] ?? null,
                'notes'        => $validatedData['notes'] ?? null,
                'is_favorite'  => $validatedData['is_favorite'] ?? false,
                'contact_source' => $validatedData['contact_source'] ?? 'manual',
            ]);

            // ─── Attach tags & update usage_count ──────────────────────
            $tagIds = $validatedData['tag_ids'] ?? [];
            if (!empty($tagIds)) {
                // Attach ke kontak
                $contact->tags()->attach($tagIds);

                // Increment usage_count untuk setiap tag
                ContactTag::whereIn('id', $tagIds)->increment('usage_count');
            }

            // ─── Load relasi & return ──────────────────────────────────
            return $contact->load('tags', 'phoneNumber');
        });
    }

    /**
     * Update user contact yang sudah ada (partial update).
     *
     * Proses:
     *   1. Update field yang dikirim
     *   2. Jika tag_ids ada: sync (replace semua tag)
     *      - Decrement usage_count tag lama
     *      - Increment usage_count tag baru
     *   3. Invalidate cache
     *   4. Reload relasi
     *
     * @param UserContact         $contact Kontak yang akan diupdate
     * @param array<string, mixed> $validatedData Data tervalidasi
     * @return UserContact Kontak yang sudah diupdate
     */
    public function update(UserContact $contact, array $validatedData): UserContact
    {
        return DB::transaction(function () use ($contact, $validatedData): UserContact {
            // ─── Update field yang dikirim ────────────────────────────
            $updateData = [];

            if (isset($validatedData['custom_name'])) {
                $updateData['custom_name'] = $validatedData['custom_name'];
            }

            if (isset($validatedData['notes'])) {
                $updateData['notes'] = $validatedData['notes'];
            }

            if (isset($validatedData['is_favorite'])) {
                $updateData['is_favorite'] = $validatedData['is_favorite'];
            }

            if (!empty($updateData)) {
                $contact->update($updateData);
            }

            // ─── Sync tags jika ada ────────────────────────────────────
            if (isset($validatedData['tag_ids'])) {
                $newTagIds = $validatedData['tag_ids'];
                // Qualify 'id' with table name to avoid ambiguity
                $oldTagIds = $contact->tags()
                    ->select('contact_tags.id')
                    ->pluck('id')
                    ->toArray();

                // Cari tags yang di-remove (decrement) dan tags yang di-add (increment)
                $removedTagIds = array_diff($oldTagIds, $newTagIds);
                $addedTagIds = array_diff($newTagIds, $oldTagIds);

                // Decrement usage_count untuk removed tags
                if (!empty($removedTagIds)) {
                    $removedTags = ContactTag::whereIn('id', $removedTagIds)->get();
                    foreach ($removedTags as $tag) {
                        $tag->decrementUsage();
                    }
                }

                // Increment usage_count untuk added tags
                if (!empty($addedTagIds)) {
                    $addedTags = ContactTag::whereIn('id', $addedTagIds)->get();
                    foreach ($addedTags as $tag) {
                        $tag->incrementUsage();
                    }
                }

                // Sync di pivot table
                $contact->tags()->sync($newTagIds);
            }

            // ─── Invalidate cache & reload ─────────────────────────────
            $this->invalidateUserContactsCache($contact->user_id);
            return $contact->refresh()->load('tags', 'phoneNumber');
        });
    }

    /**
     * Hapus user contact.
     *
     * Proses:
     *   1. Hapus UserContact
     *   2. Decrement usage_count untuk semua tag yang terkait
     *
     * @param UserContact $contact Kontak yang akan dihapus
     * @return bool true jika berhasil dihapus
     */
    public function delete(UserContact $contact): bool
    {
        return DB::transaction(function () use ($contact): bool {
            $userId = $contact->user_id;
            // Qualify 'id' with table name to avoid ambiguity
            $tagIds = $contact->tags()
                ->select('contact_tags.id')
                ->pluck('id')
                ->toArray();

            // Decrement usage_count untuk semua tag
            if (!empty($tagIds)) {
                $tags = ContactTag::whereIn('id', $tagIds)->get();
                foreach ($tags as $tag) {
                    $tag->decrementUsage();
                }
            }

            // Hapus kontak
            $contact->delete();

            // Invalidate cache
            $this->invalidateUserContactsCache($userId);

            return true;
        });
    }

    /**
     * Toggle status favorit kontak.
     *
     * @param UserContact $contact Kontak yang akan di-toggle
     * @return bool Nilai is_favorite yang baru
     */
    public function toggleFavorite(UserContact $contact): bool
    {
        $contact->toggleFavorite();
        $this->invalidateUserContactsCache($contact->user_id);

        return (bool) $contact->is_favorite;
    }

    /**
     * Attach tag ke kontak.
     *
     * Proses:
     *   1. Cek tag milik user yang sama
     *   2. Cek tag belum terkait ke kontak
     *   3. Attach tag
     *   4. Increment usage_count
     *
     * @param UserContact $contact Kontak target
     * @param int         $tagId Tag yang akan di-attach
     * @return bool true jika berhasil attach, false jika sudah ada
     */
    public function attachTag(UserContact $contact, int $tagId): bool
    {
        // Cek tag exists dan milik user yang sama
        $tag = ContactTag::where('id', $tagId)
            ->where('user_id', $contact->user_id)
            ->first();

        if (!$tag) {
            return false;
        }

        // Cek tag belum terkait - qualify column name
        $alreadyAttached = $contact->tags()
            ->where('contact_tags.id', $tagId)
            ->exists();

        if ($alreadyAttached) {
            return false;
        }

        // Attach dan increment
        $contact->tags()->attach($tagId);
        $tag->incrementUsage();

        return true;
    }

    /**
     * Detach tag dari kontak.
     *
     * Proses:
     *   1. Detach tag
     *   2. Decrement usage_count
     *
     * @param UserContact $contact Kontak target
     * @param int         $tagId Tag yang akan di-detach
     * @return bool true
     */
    public function detachTag(UserContact $contact, int $tagId): bool
    {
        $contact->tags()->detach($tagId);

        // Decrement usage_count (safe: min 0)
        $tag = ContactTag::find($tagId);
        if ($tag) {
            $tag->decrementUsage();
        }

        return true;
    }

    /**
     * Sync kontak dari phone book user.
     *
     * Proses batch:
     *   1. Chunk data ke 100 item per batch
     *   2. Untuk setiap kontak:
     *      a. Normalisasi phone_number
     *      b. firstOrCreate di user_contacts
     *         source: 'phone_book'
     *      c. Jika sudah ada:
     *         update last_interacted_at saja
     *         JANGAN overwrite custom_name
     *   3. Gunakan transaction untuk atomicity
     *
     * @param User  $user User pemilik
     * @param array<array{phone_number: string, name: string, last_interacted_at?: string}> $contacts
     * @return array{created: int, updated: int, skipped: int, total: int}
     */
    public function syncFromPhoneBook(User $user, array $contacts): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total'   => count($contacts),
        ];

        if (empty($contacts)) {
            return $stats;
        }

        // Process dalam chunks untuk efisiensi
        $chunks = array_chunk($contacts, 100);

        foreach ($chunks as $chunk) {
            DB::transaction(function () use ($user, $chunk, &$stats): void {
                foreach ($chunk as $contactData) {
                    try {
                        $phoneNumber = $contactData['phone_number']
                            |> $this->normalizer->normalize(...);

                        // firstOrCreate dengan source 'phone_book'
                        [$contact, $wasCreated] = $this->firstOrCreatePhoneBookContact(
                            $user,
                            $phoneNumber,
                            $contactData['name'] ?? '',
                        );

                        if ($wasCreated) {
                            $stats['created']++;
                        } else {
                            // Update only last_interacted_at jika kontak sudah ada
                            if (!empty($contactData['last_interacted_at'])) {
                                $contact->update([
                                    'last_interacted_at' => $contactData['last_interacted_at'],
                                ]);
                            }

                            $stats['updated']++;
                        }
                    } catch (\Exception) {
                        // Jika ada error, skip item ini
                        $stats['skipped']++;
                    }
                }
            });
        }

        return $stats;
    }

    /**
     * Get user contacts dengan filter & pagination.
     *
     * Filter yang didukung:
     *   - tag_id: filter by tag
     *   - search: search custom_name atau phone_number
     *   - is_favorite: filter favorit
     *   - source: filter by contact_source
     *   - sort: 'name'|'recent'|'added'
     *
     * @param User  $user User pemilik
     * @param array<string, mixed> $filters Filter options
     * @return LengthAwarePaginator
     */
    public function getUserContacts(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = UserContact::forUser($user)->with('tags', 'phoneNumber');

        // ─── Apply filters ────────────────────────────────────────────
        if (isset($filters['tag_id'])) {
            $query->byTag((int) $filters['tag_id']);
        }

        if (isset($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (isset($filters['is_favorite'])) {
            if ((bool) $filters['is_favorite']) {
                $query->favorites();
            }
        }

        if (isset($filters['source'])) {
            $query->where('contact_source', (string) $filters['source']);
        }

        // ─── Apply sorting ────────────────────────────────────────────
        $sort = $filters['sort'] ?? 'added';
        match ($sort) {
            'name' => $query->orderBy('custom_name'),
            'recent' => $query->recentlyInteracted(),
            'added' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        // ─── Paginate ─────────────────────────────────────────────────
        $perPage = (int) ($filters['per_page'] ?? 20);

        return $query->paginate($perPage);
    }

    /**
     * Enrich user contacts dengan phone number database lookup.
     *
     * Untuk kontak yang owner_name = null:
     *   1. Lookup ke phone_numbers
     *   2. Update display info jika ada data baru
     *
     * Berguna ketika ada update di phone_numbers table
     * dan ingin synchronize display name.
     *
     * @param User $user User pemilik
     * @return array{enriched: int}
     */
    public function enrichContacts(User $user): array
    {
        $enriched = 0;

        // Ambil kontak user yang belum punya owner_name di phone_numbers
        $contacts = UserContact::forUser($user)
            ->with('phoneNumber')
            ->get()
            ->filter(fn ($contact) => $contact->phoneNumber === null || $contact->phoneNumber->owner_name === null);

        foreach ($contacts as $contact) {
            // Lookup ke phone_numbers
            $phoneData = $this->phoneLookup->lookup($contact->phone_number);

            if ($phoneData && !empty($phoneData['owner_name'])) {
                // Update atau create phone number dengan info baru
                $this->phoneLookup->lookupOrCreate($contact->phone_number);
                $enriched++;
            }
        }

        return [
            'enriched' => $enriched,
        ];
    }

    // ─── Private Helpers ───────────────────────────────────────────────────

    /**
     * FirstOrCreate kontak dari phone book.
     *
     * Jika kontak sudah ada, cek sumber untuk decide apakah update atau skip.
     *
     * @param User   $user User pemilik
     * @param string $phoneNumber Nomor yang sudah dinormalisasi
     * @param string $phoneBookName Nama dari phone book
     * @return array{0: UserContact, 1: bool} [contact, wasCreated]
     */
    private function firstOrCreatePhoneBookContact(
        User $user,
        string $phoneNumber,
        string $phoneBookName,
    ): array {
        $existing = UserContact::forUser($user)
            ->where('phone_number', $phoneNumber)
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        // Jika belum ada, create
        $contact = UserContact::create([
            'user_id'      => $user->id,
            'phone_number' => $phoneNumber,
            'custom_name'  => $phoneBookName,
            'contact_source' => 'phone_book',
        ]);

        return [$contact, true];
    }

    /**
     * Invalidate cache untuk user contacts.
     *
     * Dipanggil setelah ada perubahan (create, update, delete).
     *
     * @param int $userId ID user pemilik
     */
    private function invalidateUserContactsCache(int $userId): void
    {
        // TODO: implement cache invalidation jika ada caching layer
        // Contoh: Cache::forget("user_contacts:{$userId}");
    }
}
