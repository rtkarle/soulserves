-- ============================================================
--  SoulServe — Complete Database Setup & Fix Script
--  Run this ONCE on a fresh database OR to fix missing columns
--  Safe to run multiple times (uses SHOW COLUMNS checks)
--  Last updated: 2026
-- ============================================================

USE adhaar_db;

-- ============================================================
-- 1. CORE TABLES (CREATE IF NOT EXISTS — always safe)
-- ============================================================

CREATE TABLE IF NOT EXISTS register (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    email        VARCHAR(180) NOT NULL UNIQUE,
    mobile       VARCHAR(20),
    password     VARCHAR(255) NOT NULL,
    role         ENUM('donor','volunteer','seller') NOT NULL DEFAULT 'donor',
    verified     TINYINT(1) NOT NULL DEFAULT 0,
    address      TEXT,
    profile_photo VARCHAR(300) DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role  (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS otps (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(180) NOT NULL,
    otp        VARCHAR(10)  NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(180) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(180) NOT NULL,
    token      VARCHAR(100) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(180) NOT NULL,
    ip           VARCHAR(50),
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_time  (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(180) NOT NULL,
    subject    VARCHAR(255),
    message    TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. FOOD DONATIONS (legacy table)
-- ============================================================

CREATE TABLE IF NOT EXISTS food_donations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donation_id     VARCHAR(30)  DEFAULT NULL,
    donor_email     VARCHAR(180) NOT NULL,
    food_time       DATETIME,
    safe_hours      INT          DEFAULT 0,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill donation_id for existing rows
UPDATE food_donations
SET donation_id = CONCAT('DON-FOOD-', LPAD(id,6,'0'))
WHERE donation_id IS NULL;

-- ============================================================
-- 3. CLOTH DONATIONS (legacy table)
-- ============================================================

CREATE TABLE IF NOT EXISTS cloth_donations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donation_id     VARCHAR(30)  DEFAULT NULL,
    donor_email     VARCHAR(180) NOT NULL,
    purchase_time   VARCHAR(100),
    quantity        INT          DEFAULT 1,
    cloth_type      VARCHAR(80),
    condition_type  ENUM('new','good','fair','worn') NOT NULL DEFAULT 'good',
    is_clean        TINYINT(1)   NOT NULL DEFAULT 1,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill donation_id for existing rows
UPDATE cloth_donations
SET donation_id = CONCAT('DON-CLO-', LPAD(id,6,'0'))
WHERE donation_id IS NULL;

-- ============================================================
-- 4. UNIFIED DONATIONS TABLE (all categories)
-- ============================================================

CREATE TABLE IF NOT EXISTS donations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donation_id     VARCHAR(30)  NOT NULL UNIQUE,
    donor_email     VARCHAR(180) NOT NULL,

    -- Category
    category        ENUM(
                        'food','clothes','study_material','school_supplies',
                        'toys','medicines','electronics','furniture','other'
                    ) NOT NULL DEFAULT 'other',

    -- Common fields
    quantity        VARCHAR(100) NOT NULL DEFAULT '1',
    description     TEXT,
    condition_type  VARCHAR(20)  DEFAULT 'good',
    pickup_address  TEXT         NOT NULL,
    contact         VARCHAR(20)  NOT NULL,
    pickup_date     DATE,
    pickup_time     TIME,
    image           VARCHAR(400),
    status          ENUM('pending','accepted','rejected','scheduled',
                         'out_for_pickup','picked_up','delivered')
                    NOT NULL DEFAULT 'pending',
    volunteer_email VARCHAR(180),
    notes           TEXT,
    priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',

    -- Food specific
    food_time       DATETIME,
    safe_hours      INT          DEFAULT NULL,

    -- Clothes / Footwear
    cloth_type          VARCHAR(80)  DEFAULT NULL,
    cloth_subcat        VARCHAR(20)  DEFAULT NULL,   -- 'clothes' | 'footwear'
    cloth_for           VARCHAR(30)  DEFAULT NULL,   -- Men/Women/Boys/Girls/etc
    cloth_garment_type  VARCHAR(60)  DEFAULT NULL,   -- T-Shirt/Kurta/Saree/etc
    cloth_sizes         VARCHAR(200) DEFAULT NULL,   -- 'S, M, L' (multi-select)
    cloth_pieces        INT          DEFAULT NULL,
    cloth_color         VARCHAR(100) DEFAULT NULL,
    cloth_packed        VARCHAR(30)  DEFAULT NULL,
    is_clean            TINYINT(1)   DEFAULT 1,
    footwear_for        VARCHAR(30)  DEFAULT NULL,
    footwear_type       VARCHAR(60)  DEFAULT NULL,
    footwear_sizes      VARCHAR(200) DEFAULT NULL,   -- 'UK 7, UK 8'
    footwear_pairs      INT          DEFAULT NULL,

    -- Study material / School supplies / Toys
    subject_grade   VARCHAR(100) DEFAULT NULL,   -- also stores sizes for clothes
    book_count      INT          DEFAULT NULL,

    -- Medicines
    expiry_date     DATE         DEFAULT NULL,
    medicine_type   VARCHAR(100) DEFAULT NULL,

    -- Electronics / Furniture / Other
    device_type     VARCHAR(100) DEFAULT NULL,
    working_status  VARCHAR(30)  DEFAULT 'working',

    -- Timestamps
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_donor    (donor_email),
    INDEX idx_status   (status),
    INDEX idx_category (category),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. SHOP TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS seller_stores (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_email     VARCHAR(180) NOT NULL UNIQUE,
    store_name       VARCHAR(180) NOT NULL,
    store_tagline    VARCHAR(300),
    store_category   VARCHAR(60)  DEFAULT 'handicraft',
    description      TEXT,
    village          VARCHAR(100),
    state            VARCHAR(100),
    pincode          VARCHAR(10),
    logo             VARCHAR(300),
    banner           VARCHAR(300),
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    is_verified      TINYINT(1)   NOT NULL DEFAULT 0,
    bank_name        VARCHAR(100),
    account_no       VARCHAR(30),
    ifsc             VARCHAR(15),
    upi_id           VARCHAR(100),
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email  (seller_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_email    VARCHAR(180) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    description     TEXT,
    category        ENUM('handicraft','textile','food_product','jewelry',
                         'art','pottery','organic','other')
                    NOT NULL DEFAULT 'other',
    price           DECIMAL(10,2) NOT NULL DEFAULT 0,
    mrp             DECIMAL(10,2) DEFAULT 0,
    stock           INT          NOT NULL DEFAULT 0,
    image1          VARCHAR(300),
    image2          VARCHAR(300),
    image3          VARCHAR(300),
    avg_rating      DECIMAL(3,2) DEFAULT 0,
    total_sold      INT          DEFAULT 0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller   (seller_email),
    INDEX idx_category (category),
    INDEX idx_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cart (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email   VARCHAR(180) NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    quantity     INT          NOT NULL DEFAULT 1,
    added_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart (user_email, product_id),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_no         VARCHAR(30)  NOT NULL UNIQUE,
    buyer_email      VARCHAR(180) NOT NULL,
    seller_email     VARCHAR(180) NOT NULL,
    total_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
    order_status     ENUM('placed','confirmed','processing','packed',
                          'shipped','out_for_delivery','delivered',
                          'cancelled','return_requested','returned')
                     NOT NULL DEFAULT 'placed',
    shipping_name    VARCHAR(120),
    shipping_phone   VARCHAR(20),
    shipping_address TEXT,
    payment_method   VARCHAR(30) DEFAULT 'cod',
    payment_status   VARCHAR(20) DEFAULT 'pending',
    tracking_id      VARCHAR(100),
    notes            TEXT,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_buyer  (buyer_email),
    INDEX idx_seller (seller_email),
    INDEX idx_status (order_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    product_name VARCHAR(200),
    quantity     INT          NOT NULL DEFAULT 1,
    price        DECIMAL(10,2) NOT NULL DEFAULT 0,
    INDEX idx_order   (order_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_reviews (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL,
    buyer_email  VARCHAR(180) NOT NULL,
    rating       TINYINT      NOT NULL DEFAULT 5,
    review_text  TEXT,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review (product_id, buyer_email),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS return_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    buyer_email  VARCHAR(180) NOT NULL,
    reason       TEXT,
    status       ENUM('requested','approved','rejected','completed')
                 NOT NULL DEFAULT 'requested',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settlements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_email VARCHAR(180) NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    status       ENUM('pending','processing','paid') NOT NULL DEFAULT 'pending',
    utr_no       VARCHAR(50),
    paid_at      DATETIME,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller (seller_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. VOLUNTEER TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS volunteers (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(180) NOT NULL UNIQUE,
    name              VARCHAR(120),
    city              VARCHAR(100),
    reason            TEXT,
    status            ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS volunteer_tasks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    volunteer_email VARCHAR(180) NOT NULL,
    donation_type   ENUM('food','cloth') NOT NULL,
    donation_id     INT UNSIGNED NOT NULL,
    task_status     ENUM('pending_acceptance','accepted','completed','rejected')
                    NOT NULL DEFAULT 'pending_acceptance',
    assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at     DATETIME,
    completed_at    DATETIME,
    INDEX idx_volunteer (volunteer_email),
    INDEX idx_status    (task_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. AI & AUXILIARY TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS ai_logs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_type  VARCHAR(80)  NOT NULL,
    input_data   TEXT,
    output_data  TEXT,
    confidence   FLOAT        DEFAULT 0,
    triggered_by VARCHAR(180) DEFAULT 'system',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action  (action_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_search_history (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email   VARCHAR(180) NOT NULL,
    query        VARCHAR(255) NOT NULL,
    category     VARCHAR(60)  DEFAULT NULL,
    result_count INT          DEFAULT 0,
    searched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user    (user_email),
    INDEX idx_searched(searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_view_history (
    user_email  VARCHAR(180) NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    view_count  INT          DEFAULT 1,
    last_viewed DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_email, product_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS donor_badges (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_email VARCHAR(180) NOT NULL,
    badge_key   VARCHAR(60)  NOT NULL,
    badge_name  VARCHAR(100) NOT NULL,
    badge_emoji VARCHAR(10)  DEFAULT '🏅',
    earned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_badge  (donor_email, badge_key),
    INDEX idx_donor (donor_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ngo_profiles (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(180) NOT NULL,
    email          VARCHAR(180) UNIQUE,
    city           VARCHAR(100),
    state          VARCHAR(100),
    capacity_daily INT          DEFAULT 50,
    is_verified    TINYINT(1)   DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events_news (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    content      TEXT,
    category     VARCHAR(30)  DEFAULT 'news',
    emoji        VARCHAR(10)  DEFAULT '📰',
    image        VARCHAR(300),
    event_date   DATE,
    is_published TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_published (is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. INDEXES (performance)
-- ============================================================

-- food_donations
ALTER TABLE food_donations ADD INDEX IF NOT EXISTS idx_food_donor  (donor_email);
ALTER TABLE food_donations ADD INDEX IF NOT EXISTS idx_food_status (status);
ALTER TABLE food_donations ADD INDEX IF NOT EXISTS idx_food_created(created_at);

-- cloth_donations
ALTER TABLE cloth_donations ADD INDEX IF NOT EXISTS idx_cloth_donor  (donor_email);
ALTER TABLE cloth_donations ADD INDEX IF NOT EXISTS idx_cloth_status (status);

-- products composite
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_prod_cat_active (category, is_active);
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_prod_seller     (seller_email, is_active);

-- orders
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_ord_buyer_status (buyer_email, order_status);

-- ============================================================
-- 9. DEFAULT ADMIN SEED
-- ============================================================

INSERT IGNORE INTO admins (name, email, password, created_at)
VALUES (
    'SoulServe Admin',
    'admin@soulserves.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    NOW()
);
-- Default password: password  ← CHANGE IMMEDIATELY after first login!

-- ============================================================
-- DONE
-- ============================================================
SELECT CONCAT('Setup complete. Tables: ', COUNT(*)) AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'adhaar_db';
