-- ============================================================
--  Adhaar – The SoulServe  |  COMPLETE DATABASE SCHEMA v2.0
--  Includes: Donation, Shop, Seller, Cart, Orders, Reviews
-- ============================================================

CREATE DATABASE IF NOT EXISTS adhaar_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE adhaar_db;

-- ============================================================
-- 1. USERS / REGISTER  (role now includes 'seller')
-- ============================================================
CREATE TABLE IF NOT EXISTS register (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(120)  NOT NULL,
  email            VARCHAR(180)  NOT NULL UNIQUE,
  mobile           VARCHAR(20)   NOT NULL,
  password         VARCHAR(255)  NOT NULL,
  role             ENUM('donor','volunteer','seller') NOT NULL DEFAULT 'donor',
  address          TEXT,
  volunteer_reason TEXT,
  verified         TINYINT(1)    NOT NULL DEFAULT 0,
  profile_photo    VARCHAR(300)  DEFAULT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 2. OTPs
-- ============================================================
CREATE TABLE IF NOT EXISTS otps (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(180) NOT NULL,
  otp        VARCHAR(10)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email)
) ENGINE=InnoDB;

-- ============================================================
-- 3. FOOD DONATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS food_donations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  donor_email     VARCHAR(180)  NOT NULL,
  food_time       DATETIME,
  safe_hours      INT           DEFAULT 0,
  quantity        VARCHAR(100),
  priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  pickup_address  TEXT,
  contact         VARCHAR(20),
  image           VARCHAR(300),
  status          ENUM('pending','accepted','rejected','scheduled',
                       'out_for_pickup','picked_up','delivered')
                  NOT NULL DEFAULT 'pending',
  pickup_date     DATE,
  pickup_time     TIME,
  volunteer_email VARCHAR(180),
  notes           TEXT,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_donor  (donor_email),
  INDEX idx_status (status)
) ENGINE=InnoDB;

-- ============================================================
-- 4. CLOTH DONATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS cloth_donations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  donor_email     VARCHAR(180)  NOT NULL,
  purchase_time   VARCHAR(100),
  quantity        INT           DEFAULT 1,
  cloth_type      VARCHAR(80),
  condition_type  ENUM('new','good','fair','worn') NOT NULL DEFAULT 'good',
  is_clean        TINYINT(1)    NOT NULL DEFAULT 1,
  pickup_address  TEXT,
  contact         VARCHAR(20),
  image           VARCHAR(300),
  status          ENUM('pending','accepted','rejected','scheduled',
                       'out_for_pickup','picked_up','delivered')
                  NOT NULL DEFAULT 'pending',
  pickup_date     DATE,
  pickup_time     TIME,
  volunteer_email VARCHAR(180),
  notes           TEXT,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_donor  (donor_email),
  INDEX idx_status (status)
) ENGINE=InnoDB;

-- ============================================================
-- 5. ADMINS
-- ============================================================
CREATE TABLE IF NOT EXISTS admins (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(180) NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 6. VOLUNTEERS (public interest form — homepage)
-- ============================================================
CREATE TABLE IF NOT EXISTS volunteers (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(180) NOT NULL,
  phone      VARCHAR(20),
  city       VARCHAR(80),
  interest   VARCHAR(120),
  message    TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 7. CONTACT MESSAGES
-- ============================================================
CREATE TABLE IF NOT EXISTS contact_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(180) NOT NULL,
  message    TEXT         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 8. PASSWORD RESETS
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(180) NOT NULL,
  token      VARCHAR(255) NOT NULL UNIQUE,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_token (token)
) ENGINE=InnoDB;

-- ============================================================
-- 9. SELLER STORES  (one per seller)
-- ============================================================
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

-- ============================================================
-- 10. PRODUCTS
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  seller_email   VARCHAR(180) NOT NULL,
  store_id       INT UNSIGNED,
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
  weight_grams   INT DEFAULT NULL COMMENT 'for shipping calculation',
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  total_sold     INT NOT NULL DEFAULT 0,
  avg_rating     DECIMAL(3,2) DEFAULT 0.00,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_seller   (seller_email),
  INDEX idx_category (category),
  INDEX idx_active   (is_active),
  FOREIGN KEY (store_id) REFERENCES seller_stores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 11. CART
-- ============================================================
CREATE TABLE IF NOT EXISTS cart (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_email   VARCHAR(180) NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  quantity     INT NOT NULL DEFAULT 1,
  added_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_cart (user_email, product_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 12. ORDERS
-- ============================================================
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
                        'out_for_delivery','delivered','cancelled','return_requested','returned')
                   NOT NULL DEFAULT 'placed',
  tracking_id      VARCHAR(100) DEFAULT NULL,
  estimated_delivery DATE DEFAULT NULL,
  notes            TEXT,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_buyer  (buyer_email),
  INDEX idx_seller (seller_email),
  INDEX idx_status (order_status)
) ENGINE=InnoDB;

-- ============================================================
-- 13. ORDER ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS order_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  product_name VARCHAR(220) NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  quantity    INT NOT NULL DEFAULT 1,
  image       VARCHAR(300),
  FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 14. PRODUCT REVIEWS
-- ============================================================
CREATE TABLE IF NOT EXISTS product_reviews (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    INT UNSIGNED NOT NULL,
  order_id      INT UNSIGNED NOT NULL,
  reviewer_email VARCHAR(180) NOT NULL,
  rating        TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  review_text   TEXT,
  is_verified   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'verified purchase',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_review (product_id, order_id, reviewer_email),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 15. RETURN REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS return_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  buyer_email    VARCHAR(180) NOT NULL,
  seller_email   VARCHAR(180) NOT NULL,
  reason         ENUM('damaged','wrong_item','not_as_described','changed_mind','other')
                 NOT NULL DEFAULT 'other',
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

-- ============================================================
-- 16. VOLUNTEER TASKS (for task accept/reject flow)
-- ============================================================
CREATE TABLE IF NOT EXISTS volunteer_tasks (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  volunteer_email VARCHAR(180) NOT NULL,
  donation_type   ENUM('food','cloth') NOT NULL,
  donation_id     INT UNSIGNED NOT NULL,
  assigned_by     VARCHAR(180) COMMENT 'admin email',
  task_status     ENUM('pending_acceptance','accepted','rejected','in_progress','completed')
                  NOT NULL DEFAULT 'pending_acceptance',
  notes           TEXT,
  assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at    DATETIME DEFAULT NULL,
  INDEX idx_volunteer (volunteer_email),
  INDEX idx_status    (task_status)
) ENGINE=InnoDB;

-- ============================================================
-- ALTER TABLE for existing installs (run only if upgrading)
-- ============================================================
-- ALTER TABLE register MODIFY COLUMN role ENUM('donor','volunteer','seller') NOT NULL DEFAULT 'donor';
-- ALTER TABLE register ADD COLUMN profile_photo VARCHAR(300) DEFAULT NULL AFTER verified;

-- ============================================================
-- Default admin seed
-- ============================================================
INSERT IGNORE INTO admins (name, email, password, created_at)
VALUES ('Adhaar Admin', 'admin@adhaar.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW());
-- default password: password (change immediately in production)

-- ============================================================
-- Auxiliary tables (AI engine, badges, search/view history)
-- ============================================================
CREATE TABLE IF NOT EXISTS ai_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_type VARCHAR(80) NOT NULL,
    input_data TEXT,
    output_data TEXT,
    confidence FLOAT DEFAULT 0,
    triggered_by VARCHAR(180) DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_search_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(180) NOT NULL,
    query VARCHAR(255) NOT NULL,
    category VARCHAR(60) DEFAULT NULL,
    result_count INT DEFAULT 0,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_email),
    INDEX idx_searched (searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_view_history (
    user_email VARCHAR(180) NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    view_count INT DEFAULT 1,
    last_viewed TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_email, product_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS donor_badges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_email VARCHAR(180) NOT NULL,
    badge_key VARCHAR(60) NOT NULL,
    badge_name VARCHAR(100) NOT NULL,
    badge_emoji VARCHAR(10) DEFAULT '🏅',
    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_badge (donor_email, badge_key),
    INDEX idx_donor (donor_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ngo_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    email VARCHAR(180) UNIQUE,
    city VARCHAR(100),
    state VARCHAR(100),
    capacity_daily INT DEFAULT 50,
    is_verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
