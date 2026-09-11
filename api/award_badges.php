<?php
/**
 * SoulServe Badge Engine
 * award_badges($conn, $email) — call after every donation delivered. Idempotent.
 * get_donor_badges($conn, $email) — returns all badges for a user.
 */

function _ensure_badges_table($conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS donor_badges (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        donor_email VARCHAR(180) NOT NULL,
        badge_key   VARCHAR(60)  NOT NULL,
        badge_name  VARCHAR(100) NOT NULL,
        badge_emoji VARCHAR(8)   NOT NULL DEFAULT '🏅',
        badge_desc  VARCHAR(255),
        earned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_donor_badge (donor_email, badge_key)
    ) ENGINE=InnoDB");
}

function award_badges($conn, string $email): array {
    _ensure_badges_table($conn);
    $e = mysqli_real_escape_string($conn, $email);

    // ── Donation counts ──
    $food  = (int)($conn->query("SELECT COUNT(*) c FROM food_donations  WHERE donor_email='$e' AND status='delivered'")->fetch_assoc()['c'] ?? 0);
    $cloth = (int)($conn->query("SELECT COUNT(*) c FROM cloth_donations WHERE donor_email='$e' AND status='delivered'")->fetch_assoc()['c'] ?? 0);
    $other = 0;
    try {
        $other = (int)($conn->query("SELECT COUNT(*) c FROM donations WHERE donor_email='$e' AND status='delivered'")->fetch_assoc()['c'] ?? 0);
    } catch(Throwable $ex){}
    $total = $food + $cloth + $other;
    $food_qty = (int)($conn->query("SELECT COALESCE(SUM(quantity),0) c FROM food_donations WHERE donor_email='$e' AND status='delivered'")->fetch_assoc()['c'] ?? 0);

    // ── Badge definitions [key, name, emoji, desc, condition] ──
    $definitions = [
        ['first_drop',     'First Drop',          '🌱', 'Made your very first donation!',               $total >= 1],
        ['food_hero',      'Food Hero',            '🍱', 'Donated food 3+ times.',                       $food >= 3],
        ['cloth_champion', 'Cloth Champion',       '👕', 'Donated clothing 3+ times.',                   $cloth >= 3],
        ['double_threat',  'Double Threat',        '⚡', 'Donated both food AND clothing.',               $food >= 1 && $cloth >= 1],
        ['ten_strong',     '10 Strong',            '💪', 'Completed 10 donations!',                      $total >= 10],
        ['fifty_meals',    '50 Meals',             '🍽️', 'Shared 50+ meal servings.',                    $food_qty >= 50],
        ['hundred_club',   'Hundred Club',         '💯', '100+ servings donated.',                       $food_qty >= 100],
        ['impact_maker',   'Impact Maker',         '🌟', '25 total donations.',                          $total >= 25],
        ['food_lord',      'Food Lord',            '👑', 'Donated food 20+ times.',                      $food >= 20],
        ['legend',         'SoulServe Legend',     '🏆', '50 total donations — infinite impact!',        $total >= 50],
        ['consistent',     'Consistent Giver',     '🔄', 'Donated in 3 consecutive months.',             false], // computed below
        ['generous',       'Generous Soul',        '💝', 'Single donation of 100+ servings.',            $food_qty >= 100],
        ['community_star', 'Community Star',       '🌠', '15+ donations across categories.',             $total >= 15],
    ];

    // Check 3 consecutive months
    try {
        $months_q = $conn->query(
            "SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') m
             FROM (SELECT created_at FROM food_donations WHERE donor_email='$e'
                   UNION ALL SELECT created_at FROM cloth_donations WHERE donor_email='$e'
                   UNION ALL SELECT created_at FROM donations WHERE donor_email='$e') x
             ORDER BY m DESC LIMIT 3"
        );
        $months = $months_q ? $months_q->fetch_all(MYSQLI_ASSOC) : [];
        if (count($months) === 3) {
            $m1 = new DateTime($months[0]['m'].'-01');
            $m2 = new DateTime($months[1]['m'].'-01');
            $m3 = new DateTime($months[2]['m'].'-01');
            $consecutive = ($m1->diff($m2)->days <= 31) && ($m2->diff($m3)->days <= 31);
            foreach ($definitions as &$def) {
                if ($def[0] === 'consistent') { $def[4] = $consecutive; }
            }
            unset($def);
        }
    } catch (Throwable $e2) {}

    // ── Insert newly earned badges (INSERT IGNORE = idempotent) ──
    $newly_earned = [];
    $ins = $conn->prepare(
        "INSERT IGNORE INTO donor_badges (donor_email,badge_key,badge_name,badge_emoji,badge_desc) VALUES (?,?,?,?,?)"
    );
    foreach ($definitions as [$key, $name, $emoji, $desc, $cond]) {
        if ($cond) {
            $ins->bind_param("sssss", $email, $key, $name, $emoji, $desc);
            if ($ins->execute() && $ins->affected_rows > 0) {
                $newly_earned[] = ['key'=>$key,'name'=>$name,'emoji'=>$emoji,'desc'=>$desc];
            }
        }
    }
    return $newly_earned;
}

function get_donor_badges($conn, string $email): array {
    _ensure_badges_table($conn);
    try {
        $s = $conn->prepare("SELECT * FROM donor_badges WHERE donor_email=? ORDER BY earned_at DESC");
        $s->bind_param("s", $email);
        $s->execute();
        return $s->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function get_badge_count($conn, string $email): int {
    _ensure_badges_table($conn);
    try {
        $s = $conn->prepare("SELECT COUNT(*) c FROM donor_badges WHERE donor_email=?");
        $s->bind_param("s", $email);
        $s->execute();
        return (int)$s->get_result()->fetch_assoc()['c'];
    } catch (Throwable $e) {
        return 0;
    }
}
