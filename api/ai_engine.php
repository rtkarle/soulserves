<?php
/**
 * Adhaar AI Engine v1.0
 * Pure PHP rule-based + statistical AI — no external API needed.
 * Functions: volunteer scoring, donation validity check,
 *            demand forecasting, impact prediction, smart recommendations.
 */
require_once __DIR__ . '/../config/db.php';

class AdhaarAI {

    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /* ── 1. VOLUNTEER SCORING ────────────────────────────────
     * Scores volunteers 0–100 for a given donation.
     * Factors: city match (40pts), past completions (30pts),
     *          current workload (20pts), last active (10pts)
     */
    public function scoreVolunteers(int $donation_id, string $donation_type): array {
        $table = ($donation_type === 'food') ? 'food_donations' : 'cloth_donations';
        $don = $this->conn->query("SELECT pickup_address FROM $table WHERE id=$donation_id")->fetch_assoc();
        if (!$don) return [];

        // Extract city from address (last meaningful word)
        $addr_words = preg_split('/[\s,]+/', trim($don['pickup_address']));
        $city_hint  = strtolower($addr_words[count($addr_words)-1] ?? '');

        $volunteers = $this->conn->query(
            "SELECT r.email, r.name, r.address,
             (SELECT COUNT(*) FROM food_donations WHERE volunteer_email=r.email AND status='delivered')
             + (SELECT COUNT(*) FROM cloth_donations WHERE volunteer_email=r.email AND status='delivered') AS completed,
             (SELECT COUNT(*) FROM food_donations WHERE volunteer_email=r.email AND status IN ('scheduled','out_for_pickup','picked_up'))
             + (SELECT COUNT(*) FROM cloth_donations WHERE volunteer_email=r.email AND status IN ('scheduled','out_for_pickup','picked_up')) AS active_tasks,
             (SELECT MAX(created_at) FROM volunteer_tasks WHERE volunteer_email=r.email) AS last_task_at
             FROM register r WHERE r.role='volunteer' AND r.verified=1"
        )->fetch_all(MYSQLI_ASSOC);

        $scored = [];
        foreach ($volunteers as $v) {
            $score = 0;

            // City match — 40 pts
            $vol_addr = strtolower($v['address'] ?? '');
            if ($city_hint && strpos($vol_addr, $city_hint) !== false) $score += 40;
            elseif (strlen($city_hint) > 3) {
                // Partial city match
                similar_text($city_hint, $vol_addr, $pct);
                $score += (int)($pct * 0.4);
            }

            // Completed tasks — up to 30 pts (log scale)
            $completed = (int)$v['completed'];
            $score += min(30, (int)(log(max(1,$completed)+1, 2) * 10));

            // Workload penalty — deduct up to 20 pts for active tasks
            $active = (int)$v['active_tasks'];
            $score += max(0, 20 - ($active * 7));

            // Last active bonus — up to 10 pts (active in last 7 days = 10, 30 days = 5)
            if ($v['last_task_at']) {
                $days_ago = (time() - strtotime($v['last_task_at'])) / 86400;
                if ($days_ago <= 7)  $score += 10;
                elseif ($days_ago <= 30) $score += 5;
            } else {
                $score += 5; // New volunteer bonus
            }

            $scored[] = [
                'email'        => $v['email'],
                'name'         => $v['name'],
                'score'        => min(100, max(0, $score)),
                'completed'    => $completed,
                'active_tasks' => $active,
                'city_match'   => $city_hint && strpos($vol_addr,$city_hint) !== false,
            ];
        }

        usort($scored, fn($a,$b) => $b['score'] - $a['score']);
        return $scored;
    }

    /* ── 2. DONATION VALIDITY CHECK ─────────────────────────
     * Checks if a food donation is still safe to accept.
     * Returns: ['valid'=>bool, 'reason'=>string, 'urgency'=>string]
     */
    public function checkFoodValidity(int $donation_id): array {
        $d = $this->conn->query(
            "SELECT food_time, safe_hours, priority, quantity FROM food_donations WHERE id=$donation_id"
        )->fetch_assoc();
        if (!$d) return ['valid'=>false,'reason'=>'Donation not found','urgency'=>'unknown'];

        $prepared_at  = strtotime($d['food_time'] ?? 'now');
        $safe_seconds = (int)$d['safe_hours'] * 3600;
        $expires_at   = $prepared_at + $safe_seconds;
        $now          = time();
        $remaining_h  = round(($expires_at - $now) / 3600, 1);

        if ($now > $expires_at) {
            return ['valid'=>false,'reason'=>"Food expired {$remaining_h}h ago",'urgency'=>'expired'];
        }

        $urgency = 'low';
        if ($remaining_h <= 2)  $urgency = 'critical';
        elseif ($remaining_h <= 4)  $urgency = 'high';
        elseif ($remaining_h <= 8)  $urgency = 'medium';

        return [
            'valid'       => true,
            'reason'      => "Valid for {$remaining_h}h more",
            'urgency'     => $urgency,
            'remaining_h' => $remaining_h,
            'feeds'       => (int)$d['quantity'],
        ];
    }

    /* ── 3. DEMAND FORECAST ──────────────────────────────────
     * Analyses donation trends over past 4 weeks.
     * Returns: weekly averages, trend direction, predicted next week.
     */
    public function demandForecast(): array {
        $weeks = [];
        for ($i = 3; $i >= 0; $i--) {
            $from = date('Y-m-d', strtotime("-".($i+1)." weeks"));
            $to   = date('Y-m-d', strtotime("-$i weeks"));
            $food  = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE created_at BETWEEN '$from' AND '$to'")->fetch_assoc()['c'];
            $cloth = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE created_at BETWEEN '$from' AND '$to'")->fetch_assoc()['c'];
            $weeks[] = ['week'=>"Week ".($i===0?'(current)':"-$i"), 'food'=>$food,'cloth'=>$cloth,'total'=>$food+$cloth];
        }

        // Simple linear regression for next week prediction
        $totals = array_column($weeks,'total');
        $n = count($totals);
        $sum_x = $sum_y = $sum_xy = $sum_x2 = 0;
        foreach ($totals as $i => $y) {
            $sum_x  += $i; $sum_y  += $y;
            $sum_xy += $i * $y; $sum_x2 += $i * $i;
        }
        $slope = $n > 1 ? ($n*$sum_xy - $sum_x*$sum_y) / max(1, $n*$sum_x2 - $sum_x*$sum_x) : 0;
        $intercept = ($sum_y - $slope*$sum_x) / $n;
        $predicted_next = max(0, round($intercept + $slope * $n));

        $trend = $slope > 0.5 ? 'increasing' : ($slope < -0.5 ? 'decreasing' : 'stable');

        return [
            'weeks'          => $weeks,
            'trend'          => $trend,
            'slope'          => round($slope, 2),
            'predicted_next' => $predicted_next,
            'avg_per_week'   => $n > 0 ? round(array_sum($totals)/$n,1) : 0,
        ];
    }

    /* ── 4. IMPACT PREDICTION ────────────────────────────────
     * Predicts real-world impact numbers from donation counts.
     */
    public function predictImpact(): array {
        $food_del  = (int)$this->conn->query("SELECT COALESCE(SUM(quantity),0) c FROM food_donations WHERE status='delivered'")->fetch_assoc()['c'];
        $cloth_del = (int)$this->conn->query("SELECT COALESCE(SUM(quantity),0) c FROM cloth_donations WHERE status='delivered'")->fetch_assoc()['c'];
        $vols      = (int)$this->conn->query("SELECT COUNT(*) c FROM register WHERE role='volunteer' AND verified=1")->fetch_assoc()['c'];
        $areas     = $this->conn->query("SELECT COUNT(DISTINCT SUBSTRING_INDEX(pickup_address,' ',-1)) c FROM food_donations WHERE status='delivered'")->fetch_assoc()['c'];

        // AI multipliers based on NGO research data
        $people_fed      = (int)($food_del * 3.2);   // avg 3.2 people per food unit
        $co2_saved_kg    = round(($food_del * 2.5) + ($cloth_del * 1.8), 1); // kg CO2 saved
        $water_saved_ltr = round($food_del * 950, 0); // litres of water saved
        $economic_value  = round(($food_del * 120) + ($cloth_del * 250), 0); // ₹ value

        return [
            'people_fed'      => $people_fed,
            'co2_saved_kg'    => $co2_saved_kg,
            'water_saved_ltr' => $water_saved_ltr,
            'economic_value'  => $economic_value,
            'food_delivered'  => $food_del,
            'cloth_delivered' => $cloth_del,
            'volunteers'      => $vols,
            'areas_covered'   => (int)$areas,
        ];
    }

    /* ── 5. SMART RECOMMENDATIONS ───────────────────────────
     * For admin: what needs attention right now.
     */
    public function getAdminRecommendations(): array {
        $recs = [];

        // High-priority food pending
        $hp = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE status='pending' AND priority='high'")->fetch_assoc()['c'];
        if ($hp > 0) $recs[] = ['type'=>'urgent','icon'=>'🔴','msg'=>"$hp high-priority food donation".($hp>1?'s':'')." need immediate acceptance."];

        // Expiring food
        $exp = $this->conn->query("SELECT id, safe_hours, food_time FROM food_donations WHERE status='pending' AND food_time IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
        $expiring = 0;
        foreach ($exp as $e) {
            $expires = strtotime($e['food_time']) + $e['safe_hours']*3600;
            if ($expires - time() < 7200) $expiring++;
        }
        if ($expiring > 0) $recs[] = ['type'=>'urgent','icon'=>'⏰','msg'=>"$expiring food donation".($expiring>1?'s':'')." expiring within 2 hours!"];

        // Unverified sellers
        $uv = (int)$this->conn->query("SELECT COUNT(*) c FROM seller_stores WHERE is_verified=0")->fetch_assoc()['c'];
        if ($uv > 0) $recs[] = ['type'=>'info','icon'=>'🏪','msg'=>"$uv seller store".($uv>1?'s':'')." awaiting verification."];

        // Pending tasks not accepted
        $pt = (int)$this->conn->query("SELECT COUNT(*) c FROM volunteer_tasks WHERE task_status='pending_acceptance'")->fetch_assoc()['c'];
        if ($pt > 0) $recs[] = ['type'=>'warn','icon'=>'📋','msg'=>"$pt volunteer task".($pt>1?'s':'')." not yet accepted by volunteers."];

        // Return requests
        $rr = (int)$this->conn->query("SELECT COUNT(*) c FROM return_requests WHERE status='requested'")->fetch_assoc()['c'];
        if ($rr > 0) $recs[] = ['type'=>'warn','icon'=>'↩️','msg'=>"$rr return request".($rr>1?'s':'')." awaiting admin action."];

        // Positive: delivery rate
        $total = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations")->fetch_assoc()['c']
                +(int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations")->fetch_assoc()['c'];
        $del   = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE status='delivered'")->fetch_assoc()['c']
                +(int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE status='delivered'")->fetch_assoc()['c'];
        if ($total > 0) {
            $rate = round($del/$total*100);
            $recs[] = ['type'=>'success','icon'=>'📈','msg'=>"Delivery rate: $rate% — ".($rate>=80?'Excellent! Keep it up.':($rate>=50?'Good, but room to improve.':'Needs attention.'))];
        }

        if (empty($recs)) $recs[] = ['type'=>'success','icon'=>'✅','msg'=>'All systems operational. No immediate actions needed.'];
        return $recs;
    }

    /* ── 6. DONOR SUGGESTIONS ───────────────────────────────
     * Personalised suggestions for a specific donor.
     */
    public function getDonorSuggestions(string $donor_email): array {
        $food_count  = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE donor_email='".mysqli_real_escape_string($this->conn,$donor_email)."'")->fetch_assoc()['c'];
        $cloth_count = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE donor_email='".mysqli_real_escape_string($this->conn,$donor_email)."'")->fetch_assoc()['c'];
        $last_don    = $this->conn->query("SELECT MAX(created_at) last FROM (SELECT created_at FROM food_donations WHERE donor_email='".mysqli_real_escape_string($this->conn,$donor_email)."' UNION ALL SELECT created_at FROM cloth_donations WHERE donor_email='".mysqli_real_escape_string($this->conn,$donor_email)."') x")->fetch_assoc()['last'];

        $suggestions = [];
        $days_since = $last_don ? round((time()-strtotime($last_don))/86400) : 999;

        // What's needed most on platform
        $food_pending  = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE status='pending'")->fetch_assoc()['c'];
        $cloth_pending = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE status='pending'")->fetch_assoc()['c'];
        $needed_most   = $food_pending > $cloth_pending ? 'food 🍱' : 'clothing 👕';

        $suggestions[] = ['icon'=>'🤖','text'=>"The platform currently needs <strong>$needed_most</strong> donations most. Your contribution will make an immediate difference."];

        if ($days_since > 30) {
            $suggestions[] = ['icon'=>'⏰','text'=>"It's been <strong>$days_since days</strong> since your last donation. A small donation today can feed a family tonight."];
        }

        if ($food_count === 0) {
            $suggestions[] = ['icon'=>'🍱','text'=>"You haven't donated food yet. Cooked food donations are the most urgent — they help families the same day!"];
        } elseif ($cloth_count === 0) {
            $suggestions[] = ['icon'=>'👕','text'=>"Try a <strong>clothing donation</strong>! Unused clothes have a huge impact, especially for children in rural areas."];
        }

        // Impact calculator
        $impact_food  = $food_count * 15;  // avg 15 people fed per food donation
        $impact_cloth = $cloth_count * 3;  // avg 3 people per clothing donation
        $suggestions[] = ['icon'=>'💚','text'=>"Your <strong>$food_count food + $cloth_count clothing</strong> donations have impacted approximately <strong>".($impact_food+$impact_cloth)." people</strong> so far."];

        // Seasonal suggestion
        $month = (int)date('n');
        if ($month >= 11 || $month <= 2) {
            $suggestions[] = ['icon'=>'❄️','text'=>"Winter is here! <strong>Warm clothing donations</strong> — jackets, sweaters, blankets — are urgently needed for rural communities."];
        } elseif ($month >= 6 && $month <= 8) {
            $suggestions[] = ['icon'=>'🌧️','text'=>"Monsoon season: <strong>food donations</strong> are critical as outdoor activities are limited and families face hardship."];
        }

        return $suggestions;
    }

    /* ── 7. SELLER RECOMMENDATIONS ─────────────────────────
     * AI-powered suggestions for a seller's store performance.
     */
    public function getSellerRecommendations(string $seller_email): array {
        $email = mysqli_real_escape_string($this->conn, $seller_email);
        $product_count = (int)$this->conn->query("SELECT COUNT(*) c FROM products WHERE seller_email='$email'")->fetch_assoc()['c'];
        $pending_orders = (int)$this->conn->query("SELECT COUNT(*) c FROM orders WHERE seller_email='$email' AND order_status IN ('placed','packed','shipped')")->fetch_assoc()['c'];
        $low_stock = (int)$this->conn->query("SELECT COUNT(*) c FROM products WHERE seller_email='$email' AND stock_quantity <= 5 AND is_active=1")->fetch_assoc()['c'];
        $top_cat = $this->conn->query("SELECT category, COUNT(*) c FROM products WHERE seller_email='$email' GROUP BY category ORDER BY c DESC LIMIT 1")->fetch_assoc();

        $recs = [];
        if ($product_count === 0) {
            $recs[] = ['icon' => '✨', 'text' => 'Your store is blank. Add your first product to start selling and build an artisan brand.'];
        } else {
            $recs[] = ['icon' => '📦', 'text' => "You currently have <strong>$product_count live products</strong> in your catalog."];
        }

        if ($pending_orders > 0) {
            $recs[] = ['icon' => '🚚', 'text' => "You have <strong>$pending_orders active orders</strong> awaiting attention. Faster fulfillment boosts trust and repeat buyers."];
        } else {
            $recs[] = ['icon' => '✅', 'text' => 'Your order pipeline is healthy. Focus on product photography and bundles to improve conversion.'];
        }

        if ($low_stock > 0) {
            $recs[] = ['icon' => '⚠️', 'text' => "<strong>$low_stock products</strong> are low in stock. Refill popular items to avoid lost sales."];
        }

        if ($top_cat) {
            $recs[] = ['icon' => '📈', 'text' => "Your best-performing category is <strong>{$top_cat['category']}</strong>. Reuse that theme for launches and bundles."];
        } else {
            $recs[] = ['icon' => '💡', 'text' => 'AI recommends focusing on handmade, seasonal, and premium local products with sharper imagery.'];
        }

        return $recs;
    }

    /* ── 8. VOLUNTEER RECOMMENDATIONS ───────────────────────
     * AI suggestions for volunteers based on workload, task history, and preferred donation type.
     */
    public function getVolunteerRecommendations(string $volunteer_email): array {
        $email = mysqli_real_escape_string($this->conn, $volunteer_email);
        $completed = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE volunteer_email='$email' AND status='delivered' UNION SELECT COUNT(*) c FROM cloth_donations WHERE volunteer_email='$email' AND status='delivered'")->fetch_assoc()['c'];

        $food_completed = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE volunteer_email='$email' AND status='delivered'")->fetch_assoc()['c'];
        $cloth_completed = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE volunteer_email='$email' AND status='delivered'")->fetch_assoc()['c'];
        $pending = (int)$this->conn->query("SELECT COUNT(*) c FROM volunteer_tasks WHERE volunteer_email='$email' AND task_status='pending_acceptance'")->fetch_assoc()['c'];

        $recs = [];

        if ($food_completed >= $cloth_completed) {
            $recs[] = ['icon' => '🍱', 'text' => 'Your strongest area is <strong>food pickups</strong>. Stay available for nearby urgent food donation windows.'];
        } else {
            $recs[] = ['icon' => '👕', 'text' => 'Your strongest area is <strong>clothing pickups</strong>. High-volume textile drives often need quick response.'];
        }

        if ($pending > 0) {
            $recs[] = ['icon' => '📋', 'text' => "You have <strong>$pending task requests</strong> waiting for acceptance. Review them to keep the pickup pipeline moving."];
        } else {
            $recs[] = ['icon' => '✅', 'text' => 'Your queue is free. Keep your location and contact details updated for better auto-assignment.'];
        }

        $total_completed = $food_completed + $cloth_completed;
        if ($total_completed === 0) {
            $recs[] = ['icon' => '🚀', 'text' => 'You are new to volunteering. Accept the first nearby task and build a strong delivery record.'];
        } else {
            $recs[] = ['icon' => '🏆', 'text' => "You have completed <strong>$total_completed pickups</strong> so far. Consistency in timely delivery creates faster future assignments."];
        }

        return $recs;
    }

    /* ── 8. PRODUCT RECOMMENDATIONS ────────────────────────
     * Returns personalised product recommendations for a user
     * based on: search history, view history, purchase history, and category preference.
     * Returns array of product rows scored and ranked.
     */
    public function getProductRecommendations(string $user_email, int $current_product_id = 0, int $limit = 6): array {
        $me = mysqli_real_escape_string($this->conn, $user_email);

        // ── Collect signals ──────────────────────────────────
        // 1. Categories from search history (last 30 days)
        $search_cats = [];
        $sh_exist = $this->conn->query("SHOW TABLES LIKE 'product_search_history'")->num_rows > 0;
        if ($sh_exist) {
            $sq = $this->conn->query(
                "SELECT category, COUNT(*) w FROM product_search_history
                 WHERE user_email='$me' AND searched_at > NOW() - INTERVAL 30 DAY
                 AND category IS NOT NULL GROUP BY category ORDER BY w DESC LIMIT 5"
            );
            while ($r = $sq->fetch_assoc()) $search_cats[$r['category']] = (int)$r['w'];
        }

        // 2. Products viewed (last 30 days)
        $viewed_ids = [];
        $vh_exist = $this->conn->query("SHOW TABLES LIKE 'product_view_history'")->num_rows > 0;
        if ($vh_exist) {
            $vq = $this->conn->query(
                "SELECT product_id, view_count FROM product_view_history
                 WHERE user_email='$me' AND last_viewed > NOW() - INTERVAL 30 DAY
                 ORDER BY view_count DESC, last_viewed DESC LIMIT 20"
            );
            while ($r = $vq->fetch_assoc()) $viewed_ids[$r['product_id']] = (int)$r['view_count'];
        }

        // 3. Categories from purchase history
        $bought_cats = [];
        $bq = $this->conn->query(
            "SELECT p.category, COUNT(*) w FROM order_items oi
             JOIN orders o ON o.id=oi.order_id
             JOIN products p ON p.id=oi.product_id
             WHERE o.buyer_email='$me'
             GROUP BY p.category ORDER BY w DESC LIMIT 5"
        );
        while ($r = $bq->fetch_assoc()) $bought_cats[$r['category']] = (int)$r['w'];

        // ── Build category preference score ──────────────────
        $cat_scores = [];
        foreach ($search_cats as $cat => $w) $cat_scores[$cat] = ($cat_scores[$cat] ?? 0) + $w * 3; // search = 3x weight
        foreach ($bought_cats as $cat => $w) $cat_scores[$cat] = ($cat_scores[$cat] ?? 0) + $w * 5; // purchase = 5x weight
        // Viewed products also boost their category
        if (!empty($viewed_ids)) {
            $id_list = implode(',', array_map('intval', array_keys($viewed_ids)));
            $vcq = $this->conn->query("SELECT category FROM products WHERE id IN ($id_list)");
            while ($r = $vcq->fetch_assoc()) {
                $cat_scores[$r['category']] = ($cat_scores[$r['category']] ?? 0) + 2;
            }
        }
        arsort($cat_scores);
        $top_cats = array_keys(array_slice($cat_scores, 0, 3, true));

        // ── Fetch candidate products ──────────────────────────
        $exclude = $current_product_id > 0 ? "AND p.id != $current_product_id" : '';
        $all_q = $this->conn->query(
            "SELECT p.*, s.store_name, s.village, s.state
             FROM products p JOIN seller_stores s ON s.seller_email=p.seller_email
             WHERE p.is_active=1 AND s.is_active=1 $exclude
             ORDER BY p.total_sold DESC, p.avg_rating DESC LIMIT 100"
        );
        $candidates = $all_q->fetch_all(MYSQLI_ASSOC);

        // ── Score each candidate ──────────────────────────────
        foreach ($candidates as &$prod) {
            $s = 0;
            $cat = $prod['category'];

            // Category preference
            $s += ($cat_scores[$cat] ?? 0) * 8;

            // Popularity (sold count)
            $s += min(30, (int)log(max(1, $prod['total_sold']) + 1, 2) * 6);

            // Rating boost
            $s += (float)$prod['avg_rating'] * 8;

            // Already viewed → slight boost (familiarity effect)
            if (isset($viewed_ids[$prod['id']])) $s += 5;

            // Discount available → boost
            if ($prod['mrp'] > 0 && $prod['price'] < $prod['mrp']) $s += 10;

            // Freshness (listed in last 30 days)
            if (strtotime($prod['created_at']) > strtotime('-30 days')) $s += 8;

            $prod['_rec_score'] = round($s, 2);
        }
        unset($prod);

        // ── Sort by score, return top N ───────────────────────
        usort($candidates, fn($a,$b) => $b['_rec_score'] <=> $a['_rec_score']);
        return array_slice($candidates, 0, $limit);
    }

    /* ── 9. SCORE VOLUNTEERS WITH DISTANCE ──────────────────
     * Enhanced volunteer scoring with:
     *   - Pincode-based distance estimation (40 pts)
     *   - Past completion rate (25 pts)
     *   - Current availability / workload (20 pts)
     *   - Last active recency (10 pts)
     *   - Response rate (5 pts)
     */
    public function scoreVolunteersWithDistance(int $donation_id, string $donation_type): array {
        $table = ($donation_type === 'food') ? 'food_donations' : 'cloth_donations';

        // Get donation address and pincode
        $don = $this->conn->query(
            "SELECT pickup_address, donor_pincode FROM $table WHERE id=$donation_id"
        )->fetch_assoc();
        if (!$don) return [];

        $donor_pin  = trim($don['donor_pincode'] ?? '');
        $donor_addr = strtolower($don['pickup_address'] ?? '');

        // Extract city hint from address (last word before pincode)
        $addr_words = preg_split('/[\s,]+/', $donor_addr);
        // Find 6-digit pincode in address if not stored
        if (!$donor_pin) {
            foreach ($addr_words as $w) {
                if (preg_match('/^\d{6}$/', $w)) { $donor_pin = $w; break; }
            }
        }
        $city_hint = '';
        foreach (array_reverse($addr_words) as $w) {
            if (strlen($w) > 3 && !is_numeric($w)) { $city_hint = $w; break; }
        }

        // Get all volunteers with pincode
        $volunteers = $this->conn->query(
            "SELECT r.email, r.name, r.address, r.mobile,
             COALESCE(r.pincode,'') AS vol_pincode,
             (SELECT COUNT(*) FROM food_donations WHERE volunteer_email=r.email AND status='delivered')
             + (SELECT COUNT(*) FROM cloth_donations WHERE volunteer_email=r.email AND status='delivered') AS completed,
             (SELECT COUNT(*) FROM food_donations WHERE volunteer_email=r.email AND status IN ('scheduled','out_for_pickup','picked_up'))
             + (SELECT COUNT(*) FROM cloth_donations WHERE volunteer_email=r.email AND status IN ('scheduled','out_for_pickup','picked_up')) AS active_tasks,
             (SELECT COUNT(*) FROM volunteer_tasks WHERE volunteer_email=r.email) AS total_tasks,
             (SELECT COUNT(*) FROM volunteer_tasks WHERE volunteer_email=r.email AND task_status='accepted') AS accepted_tasks,
             (SELECT MAX(assigned_at) FROM volunteer_tasks WHERE volunteer_email=r.email) AS last_task_at
             FROM register r WHERE r.role='volunteer' AND r.verified=1"
        )->fetch_all(MYSQLI_ASSOC);

        $scored = [];
        foreach ($volunteers as $v) {
            $score     = 0;
            $breakdown = [];

            // ── DISTANCE SCORING (40 pts) ─────────────────────
            $vol_pin  = trim($v['vol_pincode']);
            $vol_addr = strtolower($v['address'] ?? '');

            if ($donor_pin && $vol_pin && $donor_pin === $vol_pin) {
                // Same pincode = same area
                $score += 40;
                $breakdown['distance'] = 'Same pincode (+40)';
            } elseif ($donor_pin && $vol_pin) {
                // Compare first 3 digits of pincode (same district in India)
                $same_district = substr($donor_pin,0,3) === substr($vol_pin,0,3);
                $same_subdistrict = substr($donor_pin,0,4) === substr($vol_pin,0,4);
                if ($same_subdistrict) {
                    $score += 30;
                    $breakdown['distance'] = 'Same sub-district (+30)';
                } elseif ($same_district) {
                    $score += 20;
                    $breakdown['distance'] = 'Same district (+20)';
                } else {
                    // Different district — estimate by first digit (same state zone)
                    $same_zone = $donor_pin[0] === $vol_pin[0];
                    $score += $same_zone ? 8 : 2;
                    $breakdown['distance'] = $same_zone ? 'Same state zone (+8)' : 'Different zone (+2)';
                }
            } elseif ($city_hint && strpos($vol_addr, $city_hint) !== false) {
                // Fallback: city name match
                $score += 25;
                $breakdown['distance'] = "City name match '$city_hint' (+25)";
            } elseif (strlen($city_hint) > 3) {
                similar_text($city_hint, $vol_addr, $pct);
                $pts = (int)($pct * 0.3);
                $score += $pts;
                $breakdown['distance'] = "Partial city match {$pct}% (+$pts)";
            } else {
                $breakdown['distance'] = 'No location match (+0)';
            }

            // ── COMPLETION RATE (25 pts) ──────────────────────
            $completed = (int)$v['completed'];
            $pts = min(25, (int)(log(max(1,$completed)+1, 2) * 8));
            $score += $pts;
            $breakdown['completions'] = "$completed completed (+$pts)";

            // ── AVAILABILITY / WORKLOAD (20 pts) ─────────────
            $active = (int)$v['active_tasks'];
            $avail_pts = max(0, 20 - ($active * 7));
            $score += $avail_pts;
            $breakdown['workload'] = "$active active tasks (+$avail_pts)";

            // ── RECENCY (10 pts) ──────────────────────────────
            $recency_pts = 5; // default for new volunteer
            if ($v['last_task_at']) {
                $days_ago = (time() - strtotime($v['last_task_at'])) / 86400;
                if ($days_ago <= 3)       $recency_pts = 10;
                elseif ($days_ago <= 7)   $recency_pts = 8;
                elseif ($days_ago <= 14)  $recency_pts = 6;
                elseif ($days_ago <= 30)  $recency_pts = 4;
                else                      $recency_pts = 1;
            }
            $score += $recency_pts;
            $breakdown['recency'] = "Last active: $recency_pts/10";

            // ── RESPONSE RATE (5 pts) ─────────────────────────
            $total   = (int)$v['total_tasks'];
            $accepted= (int)$v['accepted_tasks'];
            $resp_rate = $total > 0 ? round($accepted/$total*100) : 100;
            $resp_pts = (int)($resp_rate / 20); // 100% → 5pts, 80% → 4pts etc.
            $score += $resp_pts;
            $breakdown['response_rate'] = "$resp_rate% response (+$resp_pts)";

            $scored[] = [
                'email'         => $v['email'],
                'name'          => $v['name'],
                'mobile'        => $v['mobile'],
                'score'         => min(100, max(0, $score)),
                'completed'     => $completed,
                'active_tasks'  => $active,
                'vol_pincode'   => $vol_pin,
                'donor_pincode' => $donor_pin,
                'city_match'    => $city_hint && strpos($vol_addr,$city_hint) !== false,
                'response_rate' => $resp_rate,
                'breakdown'     => $breakdown,
            ];
        }

        usort($scored, fn($a,$b) => $b['score'] - $a['score']);
        return $scored;
    }

    /* ── 10. LOG SEARCH / VIEW ───────────────────────────────
     * Tracks search queries and product views for recommendations.
     */
    public function logSearch(string $user_email, string $query, ?string $category, int $result_count): void {
        if (!$this->conn->query("SHOW TABLES LIKE 'product_search_history'")->num_rows) return;
        try {
            $s = $this->conn->prepare(
                "INSERT INTO product_search_history (user_email,query,category,result_count,searched_at) VALUES (?,?,?,?,NOW())"
            );
            $s->bind_param("sssi", $user_email, $query, $category, $result_count);
            $s->execute();
        } catch (Exception $e) {}
    }

    public function logView(string $user_email, int $product_id): void {
        if (!$this->conn->query("SHOW TABLES LIKE 'product_view_history'")->num_rows) return;
        try {
            $s = $this->conn->prepare(
                "INSERT INTO product_view_history (user_email,product_id,view_count,last_viewed)
                 VALUES (?,?,1,NOW())
                 ON DUPLICATE KEY UPDATE view_count=view_count+1, last_viewed=NOW()"
            );
            $s->bind_param("si", $user_email, $product_id);
            $s->execute();
        } catch (Exception $e) {}
    }

    /* ══════════════════════════════════════════════════════════
     *  NEW AI METHODS v2.0
     * ══════════════════════════════════════════════════════════ */

    /* ── A. FRAUD / DUPLICATE DETECTION ────────────────────────
     * Detects suspicious duplicate or fraudulent donation activity.
     * Returns ['risk'=>'low|medium|high', 'flags'=>[...], 'score'=>0-100]
     */
    public function detectDonationFraud(string $donor_email, string $type, string $address, int $quantity): array {
        $me    = mysqli_real_escape_string($this->conn, $donor_email);
        $table = $type === 'food' ? 'food_donations' : 'cloth_donations';
        $flags = [];
        $score = 0;

        // Check duplicate submission in last 10 min
        $recent = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM `$table`
             WHERE donor_email='$me' AND created_at > NOW() - INTERVAL 10 MINUTE"
        )->fetch_assoc()['c'];
        if ($recent >= 2) { $flags[] = ['type'=>'duplicate','msg'=>"$recent submissions in last 10 minutes"]; $score += 40; }

        // Check same address same day
        $addr_esc = mysqli_real_escape_string($this->conn, substr($address, 0, 50));
        $same_addr = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM `$table`
             WHERE donor_email='$me' AND pickup_address LIKE '$addr_esc%'
             AND DATE(created_at)=CURDATE()"
        )->fetch_assoc()['c'];
        if ($same_addr >= 2) { $flags[] = ['type'=>'same_address','msg'=>'Same address used multiple times today']; $score += 25; }

        // Abnormally high quantity
        $avg_qty = (float)$this->conn->query(
            "SELECT COALESCE(AVG(quantity),0) avg FROM `$table` WHERE donor_email='$me'"
        )->fetch_assoc()['avg'];
        if ($avg_qty > 0 && $quantity > $avg_qty * 5) {
            $flags[] = ['type'=>'abnormal_qty','msg'=>"Quantity $quantity is 5x above your average of ".round($avg_qty)]; $score += 20;
        }

        // Rapid burst — more than 5 donations in 1 hour
        $burst = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM (
                SELECT created_at FROM food_donations WHERE donor_email='$me' AND created_at > NOW()-INTERVAL 1 HOUR
                UNION ALL
                SELECT created_at FROM cloth_donations WHERE donor_email='$me' AND created_at > NOW()-INTERVAL 1 HOUR
             ) x"
        )->fetch_assoc()['c'];
        if ($burst >= 5) { $flags[] = ['type'=>'burst','msg'=>"$burst donations in last hour — unusually high"]; $score += 30; }

        $risk = $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low');
        return ['risk'=>$risk, 'score'=>min(100,$score), 'flags'=>$flags];
    }

    /* ── B. SMART NEED MATCHING ─────────────────────────────────
     * Matches a donation to the community/NGO that needs it most urgently.
     * Returns top matches with urgency scores.
     */
    public function matchDonationToNeed(string $type, int $quantity, string $pickup_address): array {
        $this->conn->query("CREATE TABLE IF NOT EXISTS ngo_profiles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ngo_email VARCHAR(180) NOT NULL UNIQUE,
            ngo_name VARCHAR(220) NOT NULL,
            city VARCHAR(100),
            pincode VARCHAR(10),
            category ENUM('food_relief','clothing','education','healthcare','general') DEFAULT 'general',
            capacity_daily INT DEFAULT 50,
            is_verified TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // Extract city hint from address
        $addr_words = preg_split('/[\s,]+/', strtolower($pickup_address));
        $city_hint  = '';
        foreach (array_reverse($addr_words) as $w) {
            if (strlen($w) > 3 && !is_numeric($w)) { $city_hint = $w; break; }
        }

        $ngos = $this->conn->query("SELECT * FROM ngo_profiles WHERE is_verified=1 ORDER BY capacity_daily DESC LIMIT 10");
        if (!$ngos) {
            // Fallback: no NGOs yet — return platform's own distribution
            return [[
                'name'       => 'SoulServe Distribution Network',
                'city'       => 'Local',
                'match_pct'  => 95,
                'urgency'    => 'high',
                'reason'     => 'Platform volunteers will directly distribute to identified beneficiaries in your area.',
                'capacity'   => 100,
            ]];
        }

        $matches = [];
        foreach ($ngos->fetch_all(MYSQLI_ASSOC) as $ngo) {
            $score = 0;
            // Category match
            $cat_match = ($type === 'food' && $ngo['category'] === 'food_relief') ||
                         ($type === 'cloth' && $ngo['category'] === 'clothing') ||
                         $ngo['category'] === 'general';
            if ($cat_match) $score += 40;

            // Location match
            if ($city_hint && strtolower($ngo['city'] ?? '') === $city_hint) $score += 40;
            elseif ($city_hint && stripos($ngo['city'] ?? '', $city_hint) !== false) $score += 20;

            // Capacity match
            if ($quantity <= $ngo['capacity_daily']) $score += 20;
            elseif ($quantity <= $ngo['capacity_daily'] * 2) $score += 10;

            $urgency = $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');
            $matches[] = [
                'name'      => $ngo['ngo_name'],
                'city'      => $ngo['city'] ?? '—',
                'match_pct' => min(100, $score),
                'urgency'   => $urgency,
                'capacity'  => $ngo['capacity_daily'],
                'reason'    => $cat_match ? "Specialises in $type distribution" : "General purpose NGO",
            ];
        }
        usort($matches, fn($a,$b) => $b['match_pct'] - $a['match_pct']);
        return array_slice($matches ?: [[
            'name'      => 'SoulServe Volunteer Network',
            'city'      => 'Local',
            'match_pct' => 90,
            'urgency'   => 'high',
            'capacity'  => 100,
            'reason'    => 'Direct community distribution by trained volunteers.',
        ]], 0, 3);
    }

    /* ── C. ETA PREDICTION ──────────────────────────────────────
     * Predicts estimated pickup/delivery time for a donation.
     * Returns minutes estimate + confidence + factors.
     */
    public function predictETA(int $donation_id, string $type): array {
        $table = $type === 'food' ? 'food_donations' : 'cloth_donations';
        $d = $this->conn->query("SELECT status, priority, created_at, volunteer_email FROM `$table` WHERE id=$donation_id")->fetch_assoc();
        if (!$d) return ['eta_minutes'=>60,'confidence'=>50,'status_msg'=>'Unknown donation'];

        $status   = $d['status'] ?? 'pending';
        $priority = $d['priority'] ?? 'medium';

        // Base time by status
        $base_min = match($status) {
            'pending'       => $priority==='high' ? 30 : ($priority==='medium' ? 90 : 180),
            'accepted'      => $priority==='high' ? 20 : 60,
            'scheduled'     => 30,
            'out_for_pickup'=> 15,
            'picked_up'     => 20,
            default         => 0,
        };

        // Adjust by volunteer workload
        if ($d['volunteer_email']) {
            $vol_load = (int)$this->conn->query(
                "SELECT COUNT(*) c FROM food_donations WHERE volunteer_email='{$d['volunteer_email']}' AND status NOT IN ('delivered','rejected')
                 UNION ALL SELECT COUNT(*) c FROM cloth_donations WHERE volunteer_email='{$d['volunteer_email']}' AND status NOT IN ('delivered','rejected')"
            )->fetch_assoc()['c'];
            $base_min += $vol_load * 10;
        }

        // Adjust by time of day
        $hour = (int)date('H');
        if ($hour >= 22 || $hour < 7) $base_min += 120; // night penalty
        elseif ($hour >= 7 && $hour <= 10) $base_min -= 15; // morning boost

        $confidence = $status === 'out_for_pickup' ? 90 : ($status === 'scheduled' ? 80 : 55);
        $eta_min    = max(5, $base_min);

        $human_eta = $eta_min < 60
            ? "$eta_min minutes"
            : round($eta_min/60,1) . " hour" . (round($eta_min/60,1) != 1 ? 's' : '');

        return [
            'eta_minutes' => $eta_min,
            'eta_human'   => $human_eta,
            'confidence'  => $confidence,
            'priority'    => $priority,
            'status_msg'  => "Expected " . ($status === 'delivered' ? 'already delivered' : "in ~$human_eta"),
        ];
    }

    /* ── D. SMART RECURRING SUGGESTION ──────────────────────────
     * Analyses donor's pattern and suggests a recurring schedule.
     */
    public function suggestRecurring(string $donor_email): array {
        $me = mysqli_real_escape_string($this->conn, $donor_email);

        // Get donation dates
        $dates = $this->conn->query(
            "SELECT DATE(created_at) AS d FROM food_donations WHERE donor_email='$me'
             UNION ALL
             SELECT DATE(created_at) FROM cloth_donations WHERE donor_email='$me'
             ORDER BY d DESC LIMIT 20"
        )->fetch_all(MYSQLI_ASSOC);

        if (count($dates) < 2) {
            return ['has_pattern'=>false,'suggestion'=>'Make 2+ donations to unlock recurring pattern detection.'];
        }

        // Calculate average gap between donations
        $timestamps = array_map(fn($r)=>strtotime($r['d']), $dates);
        sort($timestamps);
        $gaps = [];
        for ($i=1; $i<count($timestamps); $i++) {
            $gaps[] = ($timestamps[$i] - $timestamps[$i-1]) / 86400;
        }
        $avg_gap  = array_sum($gaps) / count($gaps);
        $frequency = $avg_gap <= 8 ? 'weekly' : ($avg_gap <= 16 ? 'biweekly' : 'monthly');

        // Most common day of week
        $day_counts = array_fill(0,7,0);
        foreach ($timestamps as $ts) $day_counts[(int)date('w',$ts)]++;
        $best_day  = array_search(max($day_counts), $day_counts);
        $day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        // Most common donation type
        $food_cnt  = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE donor_email='$me'")->fetch_assoc()['c'];
        $cloth_cnt = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE donor_email='$me'")->fetch_assoc()['c'];
        $pref_type = $food_cnt >= $cloth_cnt ? 'food' : 'clothing';

        return [
            'has_pattern'  => true,
            'frequency'    => $frequency,
            'best_day'     => $day_names[$best_day],
            'best_day_num' => $best_day,
            'pref_type'    => $pref_type,
            'avg_gap_days' => round($avg_gap, 1),
            'suggestion'   => "Based on your history, schedule a $frequency $pref_type donation on {$day_names[$best_day]}s.",
            'confidence'   => min(95, 50 + count($dates) * 4),
        ];
    }

    /* ── E. PERSONALIZED CAUSES ─────────────────────────────────
     * Recommends causes based on donor's location, interests, history.
     */
    public function getPersonalizedCauses(string $donor_email): array {
        $me      = mysqli_real_escape_string($this->conn, $donor_email);
        $food_q  = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE donor_email='$me'")->fetch_assoc()['c'];
        $cloth_q = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE donor_email='$me'")->fetch_assoc()['c'];

        $causes = [];
        $month  = (int)date('n');

        // Season-based causes
        if ($month >= 11 || $month <= 1) {
            $causes[] = ['icon'=>'❄️','title'=>'Winter Clothing Drive','urgency'=>'high',
                'desc'=>'Rural families need warm clothes — jackets, sweaters, blankets urgently needed.','action'=>'Donate Clothing','url'=>'donate.php?type=cloth'];
        } elseif ($month >= 6 && $month <= 8) {
            $causes[] = ['icon'=>'🌧️','title'=>'Monsoon Food Relief','urgency'=>'high',
                'desc'=>'Flood-affected families need cooked meals. Food donations have 2x impact this season.','action'=>'Donate Food','url'=>'donate.php?type=food'];
        } elseif ($month >= 3 && $month <= 5) {
            $causes[] = ['icon'=>'☀️','title'=>'Summer Nutrition Drive','urgency'=>'medium',
                'desc'=>'Children in rural areas need nutrition support in peak summer. Your food donation can help.','action'=>'Donate Food','url'=>'donate.php?type=food'];
        }

        // Based on history
        if ($food_q === 0) {
            $causes[] = ['icon'=>'🍱','title'=>'First Food Donation','urgency'=>'high',
                'desc'=>'You have never donated food. One meal donation can feed a family of 5 tonight.','action'=>'Donate Food','url'=>'donate.php'];
        }
        if ($cloth_q === 0) {
            $causes[] = ['icon'=>'👕','title'=>'First Clothing Donation','urgency'=>'medium',
                'desc'=>'Your unused clothes can restore dignity for someone who has none.','action'=>'Donate Clothes','url'=>'donate.php'];
        }

        // Platform needs
        $pending_food  = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE status='pending'")->fetch_assoc()['c'];
        $pending_cloth = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE status='pending'")->fetch_assoc()['c'];
        if ($pending_food < $pending_cloth) {
            $causes[] = ['icon'=>'🍛','title'=>'Food Needed Now','urgency'=>'urgent',
                'desc'=>"Platform currently has $pending_food food donations pending — very low. Families are waiting.",'action'=>'Donate Food','url'=>'donate.php'];
        }

        $causes[] = ['icon'=>'🤝','title'=>'Volunteer & Drive Impact','urgency'=>'low',
            'desc'=>'Join our volunteer network and deliver donations in your area. Turn 2 hours into infinite impact.','action'=>'Volunteer','url'=>'../index.html#volunteer'];

        return array_slice($causes, 0, 4);
    }

    /* ── F. MONTHLY IMPACT REPORT ───────────────────────────────
     * Generates a personalized monthly impact summary for a donor.
     */
    public function generateMonthlyReport(string $donor_email, int $month = 0, int $year = 0): array {
        if (!$month) $month = (int)date('n');
        if (!$year)  $year  = (int)date('Y');
        $me      = mysqli_real_escape_string($this->conn, $donor_email);
        $m_start = "$year-" . str_pad($month,2,'0',STR_PAD_LEFT) . "-01";
        $m_end   = date('Y-m-t', strtotime($m_start));

        $food  = $this->conn->query("SELECT COUNT(*) c, COALESCE(SUM(quantity),0) qty FROM food_donations WHERE donor_email='$me' AND created_at BETWEEN '$m_start' AND '$m_end 23:59:59'")->fetch_assoc();
        $cloth = $this->conn->query("SELECT COUNT(*) c, COALESCE(SUM(quantity),0) qty FROM cloth_donations WHERE donor_email='$me' AND created_at BETWEEN '$m_start' AND '$m_end 23:59:59'")->fetch_assoc();

        $food_count  = (int)$food['c'];
        $cloth_count = (int)$cloth['c'];
        $food_qty    = (int)$food['qty'];
        $cloth_qty   = (int)$cloth['qty'];
        $total       = $food_count + $cloth_count;

        $people_fed   = $food_qty * 3;
        $co2_saved    = round($food_qty * 2.5 + $cloth_qty * 1.8, 1);
        $eco_value    = $food_qty * 120 + $cloth_qty * 250;

        // All-time stats for comparison
        $all_time_food  = (int)$this->conn->query("SELECT COUNT(*) c FROM food_donations WHERE donor_email='$me'")->fetch_assoc()['c'];
        $all_time_cloth = (int)$this->conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE donor_email='$me'")->fetch_assoc()['c'];

        // Get badges earned this month
        $badges = [];
        try {
            $bq = $this->conn->query("SELECT badge_name, badge_emoji FROM donor_badges WHERE donor_email='$me' AND earned_at BETWEEN '$m_start' AND '$m_end 23:59:59'");
            if ($bq) $badges = $bq->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $e) {}

        $month_names = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        return [
            'month'        => $month_names[$month] . ' ' . $year,
            'food_count'   => $food_count,
            'cloth_count'  => $cloth_count,
            'total'        => $total,
            'food_qty'     => $food_qty,
            'cloth_qty'    => $cloth_qty,
            'people_fed'   => $people_fed,
            'co2_saved'    => $co2_saved,
            'eco_value'    => $eco_value,
            'all_time'     => $all_time_food + $all_time_cloth,
            'badges_earned'=> $badges,
            'is_active'    => $total > 0,
            'rank_msg'     => $total >= 5 ? '🏆 You were a top donor this month!' :
                             ($total >= 2 ? '⭐ Great month! Keep it up.' :
                             ($total === 1 ? '🌱 Good start! Aim for 2+ next month.' : '💤 No donations this month.')),
            'ai_summary'   => $total > 0
                ? "This month you donated {$food_count} food and {$cloth_count} clothing items, feeding approximately {$people_fed} people and saving {$co2_saved}kg of CO₂ emissions."
                : "No donations recorded this month. Your community needs you — even one donation makes a measurable difference.",
        ];
    }

    /* ── G. WORKLOAD BALANCE ────────────────────────────────────
     * Checks if a volunteer is overloaded and suggests alternatives.
     */
    public function checkVolunteerWorkload(string $volunteer_email): array {
        $me       = mysqli_real_escape_string($this->conn, $volunteer_email);
        $active   = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM food_donations WHERE volunteer_email='$me' AND status NOT IN ('delivered','rejected')
             UNION SELECT COUNT(*) c FROM cloth_donations WHERE volunteer_email='$me' AND status NOT IN ('delivered','rejected')"
        )->fetch_assoc()['c'];
        $completed = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM food_donations WHERE volunteer_email='$me' AND status='delivered'"
        )->fetch_assoc()['c']
        + (int)$this->conn->query(
            "SELECT COUNT(*) c FROM cloth_donations WHERE volunteer_email='$me' AND status='delivered'"
        )->fetch_assoc()['c'];

        $completion_rate = 0;
        $total_assigned  = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM volunteer_tasks WHERE volunteer_email='$me'"
        )->fetch_assoc()['c'];
        $accepted = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM volunteer_tasks WHERE volunteer_email='$me' AND task_status='accepted'"
        )->fetch_assoc()['c'];
        if ($total_assigned > 0) $completion_rate = round($accepted / $total_assigned * 100);

        $overloaded   = $active >= 5;
        $impact_score = min(100, ($completed * 10) + ($completion_rate / 2));
        $level        = $completed < 3 ? 'Newcomer' : ($completed < 10 ? 'Rising Star' : ($completed < 25 ? 'Changemaker' : ($completed < 50 ? 'Impact Hero' : 'SoulServe Legend')));

        return [
            'active_tasks'    => $active,
            'completed'       => $completed,
            'completion_rate' => $completion_rate,
            'overloaded'      => $overloaded,
            'impact_score'    => (int)$impact_score,
            'level'           => $level,
            'level_emoji'     => $completed < 3 ? '🌱' : ($completed < 10 ? '⭐' : ($completed < 25 ? '🔥' : ($completed < 50 ? '🏆' : '👑'))),
            'advice'          => $overloaded
                ? "You currently have $active active tasks. Consider pausing new assignments until you complete a few."
                : "Your queue has capacity. You can take on more pickups if available in your area.",
        ];
    }

    /* ── H. SELLER DEMAND FORECAST ──────────────────────────────
     * Predicts which product categories will have high demand next week.
     */
    public function sellerDemandForecast(string $seller_email): array {
        $me   = mysqli_real_escape_string($this->conn, $seller_email);
        $month = (int)date('n');

        // Sales trend for this seller's categories
        $cats_q = $this->conn->query(
            "SELECT p.category, COUNT(oi.id) sales, SUM(oi.quantity) qty
             FROM order_items oi
             JOIN products p ON p.id=oi.product_id
             JOIN orders o ON o.id=oi.order_id
             WHERE p.seller_email='$me'
             AND o.created_at > NOW() - INTERVAL 30 DAY
             GROUP BY p.category ORDER BY sales DESC"
        );
        $cat_sales = $cats_q ? $cats_q->fetch_all(MYSQLI_ASSOC) : [];

        // Platform-wide trending categories (last 7 days)
        $trending_q = $this->conn->query(
            "SELECT p.category, COUNT(oi.id) c FROM order_items oi
             JOIN products p ON p.id=oi.product_id
             JOIN orders o ON o.id=oi.order_id
             WHERE o.created_at > NOW() - INTERVAL 7 DAY
             GROUP BY p.category ORDER BY c DESC LIMIT 3"
        );
        $trending = $trending_q ? array_column($trending_q->fetch_all(MYSQLI_ASSOC), 'category') : [];

        // Seasonal boosts
        $seasonal = match(true) {
            ($month >= 10 && $month <= 12) => ['textile','handicraft'],
            ($month >= 1  && $month <= 2)  => ['textile'],
            ($month >= 3  && $month <= 5)  => ['organic','food_product'],
            default                        => ['handicraft','art'],
        };

        // Build forecast
        $forecast = [];
        $all_cats = ['handicraft','textile','food_product','jewelry','art','pottery','organic','other'];
        foreach ($all_cats as $cat) {
            $base_score  = 20;
            $my_sales    = array_sum(array_column(array_filter($cat_sales, fn($r)=>$r['category']===$cat),'sales'));
            $in_trending = in_array($cat, $trending);
            $in_seasonal = in_array($cat, $seasonal);

            if ($my_sales > 0)   $base_score += min(30, $my_sales * 5);
            if ($in_trending)    $base_score += 30;
            if ($in_seasonal)    $base_score += 20;

            $forecast[] = [
                'category'    => $cat,
                'score'       => min(100, $base_score),
                'trending'    => $in_trending,
                'seasonal'    => $in_seasonal,
                'my_sales_30d'=> $my_sales,
                'advice'      => $in_trending ? "🔥 Trending on platform — stock up!" :
                                ($in_seasonal ? "📅 Seasonal demand high — good timing to list." :
                                ($my_sales > 0 ? "📈 You have sales history here — keep stocked." : "💡 Low competition — good category to enter.")),
            ];
        }
        usort($forecast, fn($a,$b) => $b['score'] - $a['score']);
        return array_slice($forecast, 0, 5);
    }

    /* ── I. SMART PRICING SUGGESTION ────────────────────────────
     * Suggests optimal pricing based on demand, competition, and stock.
     */
    public function suggestPricing(int $product_id): array {
        $p = $this->conn->query("SELECT * FROM products WHERE id=$product_id")->fetch_assoc();
        if (!$p) return ['suggested_price'=>0,'confidence'=>0,'msg'=>'Product not found'];

        $cat  = $p['category'];
        $curr = (float)$p['price'];
        $mrp  = (float)$p['mrp'];
        $sold = (int)$p['total_sold'];
        $stock= (int)$p['stock'];
        $rating=(float)$p['avg_rating'];

        // Avg price in same category
        $avg_price = (float)($this->conn->query(
            "SELECT COALESCE(AVG(price),0) avg FROM products WHERE category='$cat' AND is_active=1 AND id!=$product_id"
        )->fetch_assoc()['avg'] ?? $curr);

        $suggested = $avg_price;

        // High rating → allow slight premium
        if ($rating >= 4.5) $suggested *= 1.10;
        elseif ($rating < 3.0 && $rating > 0) $suggested *= 0.92;

        // Fast moving → slight increase
        if ($sold > 20) $suggested *= 1.05;

        // Low stock → premium
        if ($stock <= 3 && $sold > 5) $suggested *= 1.08;

        // Never go below 85% of MRP if MRP set
        if ($mrp > 0) $suggested = max($suggested, $mrp * 0.85);
        $suggested = round($suggested, 0);

        $diff   = $suggested - $curr;
        $action = abs($diff) < 5 ? 'Keep current price' :
                 ($diff > 0 ? "Increase by ₹$diff (demand supports it)" : "Reduce by ₹".abs($diff)." (improve competitiveness)");

        return [
            'current_price'   => $curr,
            'suggested_price' => $suggested,
            'market_avg'      => round($avg_price, 0),
            'confidence'      => $sold > 10 ? 82 : 60,
            'action'          => $action,
            'msg'             => "Market average for $cat: ₹".round($avg_price,0).". ".($diff>5?"You can price higher given your rating/sales.":($diff<-5?"Consider reducing to stay competitive.":"Your pricing is well-aligned.")),
        ];
    }

    /* ── J. REVIEW SENTIMENT ANALYSIS ───────────────────────────
     * Classifies product reviews as positive/negative/neutral.
     */
    public function analyzeReviewSentiment(int $product_id): array {
        $reviews = $this->conn->query(
            "SELECT rating, review_text FROM product_reviews WHERE product_id=$product_id ORDER BY created_at DESC LIMIT 50"
        );
        if (!$reviews || $reviews->num_rows === 0) {
            return ['sentiment'=>'no_reviews','positive'=>0,'negative'=>0,'neutral'=>0,'score'=>0,'summary'=>'No reviews yet.'];
        }

        $positive = $negative = $neutral = 0;
        $pos_words = ['good','great','excellent','love','perfect','amazing','best','happy','recommend','quality','fast','nice','beautiful','worth','super'];
        $neg_words = ['bad','poor','terrible','worst','hate','slow','broken','damaged','wrong','fake','cheap','disappointed','waste','late','ugly'];

        foreach ($reviews->fetch_all(MYSQLI_ASSOC) as $r) {
            $text  = strtolower($r['review_text'] ?? '');
            $stars = (int)$r['rating'];

            // Stars-based
            if ($stars >= 4) $positive++;
            elseif ($stars <= 2) $negative++;
            else $neutral++;

            // Text boost
            if ($text) {
                $pos_hits = count(array_filter($pos_words, fn($w)=>strpos($text,$w)!==false));
                $neg_hits = count(array_filter($neg_words, fn($w)=>strpos($text,$w)!==false));
                if ($pos_hits > $neg_hits) $positive++;
                elseif ($neg_hits > $pos_hits) $negative++;
            }
        }
        $total    = $positive + $negative + $neutral;
        $score    = $total > 0 ? round(($positive / $total) * 100) : 0;
        $sentiment= $score >= 70 ? 'positive' : ($score <= 30 ? 'negative' : 'mixed');

        return [
            'sentiment' => $sentiment,
            'positive'  => $positive,
            'negative'  => $negative,
            'neutral'   => $neutral,
            'score'     => $score,
            'summary'   => $score >= 70 ? "Customers love this product! Keep the quality up." :
                          ($score <= 30 ? "Negative feedback is high. Consider reviewing quality or description." :
                          "Mixed reviews. Address negative feedback to improve rating."),
        ];
    }

    /* ── K. FRAUD ORDER DETECTION ───────────────────────────────
     * Flags potentially fraudulent shop orders.
     */
    public function detectOrderFraud(int $order_id): array {
        $o = $this->conn->query("SELECT * FROM orders WHERE id=$order_id")->fetch_assoc();
        if (!$o) return ['risk'=>'unknown','flags'=>[]];

        $flags = [];
        $score = 0;
        $buyer = mysqli_real_escape_string($this->conn, $o['buyer_email']);

        // Multiple orders same address in 1 hour (return fraud)
        $same_addr = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM orders WHERE shipping_phone='{$o['shipping_phone']}' AND created_at > NOW()-INTERVAL 1 HOUR"
        )->fetch_assoc()['c'];
        if ($same_addr > 2) { $flags[] = "Multiple orders same phone in 1 hour"; $score += 30; }

        // High value COD
        if ($o['payment_method'] === 'cod' && $o['total_amount'] > 2000) {
            $flags[] = "High-value COD order (₹".number_format($o['total_amount'],0).")";
            $score += 20;
        }

        // New account high value order
        $account_age = (int)$this->conn->query(
            "SELECT DATEDIFF(NOW(),created_at) age FROM register WHERE email='$buyer'"
        )->fetch_assoc()['age'] ?? 999;
        if ($account_age < 1 && $o['total_amount'] > 1000) { $flags[] = "New account, high order value"; $score += 35; }

        // Many returns history
        $returns = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM return_requests WHERE buyer_email='$buyer' AND status='completed'"
        )->fetch_assoc()['c'] ?? 0;
        if ($returns > 3) { $flags[] = "$returns past returns — high return rate"; $score += 25; }

        $risk = $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low');
        return ['risk'=>$risk,'score'=>min(100,$score),'flags'=>$flags,'order_id'=>$order_id];
    }

    /* ── L. GENERATE PRODUCT DESCRIPTION ────────────────────────
     * Rule-based product description generator from basic attributes.
     */
    public function generateProductDescription(string $name, string $category, float $price, array $attrs = []): string {
        $cat_intros = [
            'handicraft'   => "This beautiful handcrafted piece is made by skilled artisans",
            'textile'      => "This exquisite textile is woven with care by rural weavers",
            'food_product' => "This delicious artisanal food product is made fresh",
            'jewelry'      => "This stunning piece of jewelry is carefully handmade",
            'art'          => "This unique artwork is created by a talented local artist",
            'pottery'      => "This elegant pottery piece is hand-thrown and kiln-fired",
            'organic'      => "This 100% organic product is grown without chemicals",
            'other'        => "This quality product is made with care",
        ];
        $intro = $cat_intros[$category] ?? $cat_intros['other'];

        $parts = ["$intro by rural artisans in Maharashtra, India."];

        if (!empty($attrs['material']))  $parts[] = "Made from premium {$attrs['material']},";
        if (!empty($attrs['size']))      $parts[] = "available in size {$attrs['size']}.";
        if (!empty($attrs['color']))     $parts[] = "The {$attrs['color']} finish gives it a timeless look.";

        $parts[] = "Every purchase directly supports the livelihoods of rural families and preserves traditional craftsmanship.";
        $parts[] = "Priced at just ₹" . number_format($price, 0) . ", this makes for a wonderful gift or personal purchase.";
        $parts[] = "100% authentic. Ships within 3–5 days.";

        return implode(' ', $parts);
    }

    /* ── M. AI ROUTE SUGGESTION ─────────────────────────────────
     * Groups nearby pickups for efficient routing.
     */
    public function suggestPickupRoute(string $volunteer_email): array {
        $me = mysqli_real_escape_string($this->conn, $volunteer_email);

        $food_tasks  = $this->conn->query(
            "SELECT id,'food' AS type, pickup_address, priority, quantity FROM food_donations
             WHERE volunteer_email='$me' AND status NOT IN ('delivered','rejected','picked_up')"
        )->fetch_all(MYSQLI_ASSOC);
        $cloth_tasks = $this->conn->query(
            "SELECT id,'cloth' AS type, pickup_address, priority, quantity FROM cloth_donations
             WHERE volunteer_email='$me' AND status NOT IN ('delivered','rejected','picked_up')"
        )->fetch_all(MYSQLI_ASSOC);

        $tasks = array_merge($food_tasks, $cloth_tasks);
        if (empty($tasks)) return ['route'=>[],'tip'=>'No active tasks for routing.'];

        // Sort: high priority first, then food (perishable), then by address similarity
        usort($tasks, function($a, $b) {
            $pri = ['high'=>3,'medium'=>2,'low'=>1];
            $ap  = $pri[$a['priority']??'low'] ?? 1;
            $bp  = $pri[$b['priority']??'low'] ?? 1;
            if ($ap !== $bp) return $bp - $ap;
            if ($a['type'] !== $b['type']) return $a['type'] === 'food' ? -1 : 1;
            return 0;
        });

        $route = [];
        foreach ($tasks as $i => $t) {
            $route[] = [
                'stop'    => $i + 1,
                'id'      => $t['id'],
                'type'    => $t['type'],
                'address' => $t['pickup_address'],
                'priority'=> $t['priority'] ?? 'medium',
                'qty'     => $t['quantity'],
                'reason'  => ($t['priority']==='high') ? '🔴 High priority — go first!' :
                            ($t['type']==='food' ? '🍱 Perishable — pick up early' : '👕 Clothing — flexible timing'),
            ];
        }

        $tip = count($route) > 1
            ? "Optimised route: start with high-priority/food donations, then clothing. Estimated total time: " . (count($route) * 20) . " min."
            : "1 active task. Head directly to the pickup address.";

        return ['route'=>$route, 'tip'=>$tip, 'total_stops'=>count($route)];
    }

    /* ── N. DONOR FRAUD ALERTS ──────────────────────────────────
     * Returns recent flagged/suspicious activity for a donor.
     */
    public function getDonorAlerts(string $donor_email): array {
        $fraud = $this->detectDonationFraud($donor_email, 'food', '', 0);
        $alerts = [];
        if ($fraud['risk'] !== 'low') {
            foreach ($fraud['flags'] as $f) {
                $alerts[] = ['level'=>$fraud['risk'],'icon'=>'⚠️','msg'=>$f['msg']];
            }
        }
        // Check any rejected donations
        $me  = mysqli_real_escape_string($this->conn, $donor_email);
        $rej = (int)$this->conn->query(
            "SELECT COUNT(*) c FROM food_donations WHERE donor_email='$me' AND status='rejected'"
        )->fetch_assoc()['c']
        + (int)$this->conn->query(
            "SELECT COUNT(*) c FROM cloth_donations WHERE donor_email='$me' AND status='rejected'"
        )->fetch_assoc()['c'];
        if ($rej > 0) {
            $alerts[] = ['level'=>'warn','icon'=>'❌','msg'=>"$rej donation(s) were rejected. Please ensure photos are clear and items meet quality guidelines."];
        }
        return $alerts;
    }

    /* ── LOG AI DECISION ─────────────────────────────────
     */
    public function log(string $action, $input, $output, float $confidence = 0, string $by = 'system'): void {
        try {
            $s = $this->conn->prepare("INSERT INTO ai_logs (action_type,input_data,output_data,confidence,triggered_by) VALUES (?,?,?,?,?)");
            $in  = json_encode($input,  JSON_UNESCAPED_UNICODE);
            $out = json_encode($output, JSON_UNESCAPED_UNICODE);
            $s->bind_param("sssds", $action, $in, $out, $confidence, $by);
            $s->execute();
        } catch (Exception $e) { /* silent — logging should never break the app */ }
    }
}

/* ── Expose as singleton ── */
function adhaar_ai(): AdhaarAI {
    global $conn, $_adhaar_ai;
    if (!isset($_adhaar_ai)) $_adhaar_ai = new AdhaarAI($conn);
    return $_adhaar_ai;
}

/**
 * Cache AI results in session to avoid repeat DB queries.
 * TTL default: 300 seconds (5 minutes).
 * Usage: $result = ai_cached("donor_sug_$email", 300, fn()=> $ai->getDonorSuggestions($email));
 */
function ai_cached(string $key, int $ttl, callable $fn): mixed {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sk = '_ai_cache_' . md5($key);
    if (isset($_SESSION[$sk]) &&
        is_array($_SESSION[$sk]) &&
        isset($_SESSION[$sk]['ts']) &&
        (time() - $_SESSION[$sk]['ts']) < $ttl) {
        return $_SESSION[$sk]['data'];
    }
    $data = $fn();
    $_SESSION[$sk] = ['data' => $data, 'ts' => time()];
    return $data;
}

/**
 * Invalidate all AI cache entries (call after new donation, order, etc.)
 */
function ai_cache_clear(): void {
    if (session_status() === PHP_SESSION_NONE) return;
    foreach (array_keys($_SESSION) as $k) {
        if (str_starts_with($k, '_ai_cache_')) unset($_SESSION[$k]);
    }
}
