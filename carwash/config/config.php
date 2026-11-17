<?php
// Adjust these to match your XAMPP setup.
define('BASE_URL', '/carwash');          // folder name inside htdocs
define('DB_HOST', 'localhost');
define('DB_NAME', 'carwash_db');
define('DB_USER', 'root');
define('DB_PASS', '');                   // XAMPP default is empty

define('ONLINE_HOLD_HOURS', 12);           // unpaid "pay online" bookings are cancelled after this many hours (0 = never)
define('RESCHEDULE_CUTOFF_HOURS', 2);    // a customer can move a booking only if it starts at least this many hours from now (0 = no limit)
define('REMEMBER_DAYS', 30);             // "Remember me" keeps a customer/staff logged in for this many days
define('MAX_ACTIVE_BOOKINGS', 3);        // a customer can have at most this many pending/confirmed bookings at once

define('SHOP_NAME', 'Eayila Carwash & Detailing Services');
// Shown in the site header/footer.
define('SHOP_ADDRESS', 'Brgy. 5 R. Vasquez St., Nasugbu, Batangas, Philippines 4231');
define('SHOP_PHONE', '0900 000 0000');
define('SHOP_EMAIL', 'hello@eayila.test');
define('OPEN_HOUR', 8);                  // 8:00 AM
define('CLOSE_HOUR', 18);                // 6:00 PM
define('SLOT_STEP_MIN', 30);             // slot granularity in minutes
define('MAX_BAYS', 5);                   // the shop has 5 wash bays
define('STAFF_SHARE_PERCENT', 40);       // staff get 40% of each shift's sales, the shop keeps 60%
define('SHIFT_CUTOFF_HOUR', 12);         // payments before 12:00 NN count to the morning shift, from 12:00 on to the afternoon shift
// Online payment (GCash). The customer pays after the shop has set the vehicle size (so the price is final).
define('ONLINE_DEPOSIT_PERCENT', 50);    // deposit when paying online; the rest is paid at the shop. Set 100 to allow full payment only.
define('SHOP_GCASH_NAME', 'Eayila Carwash');        // shown to customers who pay online
define('SHOP_GCASH_NUMBER', '0900 000 0000');       // replace with the shop's real GCash number
// Optional: put the shop's GCash QR picture at assets/img/gcash-qr.png and it will be shown too.
date_default_timezone_set('Asia/Manila');

