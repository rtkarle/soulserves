-- ============================================================
--  Adhaar – The SoulServe  |  LIVE MIGRATION v2.0
--  Run this ONCE against the existing adhaar_db.
--  Safe: uses IF NOT EXISTS / MODIFY only where needed.
-- ============================================================

USE adhaar_db;

-- ── 1. register: ensure seller role works ────────────────────
--    Add profile_photo if missing (safe check)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='register' AND COLUMN_NAME='profile_photo');
SET @sql = IF(@col_exists=0, 
    'ALTER TABLE register ADD COLUMN profile_photo VARCHAR(300) DEFAULT NULL AFTER verified',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. cloth_donations: fix is_clean type ────────────────────
ALTER TABLE cloth_donations
  MODIFY COLUMN is_clean TINYINT(1) NOT NULL DEFAULT 1;

-- ── 3. seller_stores ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS seller_stores (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seller_email     VARCHAR(180) NOT NULL UNIQUE,
  store_name       VARCHAR(180) NOT NULL,
  store_tagline    VARCHAR(300),
  store_category   ENUM('handicraft','textile','food_product','jewelry','art',
                        'pottery','organic','other') NOT NULL DEFAULT 'other',
  store_description TEXT,
  store_logo       VARCHAR(300),
  store_banner     VARCHAR(300),
  whatsapp         VARCHAR(20),
  upi_id           VARCHAR(100),
  bank_name        VARCHAR(120),
  bank_account     VARCHAR(30),
  bank_ifsc        VARCHAR(20),
  bank_holder_name VARCHAR(120),
  village          VARCHAR(120),
  district         VARCHAR(120),
  state            VARCHAR(80),
  pincode          VARCHAR(10),
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  is_verified      TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_seller (seller_email)
) ENGINE=InnoDB;

-- ── 4. products ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seller_email   VARCHAR(180) NOT NULL,
  store_id       INT UNSIGNED DEFAULT NULL,
  name           VARCHAR(220) NOT NULL,
  description    TEXT,
  category       ENUM('handicraft','textile','food_product','jewelry','art',
                      'pottery','organic','other') NOT NULL DEFAULT 'other',
  price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  mrp            DECIMAL(10,2) DEFAULT NULL,
  stock          INT NOT NULL DEFAULT 0,
  image1         VARCHAR(300),
  image2         VARCHAR(300),
  image3         VARCHAR(300),
  weight_grams   INT DEFAULT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  total_sold     INT NOT NULL DEFAULT 0,
  avg_rating     DECIMAL(3,2) DEFAULT 0.00,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_seller   (seller_email),
  INDEX idx_category (category),
  INDEX idx_active   (is_active),
  FOREIGN KEY (store_id) REFERENCES seller_stores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── 5. cart ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cart (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_email   VARCHAR(180) NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  quantity     INT NOT NULL DEFAULT 1,
  added_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_cart (user_email, product_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── 6. orders ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number     VARCHAR(30) NOT NULL UNIQUE,
  buyer_email      VARCHAR(180) NOT NULL,
  seller_email     VARCHAR(180) NOT NULL,
  total_amount     DECIMAL(10,2) NOT NULL,
  shipping_name    VARCHAR(150) NOT NULL,
  shipping_phone   VARCHAR(20)  NOT NULL,
  shipping_address TEXT         NOT NULL,
  shipping_city    VARCHAR(100) NOT NULL,
  shipping_state   VARCHAR(80)  NOT NULL,
  shipping_pincode VARCHAR(10)  NOT NULL,
  payment_method   ENUM('cod','upi','card') NOT NULL DEFAULT 'cod',
  payment_status   ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  order_status     ENUM('placed','confirmed','processing','shipped',
                        'out_for_delivery','delivered','cancelled',
                        'return_requested','returned') NOT NULL DEFAULT 'placed',
  tracking_id      VARCHAR(100) DEFAULT NULL,
  estimated_delivery DATE DEFAULT NULL,
  notes            TEXT,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_buyer  (buyer_email),
  INDEX idx_seller (seller_email),
  INDEX idx_status (order_status)
) ENGINE=InnoDB;

-- ── 7. order_items ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS order_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  product_name VARCHAR(220) NOT NULL,
  price        DECIMAL(10,2) NOT NULL,
  quantity     INT NOT NULL DEFAULT 1,
  image        VARCHAR(300),
  FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── 8. product_reviews ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS product_reviews (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED NOT NULL,
  order_id       INT UNSIGNED NOT NULL,
  reviewer_email VARCHAR(180) NOT NULL,
  rating         TINYINT NOT NULL,
  review_text    TEXT,
  is_verified    TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_review (product_id, order_id, reviewer_email),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── 9. return_requests ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS return_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  buyer_email    VARCHAR(180) NOT NULL,
  seller_email   VARCHAR(180) NOT NULL,
  reason         ENUM('damaged','wrong_item','not_as_described',
                      'changed_mind','other') NOT NULL DEFAULT 'other',
  description    TEXT,
  images         VARCHAR(500),
  status         ENUM('requested','approved','rejected','pickup_scheduled',
                      'item_received','refund_initiated','refund_completed')
                 NOT NULL DEFAULT 'requested',
  admin_notes    TEXT,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── 10. volunteer_tasks ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS volunteer_tasks (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  volunteer_email VARCHAR(180) NOT NULL,
  donation_type   ENUM('food','cloth') NOT NULL,
  donation_id     INT UNSIGNED NOT NULL,
  assigned_by     VARCHAR(180),
  task_status     ENUM('pending_acceptance','accepted','rejected',
                       'in_progress','completed') NOT NULL DEFAULT 'pending_acceptance',
  notes           TEXT,
  assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at    DATETIME DEFAULT NULL,
  INDEX idx_volunteer (volunteer_email),
  INDEX idx_status    (task_status)
) ENGINE=InnoDB;

-- ── 11. password_resets (may already exist) ──────────────────
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(180) NOT NULL,
  token      VARCHAR(255) NOT NULL UNIQUE,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_token (token)
) ENGINE=InnoDB;

-- ── Verify ───────────────────────────────────────────────────
SELECT CONCAT('✅ ', TABLE_NAME) AS migrated_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'adhaar_db'
ORDER BY TABLE_NAME;
