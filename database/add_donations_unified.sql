-- ============================================================
--  SoulServe — Donations Table Migration
--  Safe to run multiple times (uses SHOW COLUMNS checks)
-- ============================================================

USE adhaar_db;

-- ── 1. Add donation_id to legacy food_donations ──────────────
SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='food_donations' AND COLUMN_NAME='donation_id');
SET @s1 = IF(@c1=0, 'ALTER TABLE food_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id', 'SELECT 1');
PREPARE st FROM @s1; EXECUTE st; DEALLOCATE PREPARE st;

-- Backfill food_donations donation_id
UPDATE food_donations SET donation_id = CONCAT('DON-FOOD-', LPAD(id,6,'0')) WHERE donation_id IS NULL;

-- ── 2. Add donation_id to legacy cloth_donations ─────────────
SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cloth_donations' AND COLUMN_NAME='donation_id');
SET @s2 = IF(@c2=0, 'ALTER TABLE cloth_donations ADD COLUMN donation_id VARCHAR(30) DEFAULT NULL AFTER id', 'SELECT 1');
PREPARE st FROM @s2; EXECUTE st; DEALLOCATE PREPARE st;

-- Backfill cloth_donations donation_id
UPDATE cloth_donations SET donation_id = CONCAT('DON-CLO-', LPAD(id,6,'0')) WHERE donation_id IS NULL;

-- ── 3. Create unified donations table ────────────────────────
CREATE TABLE IF NOT EXISTS donations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donation_id     VARCHAR(30)  UNIQUE,
    donor_email     VARCHAR(180) NOT NULL,
    category        VARCHAR(30)  NOT NULL DEFAULT 'other',
    quantity        VARCHAR(100) NOT NULL DEFAULT '1',
    description     TEXT,
    condition_type  VARCHAR(20)  DEFAULT 'good',
    pickup_address  TEXT NOT NULL,
    contact         VARCHAR(20)  NOT NULL,
    pickup_date     DATE,
    pickup_time     TIME,
    image           VARCHAR(400),
    image2          VARCHAR(400),
    image3          VARCHAR(400),
    status          ENUM('pending','accepted','rejected','scheduled',
                         'out_for_pickup','picked_up','delivered')
                    NOT NULL DEFAULT 'pending',
    volunteer_email VARCHAR(180),
    notes           TEXT,
    priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    food_time       DATETIME,
    safe_hours      INT DEFAULT NULL,
    cloth_type      VARCHAR(80),
    is_clean        TINYINT(1) DEFAULT 1,
    subject_grade   VARCHAR(200),
    book_count      INT DEFAULT NULL,
    expiry_date     DATE,
    medicine_type   VARCHAR(100),
    device_type     VARCHAR(100),
    working_status  VARCHAR(30) DEFAULT 'working',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_donor    (donor_email),
    INDEX idx_status   (status),
    INDEX idx_category (category),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. Add image2/image3 to donations if missing ─────────────
SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='donations' AND COLUMN_NAME='image2');
SET @s3 = IF(@c3=0, 'ALTER TABLE donations ADD COLUMN image2 VARCHAR(400) DEFAULT NULL AFTER image', 'SELECT 1');
PREPARE st FROM @s3; EXECUTE st; DEALLOCATE PREPARE st;

SET @c4 = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='donations' AND COLUMN_NAME='image3');
SET @s4 = IF(@c4=0, 'ALTER TABLE donations ADD COLUMN image3 VARCHAR(400) DEFAULT NULL AFTER image2', 'SELECT 1');
PREPARE st FROM @s4; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 5. Verify ────────────────────────────────────────────────
SELECT 'Migration complete' AS status;
SELECT TABLE_NAME, COUNT(*) AS col_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('donations','food_donations','cloth_donations')
GROUP BY TABLE_NAME;
