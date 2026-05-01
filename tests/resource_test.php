<?php

/**
 * Script test manual untuk semua Resource — jalankan via:
 * php artisan tinker --execute="require base_path('tests/resource_test.php');"
 */

use App\Http\Resources\PhoneNumberResource;
use App\Http\Resources\PhoneNumberLookupResource;
use App\Http\Resources\SpamReportResource;
use App\Http\Resources\BusinessProfileResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\BlockedNumberResource;
use App\Http\Resources\PhoneNumberCollection;
use App\Http\Resources\SpamReportCollection;
use App\Http\Responses\ApiResponse;
use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\BlockedNumber;
use Illuminate\Http\Request;

$req = Request::create('/');

$sep = fn(string $title) => print "\n" . str_repeat('─', 60) . "\n[{$title}]\n" . str_repeat('─', 60) . "\n";
$pp  = fn(mixed $data)   => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// ── 1. PhoneNumberResource ──────────────────────────────────────────────────
$sep('1. PhoneNumberResource');
$phone = PhoneNumber::first();
try {
    $pp((new PhoneNumberResource($phone))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 2. PhoneNumberResource (with relations eager loaded) ────────────────────
$sep('2. PhoneNumberResource (with spamReports + businessProfile)');
$phoneLoaded = PhoneNumber::with(['spamReports', 'businessProfile'])->first();
try {
    $pp((new PhoneNumberResource($phoneLoaded))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 3. PhoneNumberLookupResource ────────────────────────────────────────────
$sep('3. PhoneNumberLookupResource');
$phoneFull = PhoneNumber::with(['spamReports', 'businessProfile'])->first();
try {
    $pp((new PhoneNumberLookupResource($phoneFull))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 4. SpamReportResource ───────────────────────────────────────────────────
$sep('4. SpamReportResource');
$report = SpamReport::first();
try {
    $pp((new SpamReportResource($report))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 5. SpamReportResource (with user loaded) ─────────────────────────────────
$sep('5. SpamReportResource (with user)');
$reportLoaded = SpamReport::with('user')->whereNotNull('user_id')->first();
try {
    $pp((new SpamReportResource($reportLoaded))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 6. BusinessProfileResource ──────────────────────────────────────────────
$sep('6. BusinessProfileResource');
$biz = BusinessProfile::first();
try {
    $pp((new BusinessProfileResource($biz))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 7. UserResource ─────────────────────────────────────────────────────────
$sep('7. UserResource (tanpa stats)');
$user = User::first();
try {
    $pp((new UserResource($user))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 8. UserResource (with spamReports loaded) ───────────────────────────────
$sep('8. UserResource (dengan stats)');
$userWithReports = User::with('spamReports')->first();
try {
    $pp((new UserResource($userWithReports))->toArray($req));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 9. PhoneNumberCollection (paginated) ────────────────────────────────────
$sep('9. PhoneNumberCollection (paginated)');
try {
    $paginated = PhoneNumber::paginate(5);
    $collection = new PhoneNumberCollection($paginated);
    // Simulasikan response JSON seperti controller
    $response = $collection->response($req)->getContent();
    $pp(json_decode($response));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 10. SpamReportCollection (paginated) ────────────────────────────────────
$sep('10. SpamReportCollection (paginated)');
try {
    $paginated = SpamReport::paginate(5);
    $collection = new SpamReportCollection($paginated);
    $response = $collection->response($req)->getContent();
    $pp(json_decode($response));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 11. ApiResponse::success ────────────────────────────────────────────────
$sep('11. ApiResponse::success');
try {
    $res = ApiResponse::success(['id' => 1, 'name' => 'Test'], 'Berhasil');
    $pp(json_decode($res->getContent()));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

// ── 12. ApiResponse::error + validationError + notFound ─────────────────────
$sep('12. ApiResponse — error variants');
try {
    $pp(json_decode(ApiResponse::error('Server error', 500)->getContent()));
    $pp(json_decode(ApiResponse::notFound()->getContent()));
    $pp(json_decode(ApiResponse::unauthorized()->getContent()));
    $pp(json_decode(ApiResponse::validationError(['phone' => ['Nomor tidak valid']])->getContent()));
    echo "✅ OK\n";
} catch (\Throwable $e) {
    echo "❌ {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "Test selesai.\n";
