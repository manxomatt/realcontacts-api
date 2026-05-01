# RealContacts API - Complete Routes & Testing Guide

## 📋 Overview

This document contains complete API routes mapping, Postman collection guide, and end-to-end testing instructions for RealContacts REST API (Laravel 13 + PHP 8.5).

**Total Endpoints:** 35 routes
**Coverage:** Public, Protected (user), Admin

---

## 🗺️ Routes Summary

### 1. Authentication Routes (Public)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| POST | `/api/auth/register` | Register new user | ✗ |
| POST | `/api/auth/otp/request` | Request OTP code | ✗ |
| POST | `/api/auth/otp/resend` | Resend OTP code | ✗ |
| POST | `/api/auth/verify-otp` | Verify OTP & Login | ✗ |
| GET | `/api/auth/me` | Get current user | ✓ |
| POST | `/api/auth/logout` | Logout user | ✓ |

### 2. User Management Routes (Protected)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/user/profile` | Get full profile + stats | ✓ |
| PATCH | `/api/user/profile` | Update user profile | ✓ |
| GET | `/api/user/number-health` | Check account health | ✓ |
| GET | `/api/user/contributions` | Get contribution stats | ✓ |
| GET | `/api/user/leaderboard` | Get leaderboard (monthly) | ✓ |

### 3. Phone Lookup Routes (Protected)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/phone/{number}` | Single number lookup | ✓ |
| GET | `/api/phone/search?query=X` | Search by business name | ✓ |
| GET | `/api/phone/recent?limit=20` | Recent lookups history | ✓ |
| POST | `/api/phone/batch` | Batch lookup (Premium) | ✓ Premium |

### 4. Spam Reports Routes (Protected)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/spam-reports/{phone}` | Reports for phone number | ✓ |
| POST | `/api/spam-reports` | Submit spam report | ✓ |
| POST | `/api/spam-reports/{id}/vote` | Upvote/downvote report | ✓ |
| GET | `/api/spam-reports/me/reports` | My submitted reports | ✓ |

### 5. Call Blocker Routes (Protected)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/blocked-numbers` | Get user blocklist | ✓ |
| POST | `/api/blocked-numbers` | Add to blocklist | ✓ |
| GET | `/api/blocked-numbers/{number}/check` | Check if blocked | ✓ |
| DELETE | `/api/blocked-numbers/{number}` | Remove from blocklist | ✓ |
| POST | `/api/blocked-numbers/import` | Bulk import | ✓ |

### 6. Business Profiles Routes (Protected)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/business-profiles/{number}` | Get business profile | ✓ |
| POST | `/api/business-profiles/{number}/claim` | Claim profile | ✓ |
| PATCH | `/api/business-profiles/{id}` | Update profile (owner) | ✓ |
| POST | `/api/business-profiles/{number}/rate` | Rate business | ✓ |

### 7. Admin Spam Moderation Routes (Admin only)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/spam/moderation/pending` | List pending reports | ✓ Admin |
| PATCH | `/api/spam/moderation/{id}/approve` | Approve report | ✓ Admin |
| PATCH | `/api/spam/moderation/{id}/reject` | Reject report | ✓ Admin |
| GET | `/api/spam/moderation/stats` | Moderation statistics | ✓ Admin |

### 8. Admin Dashboard Routes (Admin only)

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/admin/dashboard/stats` | Overall statistics | ✓ Admin |
| GET | `/api/admin/dashboard/activity?minutes=30` | Real-time activity | ✓ Admin |
| GET | `/api/admin/dashboard/top-spam-numbers` | Top 20 spam numbers | ✓ Admin |
| GET | `/api/admin/dashboard/reporter-leaderboard` | Top 10 reporters | ✓ Admin |

---

## 📮 Postman Collection

### Installation

1. Download Postman: https://www.postman.com/downloads/
2. Import collection: `RealContacts-API.postman_collection.json`
   - File → Import → Select `RealContacts-API.postman_collection.json`

### Setup Environment Variables

Before running requests, configure environment variables:

1. Click "Environments" tab
2. Create new environment or use default:
   - `base_url` = `http://localhost:8000`
   - `auth_token` = (empty - auto-filled after login)
   - `phone_number` = (empty - auto-filled after register)

### Collection Structure

```
RealContacts API/
├── Authentication (6 endpoints)
├── User Management (5 endpoints)
├── Phone Lookup (4 endpoints)
├── Spam Reports (4 endpoints)
├── Call Blocker (5 endpoints)
├── Business Profiles (4 endpoints)
├── Admin - Spam Moderation (4 endpoints)
└── Admin - Dashboard (4 endpoints)
```

### Test Flow in Postman

**Recommended workflow for manual testing:**

1. **Register** → Create new user
2. **Request OTP** → Get OTP code
3. **Verify OTP & Login** → Extract `auth_token` (auto-saved to env var)
4. **All subsequent requests** → Use extracted token (auto-applied)

---

## 🧪 End-to-End Testing

### Running Tests

```bash
# Setup test database
php artisan migrate --env=testing

# Run all API tests
php artisan test tests/Feature/Api/EndToEndApiTest.php

# Run specific test
php artisan test tests/Feature/Api/EndToEndApiTest.php --filter=test_lookup_single_phone

# With verbose output
php artisan test tests/Feature/Api/EndToEndApiTest.php -vv

# Generate coverage report
php artisan test tests/Feature/Api/EndToEndApiTest.php --coverage
```

### Test Coverage

**39 test cases** covering:

#### Public Endpoints (3 tests)
- ✓ Register new user
- ✓ Request OTP
- ✓ Resend OTP

#### Authentication (3 tests)
- ✓ Get current user profile
- ✓ Logout user
- ✓ Unauthenticated request returns 401

#### User Management (5 tests)
- ✓ Get user profile with stats
- ✓ Update user profile
- ✓ Get number health check
- ✓ Get contributions
- ✓ Get leaderboard with filters

#### Phone Lookup (4 tests)
- ✓ Lookup single phone number
- ✓ Search phones by query
- ✓ Get recent lookups
- ✓ Batch lookup (Premium) returns 403 for free users

#### Premium Batch Lookup (1 test)
- ✓ Premium user can batch lookup

#### Spam Reports (4 tests)
- ✓ Get reports for phone
- ✓ Submit spam report
- ✓ Vote on report (upvote/downvote)
- ✓ Get user's submitted reports

#### Call Blocker (5 tests)
- ✓ Get blocklist
- ✓ Add to blocklist
- ✓ Check if blocked
- ✓ Remove from blocklist
- ✓ Bulk import

#### Business Profiles (3 tests)
- ✓ Get business profile
- ✓ Claim profile
- ✓ Rate business

#### Admin Moderation (5 tests)
- ✓ Non-admin gets 403 on moderation endpoints
- ✓ Admin can get pending reports
- ✓ Admin can approve report
- ✓ Admin can reject report
- ✓ Admin can view moderation stats

#### Admin Dashboard (4 tests)
- ✓ Get overall stats (users, phones, reports, lookups)
- ✓ Get real-time activity (15/30/60 min options)
- ✓ Get top 20 spam numbers
- ✓ Get top 10 reporter leaderboard

#### Error Handling (3 tests)
- ✓ Invalid phone format returns 422
- ✓ Duplicate report returns 422
- ✓ Unauthenticated request returns 401

---

## 📊 Response Format Standard

All API responses follow consistent format:

### Success Response (2xx)
```json
{
  "success": true,
  "message": "Operasi berhasil",
  "data": {
    "id": 1,
    "phone_number": "+6281234567890"
  }
}
```

### Error Response (4xx/5xx)
```json
{
  "success": false,
  "message": "Error message",
  "errors": {
    "phone_number": ["Format nomor telepon tidak valid"],
    "otp_code": ["OTP sudah expired"]
  }
}
```

### Status Codes
| Code | Meaning |
|------|---------|
| 200 | OK |
| 201 | Created |
| 204 | No Content |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 422 | Unprocessable Entity (validation error) |
| 429 | Too Many Requests (rate limited) |
| 500 | Server Error |

---

## 🔐 Authentication

### Bearer Token

All protected endpoints require `Authorization` header:

```
Authorization: Bearer {token}
```

Postman automatically adds this from `auth_token` env variable.

### Admin Access

Admin endpoints require:
1. Valid Sanctum token
2. User with `is_admin = true`

Failure returns `403 Forbidden`

---

## ⚡ Performance Features

### Caching Strategy

| Endpoint | TTL | Cache Key |
|----------|-----|-----------|
| `/api/phone/{number}` | 1h-24h | `phone:{number}` |
| `/api/admin/dashboard/stats` | 5 min | `dashboard:stats:v1` |
| `/api/admin/dashboard/activity` | 1 min | `dashboard:activity:*` |
| `/api/admin/dashboard/top-spam-numbers` | 5 min | `dashboard:top_spam:v1` |
| `/api/admin/dashboard/reporter-leaderboard` | 5 min | `dashboard:leaderboard:v1` |

### Rate Limiting

| Endpoint | Limit |
|----------|-------|
| `/api/auth/otp/*` | 3 requests / 1 minute |
| Login attempts | 5 / 15 minutes |
| General API | 60 / 1 minute (free), 300 / 1 minute (premium) |

---

## 🗂️ Project Files

**Routes:**
- `routes/api.php` — Complete route definitions

**Controllers:**
- `app/Http/Controllers/Api/Auth/` — Authentication
- `app/Http/Controllers/Api/User/` — User management
- `app/Http/Controllers/Api/Phone/` — Phone lookup
- `app/Http/Controllers/Api/Spam/` — Spam & moderation
- `app/Http/Controllers/Api/Block/` — Call blocker
- `app/Http/Controllers/Api/Business/` — Business profiles
- `app/Http/Controllers/Api/Admin/` — Admin dashboard

**Collections & Postman:**
- `RealContacts-API.postman_collection.json` — Import into Postman

**Testing:**
- `tests/Feature/Api/EndToEndApiTest.php` — 39 comprehensive tests

---

## 🚀 Quick Start

### 1. Start Laravel Server
```bash
php artisan serve
# or
php artisan serve --host=0.0.0.0 --port=8000
```

### 2. Run Migrations
```bash
php artisan migrate
```

### 3. Import Postman Collection
- Open Postman
- File → Import → `RealContacts-API.postman_collection.json`

### 4. Test Workflow
1. Click **Authentication** folder
2. Run **Register** endpoint
3. Run **Request OTP** endpoint
4. Run **Verify OTP & Login** (token auto-saved)
5. Try other endpoints (token auto-applied)

### 5. Run E2E Tests
```bash
php artisan test tests/Feature/Api/EndToEndApiTest.php
```

---

## 📝 Common Queries

### Q: How do I test batch lookup (Premium feature)?
A: Create user with `is_premium = true` via factory or database, then request `/api/phone/batch`

### Q: How do I test admin endpoints?
A: Create user with `is_admin = true`, get token, add to Authorization header

### Q: What's the OTP format?
A: Any 6-digit code. For testing, default is `123456`

### Q: Can I test with actual phone numbers?
A: Yes, use real Indonesian format: `+628XXXXXXXXXX`

### Q: Which headers are required?
A: Only `Authorization: Bearer {token}` for protected routes (optional for public)

---

## 📞 Support

**API Documentation:** Check inline controller comments
**Test Reference:** `tests/Feature/Api/EndToEndApiTest.php`
**Postman Collection:** `RealContacts-API.postman_collection.json`

---

*Last Updated: May 1, 2026*
*RealContacts API v1.0 — Complete Implementation*
