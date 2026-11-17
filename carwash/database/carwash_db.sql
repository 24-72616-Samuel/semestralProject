-- CarWash Reservation and Management Web System
-- Eayila Carwash & Detailing Services (IT 314)
-- Import in phpMyAdmin (XAMPP) or: mysql -u root < database/carwash_db.sql
-- ONE FILE: tables, demo logins (owner, admin, staff, customer), rate card and bays.
-- Warning: importing drops and recreates the tables, so it erases existing data.

CREATE DATABASE IF NOT EXISTS carwash_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE carwash_db;

DROP TABLE IF EXISTS online_payments, payouts, attendance, employees, customer_vehicles, reviews, reservation_photos, messages, notifications, payments, reservations, promos, package_prices, packages, bays, users;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(30) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','staff','customer') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- "Remember me" login tokens (only a hash of the token is stored)
CREATE TABLE auth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  selector CHAR(24) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_exp (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE bays (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Services from the Eayila rate card. Price depends on vehicle size -> see package_prices.
CREATE TABLE packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  category ENUM('car','motorcycle','special') NOT NULL DEFAULT 'car',
  duration_min INT NOT NULL DEFAULT 30,
  price_is_starting TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = "starts at" price
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- size keys: small, medium, large, xlarge, xxl | moto_small, moto_large, ebike, tricycle, jetski | ufo
CREATE TABLE package_prices (
  package_id INT NOT NULL,
  size VARCHAR(20) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (package_id, size),
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE promos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  description TEXT,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,                      -- NULL for walk-in customers without an account
  customer_name VARCHAR(100) DEFAULT NULL,       -- walk-in customers only
  customer_phone VARCHAR(30) DEFAULT NULL,       -- walk-in customers only
  source ENUM('online','walkin') NOT NULL DEFAULT 'online',
  payment_mode ENUM('shop','online') NOT NULL DEFAULT 'shop',   -- how the customer chose to pay: at the shop, or online (GCash) before the wash
  package_id INT NOT NULL,
  bay_id INT DEFAULT NULL,
  promo_id INT DEFAULT NULL,
  vehicle_plate VARCHAR(20) NOT NULL,
  vehicle_type VARCHAR(20) DEFAULT NULL,               -- size key from package_prices; NULL until the shop checks the customer's photos and picks it
  scheduled_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  status ENUM('pending','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  total_price DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sched (scheduled_at, end_at),
  INDEX idx_status (status),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (bay_id) REFERENCES bays(id),
  FOREIGN KEY (promo_id) REFERENCES promos(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE reservation_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  file_name VARCHAR(100) NOT NULL,                 -- file inside uploads/vehicles/ (served only through photo.php)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_res (reservation_id),
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per finished service = the sale. Recorded when the service is completed, so paid_at is the completion time
-- (payroll uses it for the morning/afternoon shift). amount = full price; prepaid = part already paid online (verified);
-- the rest (amount - prepaid) was paid at the shop with `method`.
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL UNIQUE,
  amount DECIMAL(10,2) NOT NULL,
  prepaid DECIMAL(10,2) NOT NULL DEFAULT 0,
  method ENUM('cash','gcash','card') NOT NULL DEFAULT 'cash',
  cash_received DECIMAL(10,2) DEFAULT NULL,
  change_given DECIMAL(10,2) DEFAULT NULL,
  reference_no VARCHAR(40) DEFAULT NULL,             -- GCash / card reference typed by staff at the counter
  receipt_no VARCHAR(30) NOT NULL UNIQUE,
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id)
) ENGINE=InnoDB;

-- Online (GCash) payments made by customers before the wash: a deposit or the full price.
-- pending -> verified (staff checked GCash) or rejected. If the booking is cancelled: verified -> refund_due -> refunded / forfeited
-- (shop keeps it); pending -> void.
CREATE TABLE online_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  kind ENUM('deposit','full') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  reference_no VARCHAR(40) NOT NULL,
  proof_file VARCHAR(100) DEFAULT NULL,              -- optional screenshot in uploads/vehicles/ (served only through proof.php)
  status ENUM('pending','verified','rejected','void','refund_due','refunded','forfeited') NOT NULL DEFAULT 'pending',
  note VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  verified_at DATETIME DEFAULT NULL,
  verified_by INT DEFAULT NULL,
  resolved_at DATETIME DEFAULT NULL,
  INDEX idx_res (reservation_id),
  INDEX idx_status (status),
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  channel ENUM('email','sms','app') NOT NULL DEFAULT 'app',
  message VARCHAR(500) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,                 -- whose conversation this belongs to (one thread per customer)
  sender_id INT NOT NULL,                   -- the customer, or the owner/admin/staff who wrote it
  from_staff TINYINT(1) NOT NULL DEFAULT 0,
  body VARCHAR(1000) NOT NULL,
  read_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_thread (customer_id, id),
  FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Customer feedback: one review per completed booking (1-5 stars + optional comment). Staff can reply or hide it.
CREATE TABLE reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL UNIQUE,
  user_id INT NOT NULL,
  package_id INT NOT NULL,
  rating TINYINT NOT NULL,
  comment VARCHAR(1000) DEFAULT NULL,
  staff_reply VARCHAR(1000) DEFAULT NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,           -- 0 = hidden from the public page
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_visible (is_visible, id),
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Saved vehicles on the customer profile (suggested as plate numbers when booking).
CREATE TABLE customer_vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  plate VARCHAR(20) NOT NULL,
  label VARCHAR(60) DEFAULT NULL,                     -- e.g. "Red Vios"
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_plate (user_id, plate),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Payroll: employees who share the staff 40% (they do not need a login), who came in each shift of each day
-- (morning / afternoon), and the payouts already released on the 15th and at month end.
CREATE TABLE employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE attendance (
  employee_id INT NOT NULL,
  work_date DATE NOT NULL,
  shift ENUM('morning','afternoon') NOT NULL DEFAULT 'morning',
  marked_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, work_date, shift),
  INDEX idx_date (work_date),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per employee per pay period (1st-15th = "15th pay", 16th-last day = "month-end pay"). amount = what was paid.
CREATE TABLE payouts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  paid_by INT DEFAULT NULL,
  UNIQUE KEY uq_period (employee_id, period_start, period_end),
  INDEX idx_period (period_start, period_end),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed data. Logins: owner / Owner123, admin / Admin123, staff / Staff123, customer@eayila.test / Admin@123
INSERT INTO users (name,email,phone,password_hash,role) VALUES
('Owner','owner','09170000001','$2y$10$bjmIxKPApX97AdEvOTAhhu1f7MtbOhmWD5LRBCVcPASFL9DTJYii6','owner'),
('Admin','admin','09170000002','$2y$10$lkUV6rcHEAnNJ4IsqJ7ejuJcY5K7Dyb3x4U8olpp3xTPdE8rxCNUq','admin'),
('Staff','staff','09170000004','$2y$10$u5UfO9/.HnGYlXlnpOFbouf3eDfWitw2G/DZ0eLSSoHx87gorHQ9m','staff'),
('Demo Customer','customer@eayila.test','09170000003','$2y$10$wtU9kWoeQUBaXF.lYRrhROmXu/0sg8ODxPk4Z96rzAeMCu/4yfsOK','customer');

INSERT INTO bays (name) VALUES ('Bay 1'),('Bay 2'),('Bay 3'),('Bay 4'),('Bay 5');

INSERT INTO packages (id,name,description,category,duration_min,price_is_starting) VALUES
( 1,'Premium Carwash','Exterior wash, rinse and hand dry.','car',45,0),
( 2,'Premium Wax Hand Buff Package','Premium wash plus hand wax and hand buff.','car',90,0),
( 3,'Premium Wax Machine Wax Package','Premium wash plus machine wax and polish.','car',120,0),
( 4,'Glass Detailing / Rainmarks Removal Package','Glass cleaning and water/rain mark removal.','car',90,0),
( 5,'Engine Detailing Package','Engine bay degrease, clean and dress.','car',90,0),
( 6,'Remove Asphalt','Asphalt and tar removal. Price starts at the amount shown and may vary with the extent of work.','car',60,1),
( 7,'Remove / Install Seat Cover','Seat cover removal or installation (flat rate).','car',60,0),
( 8,'Interior Detailing Package','Full interior deep clean and detailing.','car',240,0),
( 9,'Exterior Detailing Package','Full exterior deep clean and detailing.','car',240,0),
(10,'Headlight Detailing Package','Headlight restoration and polish.','car',60,0),
(11,'Bac to Zero','Bac to Zero service.','car',60,0),
(12,'Motorcycle Package 1 - Premium Wash','Premium wash.','motorcycle',30,0),
(13,'Motorcycle Package 2','Premium wash, spray wax, tire black.','motorcycle',40,0),
(14,'Motorcycle Package 3','Premium wash, spray wax, tire black, back to black.','motorcycle',45,0),
(15,'Motorcycle Package 4','Premium wash, spray wax, tire black, back to black, degreaser.','motorcycle',60,0),
(16,'Motorcycle Package 5','Premium wash, back to black, machine buff.','motorcycle',90,0),
(17,'UFO / Helicopter Service','Flat-rate service for UFOs and helicopters.','special',240,0);

-- Car sizes: small, medium, large, xlarge, xxl
INSERT INTO package_prices (package_id,size,price) VALUES
( 1,'small',180),( 1,'medium',220),( 1,'large',240),( 1,'xlarge',260),( 1,'xxl',300),
( 2,'small',380),( 2,'medium',540),( 2,'large',580),( 2,'xlarge',620),( 2,'xxl',670),
( 3,'small',1000),( 3,'medium',1300),( 3,'large',1600),( 3,'xlarge',1900),( 3,'xxl',2200),
( 4,'small',580),( 4,'medium',720),( 4,'large',840),( 4,'xlarge',960),( 4,'xxl',1100),
( 5,'small',800),( 5,'medium',900),( 5,'large',1000),( 5,'xlarge',1150),( 5,'xxl',1250),
( 6,'small',300),( 6,'medium',300),( 6,'large',300),( 6,'xlarge',300),( 6,'xxl',300),
( 7,'small',150),( 7,'medium',150),( 7,'large',150),( 7,'xlarge',150),( 7,'xxl',150),
( 8,'small',3500),( 8,'medium',4500),( 8,'large',5000),( 8,'xlarge',6000),( 8,'xxl',7000),
( 9,'small',3000),( 9,'medium',4000),( 9,'large',4500),( 9,'xlarge',5000),( 9,'xxl',5500),
(10,'small',950),(10,'medium',1000),(10,'large',1100),(10,'xlarge',1200),(10,'xxl',1300),
(11,'small',300),(11,'medium',350),(11,'large',400),(11,'xlarge',500),(11,'xxl',600);

-- Motorcycles & others: moto_small, moto_large, ebike, tricycle, jetski
INSERT INTO package_prices (package_id,size,price) VALUES
(12,'moto_small',100),(12,'moto_large',150),(12,'ebike',170),(12,'tricycle',170),(12,'jetski',10000),
(13,'moto_small',150),(13,'moto_large',250),
(14,'moto_small',180),(14,'moto_large',280),
(15,'moto_small',250),(15,'moto_large',320),
(16,'moto_small',700),(16,'moto_large',800),
(17,'ufo',5999.10);

-- Sample promo, switched OFF so booking totals match the rate card. Turn it on in Admin > Promos.
INSERT INTO promos (title,description,discount_percent,start_date,end_date,is_active) VALUES
('Sample Promo','10% off on all services.',10,'2026-01-01','2026-12-31',0);

-- Special (UFO / Helicopter) service is hidden by default. Re-enable it in Admin > Manage Packages.
UPDATE packages SET is_active = 0 WHERE id = 17;
