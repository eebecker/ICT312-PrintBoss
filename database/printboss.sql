-- ============================================================
--  PrintBoss  |  3D Printing Business Manager
--  ICT312 Advanced Web Information Systems  |  Assignment 02
--  Group: Valerio Pinheiro (leader), Eduardo Becker, Nabil
--
--  Import with phpMyAdmin (WAMP) or:
--     mysql -u root < database/printboss.sql
--  Creates the database, all 12 tables and demo data.
--  Demo login:  demo@printboss.local  /  Demo1234!
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS printboss;
CREATE DATABASE printboss CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE printboss;

-- ------------------------------------------------------------
-- 1. users : login accounts (one account = one business)
-- ------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. profiles : business settings and calculator defaults
-- ------------------------------------------------------------
CREATE TABLE profiles (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  INT UNSIGNED NOT NULL,
  business_name            VARCHAR(120) NOT NULL DEFAULT '',
  business_abn             VARCHAR(20)  NOT NULL DEFAULT '',
  business_email           VARCHAR(190) NOT NULL DEFAULT '',
  business_phone           VARCHAR(40)  NOT NULL DEFAULT '',
  business_address         VARCHAR(255) NOT NULL DEFAULT '',
  country                  VARCHAR(60)  NOT NULL DEFAULT 'Australia',
  currency                 CHAR(3)      NOT NULL DEFAULT 'AUD',
  bank_name                VARCHAR(80)  NOT NULL DEFAULT '',
  bank_account_name        VARCHAR(120) NOT NULL DEFAULT '',
  bank_bsb                 VARCHAR(10)  NOT NULL DEFAULT '',
  bank_account_number      VARCHAR(20)  NOT NULL DEFAULT '',
  invoice_prefix           VARCHAR(10)  NOT NULL DEFAULT 'INV',
  gst_rate                 DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  default_gst_enabled      TINYINT(1)   NOT NULL DEFAULT 1,
  default_payment_terms    VARCHAR(60)  NOT NULL DEFAULT 'Payment due within 14 days',
  default_payment_instructions TEXT NULL,
  default_invoice_notes    TEXT NULL,
  default_electricity_cost DECIMAL(8,4) NOT NULL DEFAULT 0.3000,
  default_labour_rate      DECIMAL(8,2) NOT NULL DEFAULT 25.00,
  default_depreciation_rate DECIMAL(8,2) NOT NULL DEFAULT 0.50,
  default_profit_margin    DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  default_failed_risk      DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  onboarding_completed     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_profiles_user (user_id),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. customers : simple CRM
-- ------------------------------------------------------------
CREATE TABLE customers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  email           VARCHAR(190) NULL,
  phone           VARCHAR(40)  NULL,
  preferred_product_type VARCHAR(120) NULL,
  address_line_1  VARCHAR(120) NULL,
  address_line_2  VARCHAR(120) NULL,
  city            VARCHAR(80)  NULL,
  state           VARCHAR(40)  NULL,
  postcode        VARCHAR(12)  NULL,
  country         VARCHAR(60)  NULL DEFAULT 'Australia',
  notes           TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_customers_user (user_id),
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. inventory : filament rolls (raw material)
-- ------------------------------------------------------------
CREATE TABLE inventory (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  material_type    VARCHAR(40)  NOT NULL,
  colour           VARCHAR(40)  NULL,
  brand            VARCHAR(60)  NULL,
  supplier         VARCHAR(80)  NULL,
  purchase_price   DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_weight     INT UNSIGNED NOT NULL DEFAULT 1000,
  remaining_weight INT UNSIGNED NOT NULL DEFAULT 1000,
  min_stock_alert  INT UNSIGNED NOT NULL DEFAULT 200,
  notes            TEXT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_inventory_user (user_id),
  CONSTRAINT fk_inventory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. stock_items : finished products ready to sell
-- ------------------------------------------------------------
CREATE TABLE stock_items (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  product_name     VARCHAR(120) NOT NULL,
  sku              VARCHAR(40)  NULL,
  category         VARCHAR(60)  NULL,
  description      TEXT NULL,
  current_quantity INT NOT NULL DEFAULT 0,
  minimum_quantity INT NOT NULL DEFAULT 0,
  unit_cost        DECIMAL(10,2) NOT NULL DEFAULT 0,
  selling_price    DECIMAL(10,2) NOT NULL DEFAULT 0,
  location         VARCHAR(60)  NULL,
  status           ENUM('In Stock','Low Stock','Out of Stock','Inactive') NOT NULL DEFAULT 'Out of Stock',
  notes            TEXT NULL,
  source_order_id  INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_stock_user (user_id),
  CONSTRAINT fk_stock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. printers
-- ------------------------------------------------------------
CREATE TABLE printers (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NOT NULL,
  name               VARCHAR(80) NOT NULL,
  model              VARCHAR(80) NULL,
  printer_type       ENUM('FDM','Resin','Other') NOT NULL DEFAULT 'FDM',
  status             ENUM('Available','Scheduled','Printing','Paused','Awaiting Confirmation','Maintenance','Offline','Error') NOT NULL DEFAULT 'Available',
  nozzle_size        VARCHAR(10) NULL,
  material_loaded    VARCHAR(40) NULL,
  purchase_price     DECIMAL(10,2) NOT NULL DEFAULT 0,
  estimated_lifetime_hours INT UNSIGNED NOT NULL DEFAULT 5000,
  average_power_consumption_watts INT UNSIGNED NOT NULL DEFAULT 150,
  depreciation_per_hour DECIMAL(8,4) NOT NULL DEFAULT 0,
  maintenance_cost_per_hour DECIMAL(8,4) NOT NULL DEFAULT 0,
  total_print_hours  DECIMAL(10,2) NOT NULL DEFAULT 0,
  last_maintenance_date DATE NULL,
  next_maintenance_due  DATE NULL,
  current_job_id     INT UNSIGNED NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  notes              TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_printers_user (user_id),
  CONSTRAINT fk_printers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. printer_maintenance_logs
-- ------------------------------------------------------------
CREATE TABLE printer_maintenance_logs (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  printer_id       INT UNSIGNED NOT NULL,
  maintenance_type ENUM('Nozzle change','Bed cleaning','Belt adjustment','Lubrication','Extruder cleaning','Firmware update','General inspection','Other') NOT NULL DEFAULT 'General inspection',
  date             DATE NOT NULL,
  cost             DECIMAL(10,2) NOT NULL DEFAULT 0,
  print_hours_at_maintenance DECIMAL(10,2) NULL,
  notes            TEXT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_maint_printer (printer_id),
  CONSTRAINT fk_maint_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_maint_printer FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. orders : quotes and customer orders
-- ------------------------------------------------------------
CREATE TABLE orders (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             INT UNSIGNED NOT NULL,
  customer_id         INT UNSIGNED NULL,
  customer_name       VARCHAR(120) NOT NULL DEFAULT '',
  product_name        VARCHAR(120) NOT NULL,
  status              ENUM('Quote','Approved','Printing','Post-processing','Ready','Delivered','Cancelled') NOT NULL DEFAULT 'Quote',
  stock_item_id       INT UNSIGNED NULL,
  printer_id          INT UNSIGNED NULL,
  order_quantity      INT UNSIGNED NOT NULL DEFAULT 1,
  material_used       VARCHAR(40) NULL,
  grams_used          DECIMAL(10,2) NOT NULL DEFAULT 0,
  print_time          DECIMAL(8,2) NOT NULL DEFAULT 0,
  labour_time         DECIMAL(8,2) NOT NULL DEFAULT 0,
  unit_cost           DECIMAL(10,2) NOT NULL DEFAULT 0,
  unit_selling_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_cost          DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_profit        DECIMAL(10,2) NOT NULL DEFAULT 0,
  profit_margin       DECIMAL(6,2)  NOT NULL DEFAULT 0,
  stock_deducted      INT NOT NULL DEFAULT 0,
  delivery_date       DATE NULL,
  notes               TEXT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_orders_user (user_id),
  KEY ix_orders_status (status),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_stock FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_printer FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE stock_items
  ADD CONSTRAINT fk_stock_source_order FOREIGN KEY (source_order_id) REFERENCES orders(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 9. print_jobs : production runs on a printer
-- ------------------------------------------------------------
CREATE TABLE print_jobs (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                    INT UNSIGNED NOT NULL,
  printer_id                 INT UNSIGNED NOT NULL,
  source_type                ENUM('stock','order','custom') NOT NULL DEFAULT 'custom',
  stock_item_id              INT UNSIGNED NULL,
  order_id                   INT UNSIGNED NULL,
  inventory_id               INT UNSIGNED NULL,
  custom_job_name            VARCHAR(120) NULL,
  customer_or_purpose        VARCHAR(120) NULL,
  status                     ENUM('Scheduled','Printing','Paused','Awaiting Confirmation','Completed','Partially Failed','Failed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  planned_quantity           INT UNSIGNED NOT NULL DEFAULT 1,
  successful_quantity        INT UNSIGNED NOT NULL DEFAULT 0,
  failed_quantity            INT UNSIGNED NOT NULL DEFAULT 0,
  material_used              VARCHAR(40) NULL,
  estimated_material_quantity DECIMAL(10,2) NULL,
  wasted_material            DECIMAL(10,2) NULL,
  estimated_duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  started_at                 DATETIME NULL,
  paused_at                  DATETIME NULL,
  total_paused_seconds       INT UNSIGNED NOT NULL DEFAULT 0,
  estimated_completion_at    DATETIME NULL,
  completed_at               DATETIME NULL,
  confirmed_at               DATETIME NULL,
  failure_reason             VARCHAR(255) NULL,
  stock_update_completed     TINYINT(1) NOT NULL DEFAULT 0,
  notes                      TEXT NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_jobs_user (user_id),
  KEY ix_jobs_printer (printer_id),
  CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_jobs_printer FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE,
  CONSTRAINT fk_jobs_stock FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE printers
  ADD CONSTRAINT fk_printers_current_job FOREIGN KEY (current_job_id) REFERENCES print_jobs(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 10. stock_movements : audit trail of every quantity change
-- ------------------------------------------------------------
CREATE TABLE stock_movements (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  stock_item_id     INT UNSIGNED NULL,
  movement_type     ENUM('Stock Added','Stock Reduced','Order Created','Order Updated','Order Cancelled','Manual Adjustment','Production Completed') NOT NULL,
  quantity_changed  INT NOT NULL,
  previous_quantity INT NOT NULL,
  new_quantity      INT NOT NULL,
  order_id          INT UNSIGNED NULL,
  print_job_id      INT UNSIGNED NULL,
  printer_id        INT UNSIGNED NULL,
  successful_quantity INT NULL,
  failed_quantity   INT NULL,
  note              VARCHAR(255) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_moves_item (stock_item_id),
  CONSTRAINT fk_moves_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_moves_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_moves_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_moves_job FOREIGN KEY (print_job_id) REFERENCES print_jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_moves_printer FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 11. invoices
-- ------------------------------------------------------------
CREATE TABLE invoices (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id              INT UNSIGNED NOT NULL,
  invoice_number       VARCHAR(20) NOT NULL,
  customer_id          INT UNSIGNED NULL,
  order_id             INT UNSIGNED NULL,
  customer_name        VARCHAR(120) NOT NULL,
  customer_email       VARCHAR(190) NULL,
  customer_phone       VARCHAR(40)  NULL,
  customer_address     VARCHAR(255) NULL,
  status               ENUM('Draft','Sent','Paid','Overdue','Cancelled') NOT NULL DEFAULT 'Draft',
  issue_date           DATE NOT NULL,
  due_date             DATE NULL,
  subtotal             DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount             DECIMAL(10,2) NOT NULL DEFAULT 0,
  gst_enabled          TINYINT(1) NOT NULL DEFAULT 1,
  gst_amount           DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount_paid          DECIMAL(10,2) NOT NULL DEFAULT 0,
  balance_due          DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_instructions TEXT NULL,
  terms_conditions     TEXT NULL,
  notes                TEXT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_number (user_id, invoice_number),
  CONSTRAINT fk_invoices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 12. invoice_items
-- ------------------------------------------------------------
CREATE TABLE invoice_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  invoice_id   INT UNSIGNED NOT NULL,
  position     INT UNSIGNED NOT NULL DEFAULT 1,
  product_name VARCHAR(120) NOT NULL,
  description  VARCHAR(255) NULL,
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price   DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_total   DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_items_invoice (invoice_id),
  CONSTRAINT fk_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  DEMO DATA  (password for both accounts: Demo1234!)
-- ============================================================
INSERT INTO users (id, email, password_hash, full_name, role) VALUES
(1, 'demo@printboss.local', '$2y$10$Yk2SLQjUDiL5rT3vQxdi5u/622Vx769LbSvYPFehU1Uf0j3YMXnJe', 'Demo Maker', 'user'),
(2, 'admin@printboss.local', '$2y$10$Yk2SLQjUDiL5rT3vQxdi5u/622Vx769LbSvYPFehU1Uf0j3YMXnJe', 'PrintBoss Admin', 'admin');

INSERT INTO profiles (user_id, business_name, business_abn, business_email, business_phone, business_address, country, currency,
  bank_name, bank_account_name, bank_bsb, bank_account_number, invoice_prefix, default_payment_instructions, default_invoice_notes,
  default_electricity_cost, default_labour_rate, default_depreciation_rate, default_profit_margin, default_failed_risk, onboarding_completed) VALUES
(1, 'Layer Lab 3D', '12 345 678 901', 'hello@layerlab3d.com.au', '0400 123 456', '12 Maker Street, Mortdale NSW 2223', 'Australia', 'AUD',
 'Commonwealth Bank', 'Layer Lab 3D', '062-000', '12345678', 'INV',
 'Payment by bank transfer. Please use the invoice number as the payment reference.',
 'Thank you for supporting a local maker!', 0.3000, 25.00, 0.50, 50.00, 10.00, 1),
(2, 'PrintBoss Admin', '', 'admin@printboss.local', '', '', 'Australia', 'AUD', '', '', '', '', 'INV', NULL, NULL, 0.3000, 25.00, 0.50, 50.00, 10.00, 1);

INSERT INTO customers (id, user_id, name, email, phone, preferred_product_type, address_line_1, city, state, postcode, country, notes) VALUES
(1, 1, 'Sarah Nguyen', 'sarah.nguyen@example.com', '0411 222 333', 'Home decor', '5 Harbour View Rd', 'Kogarah', 'NSW', '2217', 'Australia', 'Repeat customer, likes matte PLA.'),
(2, 1, 'Tom Barker', 'tom.b@example.com', '0422 444 555', 'Tabletop miniatures', '88 Forest Way', 'Hurstville', 'NSW', '2220', 'Australia', NULL),
(3, 1, 'Cafe Aroma', 'orders@cafearoma.com.au', '02 9555 1212', 'Coffee accessories', '210 King St', 'Newtown', 'NSW', '2042', 'Australia', 'Wholesale, orders in batches of 10.'),
(4, 1, 'Priya Patel', 'priya.p@example.com', '0433 666 777', 'Gifts', '3/14 Railway Pde', 'Mortdale', 'NSW', '2223', 'Australia', NULL);

INSERT INTO inventory (id, user_id, material_type, colour, brand, supplier, purchase_price, total_weight, remaining_weight, min_stock_alert, notes) VALUES
(1, 1, 'PLA', 'Black', 'Bambu Lab', 'Bambu Store AU', 29.99, 1000, 640, 200, NULL),
(2, 1, 'PLA', 'White', 'eSUN', 'Amazon AU', 24.50, 1000, 180, 200, 'Running low, reorder soon.'),
(3, 1, 'PETG', 'Translucent Blue', 'Polymaker', '3D Printers Online', 34.00, 1000, 820, 200, NULL),
(4, 1, 'PLA', 'Terracotta', 'Bambu Lab', 'Bambu Store AU', 29.99, 1000, 1000, 200, 'New roll, unopened.'),
(5, 1, 'TPU', 'Red', 'Sunlu', 'Amazon AU', 31.00, 500, 95, 100, 'Flexible, use direct drive only.');

INSERT INTO stock_items (id, user_id, product_name, sku, category, description, current_quantity, minimum_quantity, unit_cost, selling_price, location, status) VALUES
(1, 1, 'Kettle Riser with Drawers', 'KR-001', 'Kitchen', 'Riser for gooseneck kettles with two storage drawers.', 6, 3, 14.20, 55.00, 'Shelf A1', 'In Stock'),
(2, 1, 'Moon Lamp 12cm', 'LAMP-012', 'Home decor', 'Lithophane moon lamp with USB LED base.', 2, 3, 9.80, 39.00, 'Shelf A2', 'Low Stock'),
(3, 1, 'Dragon Dice Tower', 'DT-DRG', 'Tabletop', 'Dice tower with dragon motif, two pieces.', 0, 2, 11.50, 45.00, 'Shelf B1', 'Out of Stock'),
(4, 1, 'WDT Espresso Tool', 'WDT-9', 'Coffee', '9-needle weiss distribution tool.', 15, 5, 3.40, 18.00, 'Drawer C', 'In Stock'),
(5, 1, 'Coffee Bag Clip', 'CLIP-01', 'Coffee', 'Spring-less bag clip, PETG.', 40, 10, 0.90, 6.00, 'Drawer C', 'In Stock');

INSERT INTO printers (id, user_id, name, model, printer_type, status, nozzle_size, material_loaded, purchase_price, estimated_lifetime_hours,
  average_power_consumption_watts, depreciation_per_hour, maintenance_cost_per_hour, total_print_hours, last_maintenance_date, next_maintenance_due, notes) VALUES
(1, 1, 'P2S Main', 'Bambu Lab P2S', 'FDM', 'Available', '0.4', 'PLA', 1299.00, 5000, 180, 0.2598, 0.05, 412.50, '2026-09-20', '2026-11-20', 'AMS 2 Pro attached.'),
(2, 1, 'A1 Secondary', 'Bambu Lab A1', 'FDM', 'Available', '0.4', 'PETG', 400.00, 4000, 120, 0.1000, 0.04, 338.00, '2026-08-30', '2026-10-30', 'Second-hand, AMS Lite.'),
(3, 1, 'Resin Rig', 'Elegoo Mars 4', 'Resin', 'Maintenance', NULL, 'Standard Resin', 450.00, 3000, 60, 0.1500, 0.06, 92.00, '2026-10-01', '2026-10-15', 'FEP film replacement pending.');

INSERT INTO printer_maintenance_logs (user_id, printer_id, maintenance_type, date, cost, print_hours_at_maintenance, notes) VALUES
(1, 1, 'Nozzle change', '2026-09-20', 18.00, 400.00, 'Replaced with hardened steel 0.4mm.'),
(1, 1, 'Bed cleaning', '2026-09-05', 0.00, 370.00, 'IPA wipe, no issues.'),
(1, 2, 'Belt adjustment', '2026-08-30', 0.00, 320.00, 'Tightened X belt after ghosting.'),
(1, 3, 'General inspection', '2026-10-01', 0.00, 92.00, 'FEP film is cloudy, replacement ordered.');

INSERT INTO orders (id, user_id, customer_id, customer_name, product_name, status, stock_item_id, printer_id, order_quantity, material_used, grams_used, print_time, labour_time,
  unit_cost, unit_selling_price, total_cost, total_selling_price, total_profit, profit_margin, stock_deducted, delivery_date, notes, created_at) VALUES
(1, 1, 1, 'Sarah Nguyen', 'Kettle Riser with Drawers', 'Delivered', 1, 1, 1, 'PLA', 310, 9.5, 0.5, 14.20, 55.00, 14.20, 55.00, 40.80, 74.18, 1, '2026-08-14', NULL, '2026-08-10 09:12:00'),
(2, 1, 2, 'Tom Barker', 'Dragon Dice Tower', 'Printing', 3, 2, 2, 'PLA', 240, 7.0, 0.75, 11.50, 45.00, 23.00, 90.00, 67.00, 74.44, 0, '2026-10-16', 'Wants red dragon, use Sunlu red if PLA.', '2026-10-05 14:30:00'),
(3, 1, 3, 'Cafe Aroma', 'WDT Espresso Tool', 'Ready', 4, 1, 10, 'PETG', 12, 0.4, 0.1, 3.40, 16.00, 34.00, 160.00, 126.00, 78.75, 10, '2026-10-10', 'Wholesale price applied.', '2026-09-28 11:05:00'),
(4, 1, 4, 'Priya Patel', 'Moon Lamp 12cm', 'Approved', 2, NULL, 1, 'PLA', 160, 6.5, 0.5, 9.80, 39.00, 9.80, 39.00, 29.20, 74.87, 1, '2026-10-20', NULL, '2026-10-06 16:45:00'),
(5, 1, NULL, 'Walk-in', 'Custom phone stand', 'Quote', NULL, NULL, 1, 'PLA', 45, 1.5, 0.25, 3.10, 12.00, 3.10, 12.00, 8.90, 74.17, 0, NULL, 'Quote from calculator.', '2026-10-07 10:00:00'),
(6, 1, 1, 'Sarah Nguyen', 'Coffee Bag Clip', 'Delivered', 5, 2, 4, 'PETG', 8, 0.3, 0.05, 0.90, 6.00, 3.60, 24.00, 20.40, 85.00, 4, '2026-09-02', NULL, '2026-08-28 13:20:00'),
(7, 1, 2, 'Tom Barker', 'Mini Terrain Set', 'Delivered', NULL, 1, 1, 'PLA', 420, 14.0, 1.0, 19.60, 65.00, 19.60, 65.00, 45.40, 69.85, 0, '2026-07-22', NULL, '2026-07-15 08:40:00'),
(8, 1, 4, 'Priya Patel', 'Kettle Riser with Drawers', 'Cancelled', 1, NULL, 1, 'PLA', 310, 9.5, 0.5, 14.20, 55.00, 14.20, 55.00, 40.80, 74.18, 0, NULL, 'Customer changed mind.', '2026-06-18 15:00:00');

INSERT INTO print_jobs (id, user_id, printer_id, source_type, stock_item_id, order_id, inventory_id, custom_job_name, customer_or_purpose, status,
  planned_quantity, successful_quantity, failed_quantity, material_used, estimated_material_quantity, wasted_material, estimated_duration_minutes,
  started_at, estimated_completion_at, completed_at, confirmed_at, stock_update_completed, notes) VALUES
(1, 1, 1, 'stock', 4, 3, 3, NULL, 'Cafe Aroma wholesale', 'Completed', 10, 10, 0, 'PETG', 120, 0, 240,
 '2026-10-02 08:00:00', '2026-10-02 12:00:00', '2026-10-02 12:05:00', '2026-10-02 12:10:00', 1, 'Batch of 10 on one plate.'),
(2, 1, 1, 'stock', 1, NULL, 1, NULL, 'Restock', 'Partially Failed', 4, 3, 1, 'PLA', 1240, 310, 2280,
 '2026-09-25 07:30:00', '2026-09-27 21:30:00', '2026-09-27 22:00:00', '2026-09-28 08:00:00', 1, 'One riser warped at the corner.'),
(3, 1, 2, 'order', 3, 2, 1, NULL, 'Tom Barker', 'Printing', 2, 0, 0, 'PLA', 480, NULL, 840,
 NOW() - INTERVAL 3 HOUR, NOW() + INTERVAL 11 HOUR, NULL, NULL, 0, NULL),
(4, 1, 1, 'custom', NULL, NULL, 2, 'Lithophane test', 'R&D', 'Scheduled', 1, 0, 0, 'PLA', 60, NULL, 150, NULL, NULL, NULL, NULL, 0, 'Testing new white PLA.');

UPDATE printers SET status = 'Printing', current_job_id = 3 WHERE id = 2;

INSERT INTO stock_movements (user_id, stock_item_id, movement_type, quantity_changed, previous_quantity, new_quantity, order_id, print_job_id, printer_id, successful_quantity, failed_quantity, note, created_at) VALUES
(1, 1, 'Stock Added', 4, 0, 4, NULL, NULL, NULL, NULL, NULL, 'Initial batch', '2026-08-01 10:00:00'),
(1, 1, 'Order Created', -1, 4, 3, 1, NULL, NULL, NULL, NULL, 'Order #1 Sarah Nguyen', '2026-08-10 09:12:00'),
(1, 1, 'Production Completed', 3, 3, 6, NULL, 2, 1, 3, 1, 'Print job #2 confirmed', '2026-09-28 08:00:00'),
(1, 4, 'Stock Added', 15, 0, 15, NULL, NULL, NULL, NULL, NULL, 'Initial batch', '2026-08-01 10:00:00'),
(1, 4, 'Production Completed', 10, 15, 25, NULL, 1, 1, 10, 0, 'Print job #1 confirmed', '2026-10-02 12:10:00'),
(1, 4, 'Order Created', -10, 25, 15, 3, NULL, NULL, NULL, NULL, 'Order #3 Cafe Aroma', '2026-10-02 12:15:00'),
(1, 5, 'Stock Added', 44, 0, 44, NULL, NULL, NULL, NULL, NULL, 'Initial batch', '2026-08-01 10:00:00'),
(1, 5, 'Order Created', -4, 44, 40, 6, NULL, NULL, NULL, NULL, 'Order #6 Sarah Nguyen', '2026-08-28 13:20:00'),
(1, 2, 'Stock Added', 3, 0, 3, NULL, NULL, NULL, NULL, NULL, 'Initial batch', '2026-09-15 10:00:00'),
(1, 2, 'Order Created', -1, 3, 2, 4, NULL, NULL, NULL, NULL, 'Order #4 Priya Patel', '2026-10-06 16:45:00');

INSERT INTO invoices (id, user_id, invoice_number, customer_id, order_id, customer_name, customer_email, customer_phone, customer_address, status, issue_date, due_date,
  subtotal, discount, gst_enabled, gst_amount, total_amount, amount_paid, balance_due, payment_instructions, terms_conditions, notes, created_at, updated_at) VALUES
(1, 1, 'INV-0001', 1, 1, 'Sarah Nguyen', 'sarah.nguyen@example.com', '0411 222 333', '5 Harbour View Rd, Kogarah NSW 2217', 'Paid', '2026-08-14', '2026-08-28',
 55.00, 0.00, 1, 5.50, 60.50, 60.50, 0.00, 'Payment by bank transfer. Please use the invoice number as the payment reference.', 'Payment due within 14 days', NULL, '2026-08-14 10:00:00', '2026-08-20 09:00:00'),
(2, 1, 'INV-0002', 2, 7, 'Tom Barker', 'tom.b@example.com', '0422 444 555', '88 Forest Way, Hurstville NSW 2220', 'Paid', '2026-07-22', '2026-08-05',
 65.00, 5.00, 1, 6.00, 66.00, 66.00, 0.00, 'Payment by bank transfer. Please use the invoice number as the payment reference.', 'Payment due within 14 days', 'Loyalty discount applied.', '2026-07-22 10:00:00', '2026-07-25 09:00:00'),
(3, 1, 'INV-0003', 3, 3, 'Cafe Aroma', 'orders@cafearoma.com.au', '02 9555 1212', '210 King St, Newtown NSW 2042', 'Sent', '2026-10-03', '2026-10-17',
 160.00, 0.00, 1, 16.00, 176.00, 0.00, 176.00, 'Payment by bank transfer. Please use the invoice number as the payment reference.', 'Payment due within 14 days', NULL, '2026-10-03 10:00:00', '2026-10-03 10:00:00'),
(4, 1, 'INV-0004', 1, 6, 'Sarah Nguyen', 'sarah.nguyen@example.com', '0411 222 333', '5 Harbour View Rd, Kogarah NSW 2217', 'Overdue', '2026-09-02', '2026-09-16',
 24.00, 0.00, 1, 2.40, 26.40, 0.00, 26.40, 'Payment by bank transfer. Please use the invoice number as the payment reference.', 'Payment due within 14 days', NULL, '2026-09-02 10:00:00', '2026-09-02 10:00:00');

INSERT INTO invoice_items (user_id, invoice_id, position, product_name, description, quantity, unit_price, line_total) VALUES
(1, 1, 1, 'Kettle Riser with Drawers', 'Black PLA, two drawers', 1, 55.00, 55.00),
(1, 2, 1, 'Mini Terrain Set', 'Six pieces, unpainted', 1, 65.00, 65.00),
(1, 3, 1, 'WDT Espresso Tool', 'Wholesale batch, PETG blue', 10, 16.00, 160.00),
(1, 4, 1, 'Coffee Bag Clip', 'PETG, assorted colours', 4, 6.00, 24.00);
