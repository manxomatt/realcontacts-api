# RealContacts API — Copilot Instructions

## Project Context
REST API untuk aplikasi caller ID & spam protection Indonesia

## Stack
- Laravel 13
- PHP 8.5
- SQLite
- Redis
- VPS Ubuntu 22.04

## PHP 8.5 Preferences
- Gunakan pipe operator |> untuk data transformation
- Gunakan array_first() / array_last() bukan reset() / end()
- Gunakan clone() with properties untuk immutable updates
- Tambahkan #[\NoDiscard] untuk method yang return value-nya wajib dipakai

## Laravel 13 Preferences
- Gunakan PHP Attributes untuk Model (#[Fillable], #[Cast], dll)
- Gunakan Queue::route() untuk job routing di AppServiceProvider
- Semua response via API Resource
- Validasi via Form Request class
- Business logic di Service class, bukan Controller


## Coding Standards
- Selalu gunakan Laravel Resource untuk API response
- Selalu validasi request menggunakan Form Request class
- Gunakan Service class untuk business logic, bukan di Controller
- Semua response dalam format: {success, data, message}
- Gunakan Repository pattern untuk database query
- Tambahkan komentar dalam Bahasa Indonesia

## Response Format Standar
{
  "success": true,
  "data": {},
  "message": "string"
}

## Naming Convention
- Controller: PhoneLookupController
- Service: PhoneLookupService  
- Resource: PhoneNumberResource
- Request: LookupPhoneRequest
