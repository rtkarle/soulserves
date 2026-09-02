-- ============================================================
--  SoulServe — Performance Indexes
--  Run ONCE. All use IF NOT EXISTS / safe pattern.
-- ============================================================
USE adhaar_db;

-- food_donations: donor+status (donor dashboard loads this 5x per page)
ALTER TABLE food_donations
  ADD INDEX IF NOT EXISTS idx_donor_status   (donor_email, status),
  ADD INDEX IF NOT EXISTS idx_vol_status     (volunteer_email, status),
  ADD INDEX IF NOT EXISTS idx_status_created (status, created_at DESC),
  ADD INDEX IF NOT EXISTS idx_priority       (priority, status);

-- cloth_donations: same pattern
ALTER TABLE cloth_donations
  ADD INDEX IF NOT EXISTS idx_donor_status   (donor_email, status),
  ADD INDEX IF NOT EXISTS idx_vol_status     (volunteer_email, status),
  ADD INDEX IF NOT EXISTS idx_status_created (status, created_at DESC);

-- products: seller active products loaded on every shop page
ALTER TABLE products
  ADD INDEX IF NOT EXISTS idx_seller_active  (seller_email, is_active),
  ADD INDEX IF NOT EXISTS idx_cat_active     (category, is_active),
  ADD INDEX IF NOT EXISTS idx_sold_rating    (total_sold DESC, avg_rating DESC),
  ADD INDEX IF NOT EXISTS idx_price          (price),
  ADD INDEX IF NOT EXISTS idx_stock_active   (stock, is_active);

-- orders: seller dashboard aggregates
ALTER TABLE orders
  ADD INDEX IF NOT EXISTS idx_seller_status  (seller_email, order_status),
  ADD INDEX IF NOT EXISTS idx_buyer_status   (buyer_email, order_status),
  ADD INDEX IF NOT EXISTS idx_created        (created_at DESC);

-- register: role lookups on every dashboard load
ALTER TABLE register
  ADD INDEX IF NOT EXISTS idx_role_verified  (role, verified),
  ADD INDEX IF NOT EXISTS idx_email_verified (email, verified);

-- volunteer_tasks: workload queries
ALTER TABLE volunteer_tasks
  ADD INDEX IF NOT EXISTS idx_vol_status     (volunteer_email, task_status),
  ADD INDEX IF NOT EXISTS idx_don_type       (donation_id, donation_type);

-- cart: per-user count
ALTER TABLE cart
  ADD INDEX IF NOT EXISTS idx_user_email     (user_email);

SELECT CONCAT('✅ Indexes created: ', COUNT(*), ' total') AS result
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'adhaar_db';
