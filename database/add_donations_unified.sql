-- ============================================================
--  SoulServe — Unified Donations Table (all categories)
--  Run once against adhaar_db
-- ============================================================

USE adhaar_db;

-- Add donation_id column to existing food_donations (safe)
SET @food_don_id = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='food_donations' AND COLUMN_NAME='donation_id');
SET @sql1 = IF(@food_don_id=0,
    'ALTER TABLE food_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id',
    'SELECT 1');
PREPARE s FROM @sql1; EXECUTE s; DEALLOCATE PREPARE s;

-- Add donation_id column to existing cloth_donations (safe)
SET @cloth_don_id = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cloth_donations' AND COLUMN_NAME='donation_id');
SET @sql2 = IF(@cloth_don_id=0,
    'ALTER TABLE cloth_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id',
    'SELECT 1');
PREPARE s FROM @sql2; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill donation_id for existing food rows
UPDATE food_donations SET donation_id = CONCAT('DON-FOOD-', LPAD(id,6,'0')) WHERE donation_id IS NULL;
UPDATE cloth_donations SET donation_id = CONCAT('DON-CLO-',  LPAD(id,6,'0')) WHERE donation_id IS NULL;

-- ── Unified donations table (new categories) ──────────────
CREATE TABLE IF NOT EXISTS donations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donation_id     VARCHAR(30)  NOT NULL UNIQUE,
    donor_email     VARCHAR(180) NOT NULL,
    category        ENUM(
                        'food',
                        'clothes',
                        'study_material',
                        'school_supplies',
                        'toys',
                        'medicines',
                        'electronics',
                        'furniture',
                        'other'
                    ) NOT NULL DEFAULT 'other',
    -- common fields
    quantity        VARCHAR(100) NOT NULL DEFAULT '1',
    description     TEXT,
    condition_type  ENUM('new','good','fair','worn') DEFAULT 'good',
    pickup_address  TEXT NOT NULL,
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
    -- food-specific
    food_time       DATETIME,
    safe_hours      INT DEFAULT NULL,
    -- clothes-specific
    cloth_type      VARCHAR(80),
    is_clean        TINYINT(1) DEFAULT 1,
    -- study_material / school_supplies
    subject_grade   VARCHAR(100),
    book_count      INT DEFAULT NULL,
    -- medicines
    expiry_date     DATE,
    medicine_type   VARCHAR(100),
    -- electronics
    device_type     VARCHAR(100),
    working_status  ENUM('working','partially_working','not_working') DEFAULT 'working',
    -- meta
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_donor    (donor_email),
    INDEX idx_status   (status),
    INDEX idx_category (category),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
