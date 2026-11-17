# CarWash Reservation and Management Web System (starter code)

IT 314 project for **Eayila Carwash & Detailing Services**. Stack: HTML, CSS, JavaScript, PHP, MySQL (XAMPP). UI uses Bootstrap 5.0.2 from a CDN (needs internet; to work offline, download Bootstrap and change the two links in `includes/header.php` and `includes/footer.php`).

## Run it (XAMPP)
1. Copy this `carwash/` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin` → **Import** → choose `database/carwash_db.sql` → Go.
4. Check `config/config.php` (DB user/password, `BASE_URL`).
5. Visit `http://localhost/carwash/`.

**Prices:** services and per-size prices come from the Eayila rate card (seeded in `database/carwash_db.sql`). Re-import the schema to load them; edit later in Admin > Manage Packages. Service durations are estimates - adjust them to real times.

Demo logins: `owner` / `Owner123`, `admin` / `Admin123`, `staff` / `Staff123`, and `customer@eayila.test` / `Admin@123`. This one file creates everything (tables, demo logins, rate card, 5 bays). Importing it again resets the database to a clean start, so any bookings you added are erased.
Owner, admin and staff all have the same privileges (the admin panel); the list lives in `STAFF_ROLES` in `includes/bootstrap.php`.
Change these passwords before any real use.

## What is included (mapped to the proposal)
| Proposal module | Files |
|---|---|
| Authentication & role-based access | `login.php`, `register.php`, `logout.php`, `includes/bootstrap.php` (`require_role`) |
| Online reservation & scheduling | `customer/book.php`, `customer/dashboard.php` (cancel), `api/availability.php`, `includes/scheduling.php` |
| Real-time queue & bay monitoring | `queue.php`, `api/queue.php` (auto-refresh every 10 s) |
| Promo & package management | `admin/packages.php` (price per vehicle size), `admin/promos.php`, rate card view in `index.php`, helpers in `includes/catalog.php` |
| Admin panel (CRUD, status flow) | `admin/dashboard.php`, `admin/reservations.php` |
| Payment & billing | "Complete & pay" in `admin/reservations.php`, `receipt.php` |
| Reports & analytics | `admin/reports.php` (daily sales, by package, top customers, CSV) |
| Vehicle photos + size check (customer uploads 1-4 photos; owner/admin/staff pick the size) | `customer/book.php` (photo upload), `includes/photos.php`, `photo.php` (private viewer), size picker in `includes/reservations_panel_view.php` / `_post.php` |
| Walk-in customers (staff) | `admin/walkin.php` (no account needed; shown with a Walk-in badge in Reservations) |
| Messages (customer <-> shop) | `customer/messages.php`, `admin/messages.php` (shared inbox for owner/admin/staff), `api/messages.php`, `assets/js/chat.js`, `includes/messages.php` |
| Customer feedback & ratings (1-5 stars + comment after a completed booking; staff reply / hide; shown on the home page) | `customer/reviews.php`, `admin/reviews.php`, `includes/reviews.php`, "Rate" button in `customer/dashboard.php`, reviews section in `index.php` |
| Customer profile (edit name/phone, change password, saved vehicles suggested when booking) | `customer/profile.php`, `customer_vehicles` table, plate suggestions in `customer/book.php` |
| Staff payroll (owner/admin only). Each day has a **morning** and an **afternoon** shift (payments before `SHIFT_CUTOFF_HOUR`, default 12:00, count to the morning). 40% of each shift's sales (`STAFF_SHARE_PERCENT`) is split equally among the employees marked present **for that shift**; the shop keeps 60%. Nobody marked = no pay. Pay is released on the **15th** (1st-15th) and at **month end** (16th-last day); "Mark as paid" saves the amounts and locks attendance for those dates (can be undone) | `admin/payroll.php` (tabs: Payroll report, 15th & month-end pay, Daily attendance, Employees), `includes/payroll.php`, tables `employees`, `attendance` (per shift), `payouts` |
| Payments: **cash at the shop** (staff enter the cash received, the system shows the change; GCash/card need a reference no.) and **online GCash** (customer picks "Pay online" when booking; after the shop sets the vehicle size they send a deposit (`ONLINE_DEPOSIT_PERCENT`, default 50%) or the full price with the GCash reference no. and an optional screenshot; staff **Confirm** or **Reject** under Reservations > Verify payments, and an online booking can only be accepted after its payment is verified). The sale is recorded when the service is **completed**, with verified online money credited and only the balance collected, so reports and payroll follow the completion time. Cancelling a booking that already paid online makes it a **refund due** (Reservations > Refunds): staff mark it Refunded or Kept. Set the shop's GCash name/number in `config/config.php` (optional QR picture: `assets/img/gcash-qr.png`) | `customer/book.php`, `customer/dashboard.php`, `includes/online_payments.php`, `includes/reservations_panel_post.php` / `_view.php`, `receipt.php`, `proof.php`, tables `online_payments`, `payments` |
| Automated notifications | `notify()` in `includes/bootstrap.php` logs to the `notifications` table; add real email/SMS where the TODO is |

## Security already in place
Prepared statements (PDO), `password_hash`, CSRF tokens on every form, session ID regeneration at login, output escaping, role checks on every admin page, transaction + row lock to prevent double-booking.

## Ideas for next weeks (timeline)
- Week 7: connect a real SMS gateway/email (PHPMailer) inside `notify()`.
- Staff/owner user management page, bay management page.
- Charts library (Chart.js) in `admin/reports.php`.

## Vehicle photos
Photos are saved in `uploads/vehicles/` (blocked from direct access by `.htaccess`, shown only through `photo.php`). If photos fail to upload, raise `upload_max_filesize` and `post_max_size` in XAMPP's `php.ini`; the booking page already shrinks photos in the browser first.

## Changes in this version (bug-fix pass)
- Tables scroll sideways on phones (`.table-responsive.card` no longer clips).
- MySQL time zone is set to +08:00 in `includes/db.php` (matches PHP `Asia/Manila`; needed on hosts running in UTC).
- Schema upgrades run once; `config/.schema_ok` records it. Delete that file if you change the database structure by hand.
- `notifications.message` is now VARCHAR(500) (existing databases are upgraded automatically) and long messages are trimmed.
- "Complete & pay" can no longer run twice (conditional update + try/catch + double-click guard).
- Booking limits: `MAX_ACTIVE_BOOKINGS` per customer and `ONLINE_HOLD_HOURS` auto-cancel of unpaid online bookings (both in `config/config.php`). Only listed time slots are accepted.
- Queue shows accepted bookings only; the wait estimate counts bookings due within the next hour.
- Faster pages: availability slots and the bay list in Reservations use one query instead of hundreds.
- Login: 5 failed attempts pause logging in for 5 minutes. Logout is now a POST button. Session cookie gets `secure` on HTTPS.
- CSV exports are protected against spreadsheet formulas; photo size cap (40 MP); promo date validation; duplicate-review and walk-in closing-time checks; warning messages show in yellow; the chosen time slot is kept after a failed booking.
- Still recommended before real use: change the demo passwords, use HTTPS, host Bootstrap/Chart.js locally if the shop's internet is unreliable.

## Remember me + customer reschedule
- **Remember me**: tick the box on the login page to stay logged in for `REMEMBER_DAYS` (30) days. Only a hash of the token is stored (table `auth_tokens`, created automatically on first use; also in `database/carwash_db.sql` for fresh installs). Logging out or changing the password removes the saved tokens.
- **Reschedule**: customers see a *Reschedule* button on My bookings for pending/confirmed bookings (`customer/reschedule.php`). They pick a new date and slot; the same bay is kept when free, otherwise another free bay is assigned. Service, vehicle, price, promo and any online payment stay attached. Changes close `RESCHEDULE_CUTOFF_HOURS` (2) before the start time (set 0 for no limit) - both in `config/config.php`.
