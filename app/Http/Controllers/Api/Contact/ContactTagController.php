<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Contact;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactTagRequest;
use App\Http\Resources\ContactTagResource;
use App\Http\Resources\Collections\ContactTagCollection;
use App\Http\Responses\ApiResponse;
use App\Models\ContactTag;
use App\Services\Contact\ContactTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * ContactTagController
 *
 * Manajemen contact tags: CRUD, listing, merging.
 * Semua endpoint memerlukan autentikasi Sanctum.
 *
 * Endpoints:
 *   - GET /api/contacts/tags — Daftar semua tag user
 *   - POST /api/contacts/tags — Buat tag baru
 *   - PUT /api/contacts/tags/{tag} — Update tag
 *   - DELETE /api/contacts/tags/{tag} — Hapus tag
 *   - GET /api/contacts/tags/{tag}/contacts — Kontak dengan tag ini
 *   - POST /api/contacts/tags/{tag}/merge — Merge ke tag lain
 */
#[Middleware('auth:sanctum')]
class ContactTagController extends Controller
{
    public function __construct(
        private readonly ContactTagService $tagService,
    ) {}

    /**
     * Daftar semua tag milik user dengan summary.
     *
     * GET /api/contacts/tags
     */
    public function index(Request $request): JsonResponse
    {
        $tags = $this->tagService->getUserTags($request->user());
        return ApiResponse::success(new ContactTagCollection($tags), 'Daftar tag berhasil diambil');
    }

    /**
     * Buat tag baru untuk user.
     *
     * POST /api/contacts/tags
     */
    public function store(StoreContactTagRequest $request): JsonResponse
    {
        $tag = $this->tagService->store($request->user(), $request->validated());
        return ApiResponse::created(new ContactTagResource($tag), 'Tag berhasil dibuat');
    }

    /**
     * Update tag (nama, warna, icon).
     *
     * PUT /api/contacts/tags/{tag}
     */
    public function update(Request $request, ContactTag $tag): JsonResponse
    {
        $this->authorize('update', $tag);
        $validated = $request->validate([
            'name' => 'nullable|string|min:2|max:50|unique:contact_tags,name,' . $tag->id . ',id,user_id,' . $request->user()->id,
            'color' => 'nullable|string|regex:/^#[0-9A-F]{6}$/i',
            'icon' => 'nullable|string|in:label,star,bookmark,flag,heart,user,users,building,briefcase,shopping_cart,phone,mail,map,calendar,clock,tag,home,restaurant,family,medical,school,work,favorite,important',
        ]);
        $updated = $this->tagService->update($tag, $validated);
        return ApiResponse::success(new ContactTagResource($updated), 'Tag berhasil diperbarui');
    }

    /**
     * Hapus tag custom (system tags tidak bisa dihapus).
     *
     * DELETE /api/contacts/tags/{tag}
     */
    public function destroy(Request $request, ContactTag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);
        try {
            $this->tagService->delete($tag);
            return ApiResponse::noContent('Tag berhasil dihapus');
        } catch (\Exception $e) {
            // Tangkap SystemTagException atau exception apapun dari delete
            if (strpos($e->getMessage(), 'sistem') !== false || strpos(get_class($e), 'SystemTag') !== false) {
                return ApiResponse::error('Tag sistem tidak dapat dihapus', 422);
            }
            throw $e;
        }
    }

    /**
     * Daftar semua kontak yang punya tag ini.
     *
     * GET /api/contacts/tags/{tag}/contacts
     */
    public function contacts(Request $request, ContactTag $tag): JsonResponse
    {
        $this->authorize('view', $tag);
        $perPage = (int) ($request->query('per_page') ?? 20);
        $paginated = $this->tagService->getContactsByTag($tag, $perPage);
        return ApiResponse::success($paginated, 'Kontak dengan tag ini berhasil diambil');
    }

    /**
     * Merge tag ke tag lain.
     *
     * POST /api/contacts/tags/{tag}/merge
     * Body: { target_tag_id: integer }
     */
    public function merge(Request $request, ContactTag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);
        $validated = $request->validate([
            'target_tag_id' => 'required|integer|exists:contact_tags,id',
        ]);

        // Cek target tag milik user yang sama
        $targetTag = ContactTag::findOrFail($validated['target_tag_id']);
        if ($targetTag->user_id !== $request->user()->id) {
            throw ValidationException::withMessages(['target_tag_id' => 'Tag target tidak ditemukan']);
        }

        // Cegah merge ke tag yang sama
        if ($tag->id === $targetTag->id) {
            throw ValidationException::withMessages(['target_tag_id' => 'Tidak bisa merge ke tag yang sama']);
        }

        // Merge tags
        $this->tagService->mergeTag($tag, $targetTag);

        return ApiResponse::success(
            ['target_tag' => new ContactTagResource($targetTag->fresh())],
            'Tag berhasil digabungkan'
        );
    }
}
