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
 * EnrichContactsJob
 *
 * Async job untuk enrichment data kontak user.
 * Lookup ke phone_numbers database untuk mendapatkan informasi pemilik nomor.
 *
 * Fitur:
 * - Proses dalam background untuk mencegah timeout
 * - Retry 2x dengan exponential backoff otomatis
 * - Timeout 180 detik per attempt
 * - Queue khusus 'contacts' dengan Redis
 * - Update display_name kontak yang belum punya informasi pemilik
 *
 * @author RealContacts API
 * @version 1.0
 */
#[\Illuminate\Queue\Attributes\Tries(2)]
#[\Illuminate\Queue\Attributes\Timeout(180)]
class EnrichContactsJob implements ShouldQueue
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
     * @param User $user User yang kontak-nya akan di-enrich
     */
    public function __construct(
        private readonly User $user,
    ) {
        $this->jobId = Uuid::v7()->toRfc4122();
        $this->onQueue('contacts');
    }

    /**
     * Execute job untuk enrichment kontak.
     *
     * Proses:
     * 1. Catat mulai enrichment di log
     * 2. Inject ContactManagementService
     * 3. Panggil enrichContacts() untuk lookup ke phone_numbers database
     * 4. Log hasil enrichment (berapa kontak yang berhasil di-enrich)
     * 5. Dispatch notifikasi ke user
     *
     * @param ContactManagementService $service Service untuk enrich kontak
     * @return void
     */
    public function handle(ContactManagementService $service): void
    {
        try {
            // Log: mulai proses enrichment
            Log::info('ContactManagement: Memulai enrichment kontak', [
                'job_id' => $this->jobId,
                'user_id' => $this->user->id,
            ]);

            // Enrich kontak user dengan lookup ke phone_numbers
            $result = $service->enrichContacts($this->user);

            // Extract hasil enrichment
            $enrichedCount = $result['enriched'] ?? 0;
            $failedCount = $result['failed'] ?? 0;
            $alreadyEnrichedCount = $result['already_enriched'] ?? 0;

            // Log: selesai berhasil
            Log::info('ContactManagement: Enrichment kontak selesai', [
                'job_id' => $this->jobId,
                'user_id' => $this->user->id,
                'enriched' => $enrichedCount,
                'failed' => $failedCount,
                'already_enriched' => $alreadyEnrichedCount,
            ]);

            // TODO: Dispatch event/notification ke user
            // Example:
            // ContactEnrichmentCompleted::dispatch($this->user, [
            //     'enriched' => $enrichedCount,
            //     'already_enriched' => $alreadyEnrichedCount,
            // ]);
            //
            // Atau gunakan Notification:
            // $this->user->notify(new EnrichmentCompletedNotification($enrichedCount));

        } catch (\Throwable $exception) {
            // Log error
            Log::error('ContactManagement: Enrichment kontak gagal', [
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
        Log::error('ContactManagement: Enrichment kontak gagal setelah 2x retry', [
            'job_id' => $this->jobId,
            'user_id' => $this->user->id,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Notify user bahwa enrichment gagal
        // $this->user->notify(new EnrichmentFailedNotification());
    }
}
