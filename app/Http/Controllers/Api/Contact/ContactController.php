<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Contact;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactTagRequest;
use App\Http\Requests\Contact\StoreUserContactRequest;
use App\Http\Requests\Contact\SyncPhoneBookRequest;
use App\Http\Requests\Contact\UpdateUserContactRequest;
use App\Http\Resources\UserContactResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\EnrichContactsJob;
use App\Jobs\SyncPhoneBookJob;
use App\Models\UserContact;
use App\Services\Contact\ContactManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * ContactController
 *
 * Manajemen kontak user: CRUD, tagging, sync phone book, enrichment.
 * Semua endpoint memerlukan autentikasi Sanctum.
 *
 * Endpoints:
 *   - GET /api/contacts — List kontak dengan filter & pagination
 *   - POST /api/contacts — Tambah kontak baru
 *   - GET /api/contacts/{id} — Detail kontak
 *   - PUT /api/contacts/{id} — Update kontak
 *   - DELETE /api/contacts/{id} — Hapus kontak
 *   - POST /api/contacts/{id}/toggle-favorite — Toggle favorite
 *   - POST /api/contacts/sync-phone-book — Sync dari phone book
 *   - POST /api/contacts/enrich — Enrichment data
 */
#[Middleware('auth:sanctum')]
class ContactController extends Controller
{
    public function __construct(
        private readonly ContactManagementService $contactService,
    ) {}

    /**
     * Daftar kontak user dengan filter & pagination.
     *
     * Query params:
     *   ?tag_id=1 — Filter by tag
     *   ?search=budi — Search by name/phone
     *   ?is_favorite=true — Only favorites
     *   ?source=phone_book — Filter by source
     *   ?sort=name|recent|added — Sort order
     *   ?per_page=20 — Items per page
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $contacts = $this->contactService->getUserContacts($user, $request->query());
        $favorites = $this->contactService->getUserContacts($user, ['is_favorite' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Daftar kontak berhasil diambil',
            'data' => UserContactResource::collection($contacts),
        ], 200, [
            'X-Total-Contacts' => (string) $contacts->total(),
            'X-Favorite-Count' => (string) $favorites->total(),
        ]);
    }

    /**
     * Tambah kontak baru ke daftar user.
     *
     * POST /api/contacts
     */
    public function store(StoreUserContactRequest $request): JsonResponse
    {
        $contact = $this->contactService->store($request->user(), $request->validated());
        return ApiResponse::created(new UserContactResource($contact->load('tags', 'phoneNumber')), 'Kontak berhasil ditambahkan');
    }

    /**
     * Detail kontak lengkap dengan tags & phone info.
     *
     * GET /api/contacts/{id}
     */
    public function show(Request $request, UserContact $contact): JsonResponse
    {
        $this->authorize('view', $contact);
        $contact->load('tags', 'phoneNumber');
        return ApiResponse::success(new UserContactResource($contact), 'Detail kontak berhasil diambil');
    }

    /**
     * Update kontak (partial update).
     *
     * PUT /api/contacts/{id}
     */
    public function update(UpdateUserContactRequest $request, UserContact $contact): JsonResponse
    {
        $updated = $this->contactService->update($contact, $request->validated());
        return ApiResponse::success(new UserContactResource($updated->load('tags', 'phoneNumber')), 'Kontak berhasil diperbarui');
    }

    /**
     * Hapus kontak dari daftar user.
     *
     * DELETE /api/contacts/{id}
     */
    public function destroy(Request $request, UserContact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);
        $this->contactService->delete($contact);
        return ApiResponse::noContent('Kontak berhasil dihapus');
    }

    /**
     * Toggle status favorit kontak.
     *
     * POST /api/contacts/{id}/toggle-favorite
     */
    public function toggleFavorite(Request $request, UserContact $contact): JsonResponse
    {
        $this->authorize('update', $contact);
        $newStatus = $this->contactService->toggleFavorite($contact);
        $message = $newStatus ? 'Ditambahkan ke favorit' : 'Dihapus dari favorit';
        return ApiResponse::success(['is_favorite' => $newStatus], $message);
    }

    /**
     * Sync kontak dari phone book user.
     *
     * POST /api/contacts/sync-phone-book
     * > 100 kontak → async job
     * ≤ 100 kontak → sync langsung
     */
    public function syncPhoneBook(SyncPhoneBookRequest $request): JsonResponse
    {
        $user = $request->user();
        $contacts = $request->validated()['contacts'];

        if (count($contacts) > 100) {
            SyncPhoneBookJob::dispatch($user, $contacts);
            return ApiResponse::success(
                ['status' => 'processing', 'estimated_time' => '30 detik'],
                'Sinkronisasi sedang diproses'
            );
        }

        $result = $this->contactService->syncFromPhoneBook($user, $contacts);
        return ApiResponse::success($result, 'Sinkronisasi berhasil');
    }

    /**
     * Enrichment kontak yang belum punya nama owner.
     *
     * POST /api/contacts/enrich
     * Async job untuk background processing.
     */
    public function enrich(Request $request): JsonResponse
    {
        EnrichContactsJob::dispatch($request->user());
        return ApiResponse::success(
            ['status' => 'processing'],
            'Pengayaan data kontak sedang diproses'
        );
    }
}
