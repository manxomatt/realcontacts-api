<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Contact\ContactManagementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\Uuid;

/**
 * SyncPhoneBookJob
 *
 * Async job untuk sync phone book dari device user.
 * Dipanggil ketika ada > 100 kontak yang perlu disync dari HP.
 *
 * Fitur:
 * - Proses dalam background untuk menghindari timeout
 * - Retry 3x dengan backoff 30s, 60s, 120s
 * - Timeout 120 detik per attempt
 * - Queue khusus 'contacts' dengan Redis
 *
 * @author RealContacts API
 * @version 1.0
 */
#[\Illuminate\Queue\Attributes\Tries(3)]
#[\Illuminate\Queue\Attributes\Timeout(120)]
#[\Illuminate\Queue\Attributes\Backoff([30, 60, 120])]
class SyncPhoneBookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Unique ID untuk tracking job ini di logs.
     */
    private string $jobId;

    /**
     * Konstruktor job.
     *
     * @param User $user User yang melakukan sync
     * @param array<int, array{phone_number: string, name: string, last_interacted_at?: string}> $contacts
     *              Daftar kontak dari phone book device user
     */
    public function __construct(
        private readonly User $user,
        private readonly array $contacts,
    ) {
        $this->jobId = Uuid::v7()->toRfc4122();
        $this->onQueue('contacts');
    }

    /**
     * Execute job untuk sync phone book.
     *
     * Proses:
     * 1. Catat mulai sync di log
     * 2. Inject ContactManagementService
     * 3. Panggil syncFromPhoneBook() - auto-chunking 100 items
     * 4. Log hasil sync (created, updated, skipped)
     * 5. Dispatch notifikasi ke user
     *
     * @param ContactManagementService $service Service untuk sync kontak
     * @return void
     */
    public function handle(ContactManagementService $service): void
    {
        try {
            // Log: mulai proses sync
            Log::info('ContactManagement: Memulai sync phone book', [
                'job_id' => $this->jobId,
                'user_id' => $this->user->id,
                'contact_count' => count($this->contacts),
            ]);

            // Sync kontak dari phone book
            $result = $service->syncFromPhoneBook($this->user, $this->contacts);

            // Extract hasil sync
            $createdCount = $result['created'] ?? 0;
            $updatedCount = $result['updated'] ?? 0;
            $skippedCount = $result['skipped'] ?? 0;

            // Log: selesai berhasil
            Log::info('ContactManagement: Sync phone book selesai', [
                'job_id' => $this->jobId,
                'user_id' => $this->user->id,
                'created' => $createdCount,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
            ]);

            // TODO: Dispatch event/notification ke user
            // Example:
            // ContactSyncCompleted::dispatch($this->user, [
            //     'created' => $createdCount,
            //     'updated' => $updatedCount,
            // ]);
            //
            // Atau gunakan Notification:
            // $this->user->notify(new SyncPhoneBookNotification($createdCount, $updatedCount));

        } catch (\Throwable $exception) {
            // Log error
            Log::error('ContactManagement: Sync phone book gagal', [
                'job_id' => $this->jobId,
                'user_id' => $this->user->id,
                'error' => $exception->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Re-throw agar Laravel handle retry
            throw $exception;
        }
    }

    /**
     * Handle job failure setelah semua retry habis.
     *
     * @param \Throwable $exception Exception yang menyebabkan job gagal
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ContactManagement: Sync phone book gagal setelah 3x retry', [
            'job_id' => $this->jobId,
            'user_id' => $this->user->id,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Notify user bahwa sync gagal
        // $this->user->notify(new SyncPhoneBookFailedNotification());
    }
}
