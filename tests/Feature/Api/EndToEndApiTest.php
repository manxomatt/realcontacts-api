<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * End-to-End API Testing
 *
 * Comprehensive test suite untuk semua endpoint RealContacts API.
 * Coverage: Public, Protected, dan Admin endpoints.
 *
 * Running tests:
 *   php artisan test tests/Feature/Api/EndToEndApiTest.php
 */
class EndToEndApiTest extends TestCase
{
    private string $testPhone = '+6281234567890';
    private string $testOtp = '123456';
    private ?User $user = null;
    private ?User $adminUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->user = User::factory()->create([
            'phone_number' => $this->testPhone,
            'is_admin'     => false,
            'is_premium'   => false,
        ]);

        $this->adminUser = User::factory()->create([
            'phone_number' => '+6298765432100',
            'is_admin'     => true,
            'is_premium'   => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUBLIC ENDPOINTS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: POST /api/auth/register
     */
    public function test_register_new_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'phone_number' => '+6281234567999',
            'name'         => 'New User',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'phone_number', 'name'],
                'message',
            ]);
    }

    /**
     * @test
     * Endpoint: POST /api/auth/otp/request
     */
    public function test_request_otp(): void
    {
        $response = $this->postJson('/api/auth/otp/request', [
            'phone_number' => $this->testPhone,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: POST /api/auth/otp/resend
     */
    public function test_resend_otp(): void
    {
        $response = $this->postJson('/api/auth/otp/resend', [
            'phone_number' => $this->testPhone,
        ]);

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PROTECTED ENDPOINTS - User Routes
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: GET /api/auth/me
     */
    public function test_get_current_user_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'id'           => $this->user->id,
                    'phone_number' => $this->testPhone,
                ],
            ]);
    }

    /**
     * @test
     * Endpoint: POST /api/auth/logout
     */
    public function test_logout(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * @test
     * Endpoint: GET /api/user/profile
     */
    public function test_get_user_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['user', 'stats'],
                'message',
            ]);
    }

    /**
     * @test
     * Endpoint: PATCH /api/user/profile
     */
    public function test_update_user_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/user/profile', [
                'name'  => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * @test
     * Endpoint: GET /api/user/number-health
     */
    public function test_get_number_health(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user/number-health');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: GET /api/user/contributions
     */
    public function test_get_contributions(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user/contributions');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: GET /api/user/leaderboard
     */
    public function test_get_leaderboard(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user/leaderboard?period=monthly&limit=10');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PROTECTED ENDPOINTS - Phone Lookup
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: GET /api/phone/{number}
     */
    public function test_lookup_single_phone(): void
    {
        // Create test phone number
        PhoneNumber::factory()->create([
            'phone_number'       => $this->testPhone,
            'normalized_number'  => $this->testPhone,
            'spam_score'         => 5,
            'spam_level'         => 'warning',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/phone/{$this->testPhone}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['phone_number', 'spam_score', 'spam_level'],
                'message',
            ]);
    }

    /**
     * @test
     * Endpoint: GET /api/phone/search
     */
    public function test_search_phones(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/phone/search?query=pizza&limit=10');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: GET /api/phone/recent
     */
    public function test_get_recent_lookups(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/phone/recent?limit=20');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: POST /api/phone/batch (Premium feature)
     */
    public function test_batch_lookup_requires_premium(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/phone/batch', [
                'phone_numbers' => ['+6281234567890', '+6281234567891'],
            ]);

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /**
     * @test
     * Premium batch lookup
     */
    public function test_batch_lookup_premium_user(): void
    {
        $premiumUser = User::factory()->create(['is_premium' => true]);

        $response = $this->actingAs($premiumUser, 'sanctum')
            ->postJson('/api/phone/batch', [
                'phone_numbers' => ['+6281234567890', '+6281234567891'],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PROTECTED ENDPOINTS - Spam Reports
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: GET /api/spam-reports/{phoneNumber}
     */
    public function test_get_spam_reports_for_phone(): void
    {
        SpamReport::factory()->create([
            'phone_number' => $this->testPhone,
            'status'       => 'verified',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/spam-reports/{$this->testPhone}");

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: POST /api/spam-reports
     */
    public function test_submit_spam_report(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/spam-reports', [
                'phone_number' => '+6281234567880',
                'category'     => 'spam',
                'description'  => 'Robocall spam',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: POST /api/spam-reports/{report}/vote
     */
    public function test_vote_on_spam_report(): void
    {
        $report = SpamReport::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/spam-reports/{$report->id}/vote", [
                'type' => 'upvote',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * @test
     * Endpoint: GET /api/spam-reports/me/reports
     */
    public function test_get_my_reports(): void
    {
        SpamReport::factory()->create([
            'reporter_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/spam-reports/me/reports');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PROTECTED ENDPOINTS - Call Blocker
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: GET /api/blocked-numbers
     */
    public function test_get_blocklist(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/blocked-numbers');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: POST /api/blocked-numbers
     */
    public function test_add_to_blocklist(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/blocked-numbers', [
                'phone_number' => '+6281234567880',
                'reason'       => 'Spam',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: GET /api/blocked-numbers/{number}/check
     */
    public function test_check_if_blocked(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/blocked-numbers/{$this->testPhone}/check");

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: DELETE /api/blocked-numbers/{number}
     */
    public function test_remove_from_blocklist(): void
    {
        // First add to blocklist
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/blocked-numbers', [
                'phone_number' => '+6281234567880',
            ]);

        // Then remove
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/blocked-numbers/+6281234567880');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * @test
     * Endpoint: POST /api/blocked-numbers/import
     */
    public function test_bulk_import_blocklist(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/blocked-numbers/import', [
                'phone_numbers' => ['+6281234567880', '+6281234567881', '+6281234567882'],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PROTECTED ENDPOINTS - Business Profiles
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: GET /api/business-profiles/{number}
     */
    public function test_get_business_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/business-profiles/{$this->testPhone}");

        // May return 404 if not a business number
        $response->assertIn([200, 404]);
    }

    /**
     * @test
     * Endpoint: POST /api/business-profiles/{number}/claim
     */
    public function test_claim_business_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/business-profiles/{$this->testPhone}/claim", [
                'business_name' => 'Pizza Shop',
                'website'       => 'https://pizzashop.com',
            ]);

        // May succeed or return error if already claimed
        $response->assertIn([201, 200, 422]);
    }

    /**
     * @test
     * Endpoint: POST /api/business-profiles/{number}/rate
     */
    public function test_rate_business(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/business-profiles/{$this->testPhone}/rate", [
                'rating' => 4.5,
                'review' => 'Great service!',
            ]);

        $response->assertIn([200, 201, 404, 422]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ADMIN ENDPOINTS - Spam Moderation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: GET /api/spam/moderation/pending (Admin only)
     */
    public function test_get_pending_reports_admin_only(): void
    {
        // Non-admin user should get 403
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/spam/moderation/pending');

        $response->assertStatus(403);
    }

    /**
     * @test
     * Admin can get pending reports
     */
    public function test_admin_get_pending_reports(): void
    {
        SpamReport::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/spam/moderation/pending');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: PATCH /api/spam/moderation/{report}/approve
     */
    public function test_admin_approve_report(): void
    {
        $report = SpamReport::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/spam/moderation/{$report->id}/approve", []);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * @test
     * Endpoint: PATCH /api/spam/moderation/{report}/reject
     */
    public function test_admin_reject_report(): void
    {
        $report = SpamReport::factory()->create(['status' => 'pending']);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/spam/moderation/{$report->id}/reject", [
                'rejection_reason' => 'Insufficient evidence',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * @test
     * Endpoint: GET /api/spam/moderation/stats
     */
    public function test_admin_get_moderation_stats(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/spam/moderation/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['total_pending', 'approved_today', 'rejected_today', 'top_reporters'],
                'message',
            ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ADMIN ENDPOINTS - Dashboard
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Endpoint: GET /api/admin/dashboard/stats
     */
    public function test_admin_dashboard_stats(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['users', 'phones', 'reports', 'lookups'],
                'message',
            ]);
    }

    /**
     * @test
     * Endpoint: GET /api/admin/dashboard/activity
     */
    public function test_admin_dashboard_activity(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/dashboard/activity?minutes=30');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['recent_reports', 'recent_lookups_count', 'active_users'],
                'message',
            ]);
    }

    /**
     * @test
     * Endpoint: GET /api/admin/dashboard/top-spam-numbers
     */
    public function test_admin_dashboard_top_spam_numbers(): void
    {
        PhoneNumber::factory()->create([
            'spam_score' => 50,
            'spam_level' => 'danger',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/dashboard/top-spam-numbers');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    /**
     * @test
     * Endpoint: GET /api/admin/dashboard/reporter-leaderboard
     */
    public function test_admin_dashboard_reporter_leaderboard(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/dashboard/reporter-leaderboard');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'message']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ERROR HANDLING TESTS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Unauthenticated request should return 401
     */
    public function test_unauthenticated_protected_endpoint(): void
    {
        $response = $this->getJson('/api/user/profile');

        $response->assertStatus(401);
    }

    /**
     * @test
     * Invalid phone number should return 422
     */
    public function test_invalid_phone_lookup(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/phone/invalid-number');

        $response->assertStatus(422);
    }

    /**
     * @test
     * Duplicate spam report should return 422
     */
    public function test_duplicate_spam_report(): void
    {
        SpamReport::factory()->create([
            'phone_number'     => '+6281234567880',
            'reporter_user_id' => $this->user->id,
            'created_at'       => now()->subHours(2),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/spam-reports', [
                'phone_number' => '+6281234567880',
                'category'     => 'spam',
            ]);

        $response->assertStatus(422);
    }
}
