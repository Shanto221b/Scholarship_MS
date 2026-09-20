# ScholarHub — Scholarship Management System

PHP + MySQL web application. Students register, browse scholarships, pay the
application fee (bKash / Nagad / Rocket / card) and apply; admins create
scholarships, review applications, and manage payments and invoices.

Folder: `scholarhub` · Database: `scholarhub_db`

## চালানোর নিয়ম (XAMPP)

1. zip extract করে `scholarhub` ফোল্ডারটা `C:\xampp\htdocs\` এর ভেতরে রাখো।
2. XAMPP Control Panel থেকে **Apache** আর **MySQL** Start করো।
3. Browser-এ যাও: `http://localhost/scholarhub/`
4. **Setup** পেজ খুলবে → **Install database** চাপো।
   - আগের ScholarHub ভার্সনের `scholarhub_db` থাকলেও সমস্যা নেই — নিজে থেকেই
     Setup পেজে নিয়ে যাবে, Install চাপলে নতুন টেবিলসহ আপডেট হয়ে যাবে।
   - অথবা phpMyAdmin → Import দিয়ে `database/scholarhub_db.sql` import করো।
5. Login:

| Role    | Username | Password     |
|---------|----------|--------------|
| Admin   | Admin1   | adminpass1   |  (Admin1 to Admin5)
| Student | std1     | stdpass1     |  (std1 to std5, or email e.g. karim@example.com)

নতুন Admin account বানাতে Register → Admin ট্যাবে code: `SH-ADMIN-2026`

## Payment test credentials (PAYMENT_MODE = 'test')

কোনো আসল টাকা কাটে না। Checkout পেজে এগুলো লেখাও থাকে।

| Method | What to enter |
|--------|---------------|
| bKash  | any valid number (01XXXXXXXXX) → 6-digit code shown in the SMS pop-up → PIN `12121` |
| Nagad  | any valid number → code → PIN `1234` |
| Rocket | any valid number → code → PIN `1234` |
| Card   | `4242 4242 4242 4242`, any future expiry, any CVV → 3-D Secure code |
| Failures to try | wallet `01700000000` = insufficient balance · card `4000 0000 0000 0002` = declined · 3 wrong codes / PINs = payment failed · 10 minutes idle = checkout expired |

How it works: when a student applies for a scholarship with a fee, a
`payment` row is created (Initiated) and the student goes to the checkout.
The application is created **only after** the payment succeeds, in one
database transaction. A trigger gives every completed payment an invoice
number (`INV-2026-00001`) and blocks any change to a completed payment.

Going live needs a merchant account (bKash Tokenized Checkout, Nagad / Rocket
merchant API or a card gateway such as SSLCommerz). Only
`wallet_verify_pin()` and `card_charge()` in `includes/payment.php` need to
call the provider; everything else stays the same.

## Features

**Student**: dashboard · browse / search scholarships (live) · apply + pay
fee · My applications (print application copy) · Payments & invoices (view /
print / save as PDF) · Profile with photo, personal, contact, academic and
guardian details, profile completion, change password, sign out other
devices, activity timeline.

**Admin**: dashboard (pending, approved, fees collected, status chart, latest
payments) · global search · Applications with live filters, **bulk approve /
reject**, print · Review page with payment details · Scholarships (fee,
description) · Students · Departments · **Payments & invoices** (filters by
status / method / date, totals by method, **CSV export**, **printable
statement**, invoice for any payment) · Admin profile with photo.

**Everywhere**: light / dark mode (remembered in a cookie), dim-white
forest-green theme, AJAX forms without page reloads, toasts, print-friendly
pages, mobile layout.

## Database (`database/scholarhub_db.sql`)

- 8 tables: `users`, `department`, `admin`, `student`, `scholarship`,
  `application`, `payment`, `remember_token`
- IDs by triggers: `USR0001`, `DEP01`, `ADM001`, `STD0001`, `SCH001`,
  `APP0001`, `PAY000001`; invoice numbers `INV-YYYY-00001`
- Stored routines: `fn_get_cgpa`, `fn_app_count`, `fn_fee_collected`,
  `proc_update_status`, `proc_get_admin_name`; triggers `trg_check_slots`,
  `trg_payment_id`, `trg_payment_complete`

## Security

CSRF tokens on every form and checkout step · prepared statements · output
escaping · bcrypt passwords · checkout steps cannot be skipped · OTP stored as
a hash, PIN never stored, card: only brand + last 4 digits · students can only
see their own payments and invoices · uploads: only real JPG / PNG / WebP ≤ 2 MB,
random file names, PHP blocked in `uploads/` · CSV export protected against
formula injection · "Remember me" tokens hashed and rotated.

## Configuration: `config/config.php`

```php
define('DB_NAME', 'scholarhub_db');
define('ADMIN_REGISTRATION_CODE', 'SH-ADMIN-2026');
define('PAYMENT_MODE', 'test');
define('MERCHANT_NAME', 'ScholarHub Scholarship Office');   // shown on invoices
define('MERCHANT_ADDRESS', '...');
define('MERCHANT_EMAIL', '...');
```

## Troubleshooting

- **"Cannot connect to MySQL"**: start MySQL in XAMPP; check `DB_USER` / `DB_PASS`.
- **Profile photo won't upload**: make sure `uploads/avatars` is writable
  (the Setup page checks this). Enable `extension=fileinfo` in `php.ini` if it is off.
- **Reset all data**: log in as admin, open `install.php`, type `RESET`.

PHP 8.0+ (XAMPP 8.x), MySQL 5.7+/8 or MariaDB 10.2+.
