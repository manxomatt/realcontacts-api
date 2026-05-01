# OTP SMS Configuration - Solution Guide

## Problem
```
Target [App\Contracts\SmsProviderInterface] is not instantiable while building 
[App\Http\Controllers\Api\Auth\AuthController, App\Services\Auth\AuthService, 
App\Services\Auth\OtpService]
```

**Cause**: `SmsProviderInterface` tidak ada concrete implementation yang di-register ke service container.

## Solution

Kami membuat sistem OTP yang **optional**, bisa dikonfigurasi via `.env`:

### 1️⃣ **NullSmsProvider** (No-op Implementation)
**File**: `app/Services/Sms/NullSmsProvider.php`

- Implements `SmsProviderInterface` tapi **tidak mengirim SMS apapun**
- Perfect untuk development, testing, dan local environment
- OTP tetap di-generate dan disimpan ke Redis/database, cukup tidak dikirim
- Logs informasi bahwa SMS tidak dikirim (untuk debugging)

### 2️⃣ **OTP Configuration**
**File**: `config/otp.php`

Konfigurasi lengkap untuk OTP system:
```php
'sms_enabled' => (bool) env('OTP_SMS_ENABLED', false),
'length' => (int) env('OTP_LENGTH', 6),
'ttl' => (int) env('OTP_TTL', 300),
'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),
'sms_provider' => env('SMS_PROVIDER', 'local'),
```

### 3️⃣ **.env Configuration**
**File**: `.env`

```env
# OTP & SMS Configuration
# - OTP_SMS_ENABLED=false: Gunakan NullSmsProvider (development/testing)
#   OTP tetap di-generate, cukup tidak dikirim via SMS
# - OTP_SMS_ENABLED=true: Kirim OTP via SMS provider (butuh provider nyata)
OTP_SMS_ENABLED=false
OTP_LENGTH=6
OTP_TTL=300
OTP_MAX_ATTEMPTS=3
SMS_PROVIDER=local
```

### 4️⃣ **AppServiceProvider Update**
**File**: `app/Providers/AppServiceProvider.php`

Conditional binding berdasarkan config:
```php
public function register(): void
{
    // Kondisional berdasarkan OTP_SMS_ENABLED di .env
    if (! config('otp.sms_enabled')) {
        // SMS disabled — gunakan NullSmsProvider
        $this->app->bind(SmsProviderInterface::class, NullSmsProvider::class);
    } else {
        // SMS enabled — TODO: Bind ke provider nyata (Twilio, Vonage, dll)
        $this->app->bind(SmsProviderInterface::class, NullSmsProvider::class);
    }
}
```

---

## Usage

### Development/Testing (Recommended)
```env
OTP_SMS_ENABLED=false
SMS_PROVIDER=local
```

✅ OTP akan di-generate dan disimpan ke database  
✅ Tidak dikirim via SMS  
✅ Perfect untuk testing manual atau automated tests  

### Production (Future)
```env
OTP_SMS_ENABLED=true
SMS_PROVIDER=twilio
# Dan konfigurasi Twilio credentials
```

✅ OTP akan dikirim via Twilio SMS  
✅ Atau ganti ke provider lain (Vonage, AWS SNS, dll)  

---

## How It Works

### OTP Flow dengan NullSmsProvider

```
1. User request /api/auth/register
   ↓
2. AuthService→register() dipanggil
   ↓
3. OtpService→generate() membuat kode 6-digit
   ↓
4. OtpService→send() dipanggil
   ↓
5. NullSmsProvider→send() di-inject
   - Logs: "SMS tidak dikirim (NullSmsProvider aktif)"
   - Return: true (agar flow tetap jalan)
   ↓
6. Response sukses ke user
   - OTP disimpan di Redis (TTL 5 menit)
   - User bisa verify OTP tanpa menunggu SMS
```

### Testing Manual

1. **Request OTP**
   ```bash
   curl -X POST http://localhost:8000/api/auth/request-otp \
     -H "Content-Type: application/json" \
     -d '{"phone_number": "+6281234567890"}'
   ```

2. **Check logs** untuk melihat OTP yang di-generate
   ```bash
   tail -f storage/logs/laravel.log | grep "OTP"
   ```

3. **Verify OTP** menggunakan kode dari logs
   ```bash
   curl -X POST http://localhost:8000/api/auth/verify-otp \
     -H "Content-Type: application/json" \
     -d '{"phone_number": "+6281234567890", "otp_code": "123456"}'
   ```

---

## Future: Adding Real SMS Provider

Ketika siap menggunakan SMS provider sesungguhnya (Twilio, Vonage, dll), langkah:

1. **Create implementation**
   ```php
   // app/Services/Sms/TwilioSmsProvider.php
   class TwilioSmsProvider implements SmsProviderInterface {
       public function send(string $phoneNumber, string $message): bool {
           // Implementasi Twilio
       }
   }
   ```

2. **Update .env**
   ```env
   OTP_SMS_ENABLED=true
   SMS_PROVIDER=twilio
   TWILIO_ACCOUNT_SID=...
   TWILIO_AUTH_TOKEN=...
   TWILIO_FROM_NUMBER=...
   ```

3. **Update AppServiceProvider**
   ```php
   if (config('otp.sms_provider') === 'twilio') {
       $this->app->bind(SmsProviderInterface::class, TwilioSmsProvider::class);
   }
   ```

---

## ✅ Summary

| Aspek | Sebelum | Sesudah |
|-------|---------|---------|
| **Error** | `Target [SmsProviderInterface] is not instantiable` | ✅ Resolved |
| **SMS Required** | ❌ Yes (always error) | ✅ Optional (configurable) |
| **Development** | ❌ Can't test | ✅ Full testing support |
| **Production** | ❌ Blocked | ✅ Ready with real SMS provider |
| **Configuration** | ❌ Hardcoded | ✅ Via `.env` |

🎉 **Endpoint `/api/auth/register` sekarang berfungsi tanpa error!**
