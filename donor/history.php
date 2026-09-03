<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
$email  = $_SESSION['user_email'];
$filter = $_GET['filter'] ?? 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 10;

$type_w=$status_w='';
if($filter==='food')          $type_w  = "AND type='Food'";
elseif($filter==='cloth')     $type_w  = "AND type='Clothes'";
elseif($filter==='pending')   $status_w= "AND status='pending'";
elseif($filter==='delivered') $status_w= "AND status='delivered'";

$sql="SELECT * FROM ((SELECT COALESCE(donation_id,CONCAT('DON-FOOD-',LPAD(id,6,'0'))) AS don_id,'Food' AS type,status,quantity,pickup_address,created_at FROM food_donations WHERE donor_email=?) UNION ALL (SELECT COALESCE(donation_id,CONCAT('DON-CLO-',LPAD(id,6,'0'))) AS don_id,'Clothes' AS type,status,quantity,pickup_address,created_at FROM cloth_donations WHERE donor_email=?)) combined WHERE 1=1 $type_w $status_w ORDER BY created_at DESC";
$h=$conn->prepare($sql);$h->bind_param("ss",$email,$email);$h->execute();
$all=$h->get_result()->fetch_all(MYSQLI_ASSOC);
$total_rows=count($all);
$total_pages=max(1,(int)ceil($total_rows/$per));
$page=min($page,$total_pages);
$rows=array_slice($all,($page-1)*$per,$per);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Donation History | Adhaar</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#7a7d3f;--accent2:#9a8f5c;--bg:#f6f5f0;--card:#fff;--text:#2f2e26;--muted:#5a594d;--shadow:0 8px 32px rgba(47,46,38,.09);--radius:18px}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif}
body{background:var(--bg);color:var(--text);min-height:100vh}
/* Top header */
.top-header{position:sticky;top:0;background:rgba(255,255,255,.96);backdrop-filter:blur(14px);z-index:100;box-shadow:0 2px 16px rgba(0,0,0,.07)}
.top-header-inner{max-width:920px;margin:auto;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:var(--accent);font-weight:700;font-size:14px;text-decoration:none;padding:7px 14px;border-radius:8px;border:1.5px solid rgba(122,125,63,.3);transition:.2s}
.back-btn:hover{background:rgba(122,125,63,.08)}
.page-logo{font-size:16px;font-weight:800;color:var(--accent)}
.page{padding:28px 20px 60px;max-width:920px;margin:0 auto}
.page-title{font-size:22px;font-weight:800;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--muted);margin-bottom:22px}
/* Filter bar */
.filter-wrap{position:sticky;top:64px;z-index:90;background:var(--bg);padding-bottom:12px}
.filter-bar{background:var(--card);border-radius:14px;padding:14px 18px;box-shadow:var(--shadow);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.filter-btn{padding:8px 16px;border-radius:10px;border:1.5px solid #e0ddd5;background:#fafaf6;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;transition:.22s;white-space:nowrap}
.filter-btn:hover{border-color:var(--accent);color:var(--accent)}
.filter-btn.active{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-color:transparent;box-shadow:0 4px 14px rgba(122,125,63,.3)}
.result-count{margin-left:auto;font-size:12px;color:var(--muted);font-weight:600;white-space:nowrap}
/* Table */
.table-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:460px}
thead tr{background:#f6f5f0}
th{padding:13px 16px;text-align:left;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #ede9df}
td{padding:13px 16px;border-bottom:1px solid #f0ede5;vertical-align:middle;font-size:13px}
tr:last-child td{border-bottom:none}
tbody tr{transition:.2s;animation:rowIn .3s ease forwards;opacity:0}
tbody tr:hover td{background:#fafaf6}
tbody tr:nth-child(1){animation-delay:.04s}tbody tr:nth-child(2){animation-delay:.08s}
tbody tr:nth-child(3){animation-delay:.12s}tbody tr:nth-child(n+4){animation-delay:.15s}
@keyframes rowIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.type-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
.type-food{background:#fef3c7;color:#92400e}.type-cloth{background:#dbeafe;color:#1e40af}
.pill{display:inline-block;padding:4px 11px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.pill.pending{background:#fef3c7;color:#92400e}.pill.accepted{background:#dbeafe;color:#1e40af}
.pill.scheduled{background:#ede9fe;color:#5b21b6}.pill.out_for_pickup{background:#fce7f3;color:#9d174d}
.pill.picked_up,.pill.delivered{background:#d1fae5;color:#065f46}.pill.rejected{background:#fee2e2;color:#991b1b}
.empty{text-align:center;padding:52px 24px;color:var(--muted);font-size:14px}
.empty .emoji{font-size:42px;margin-bottom:14px;display:block}
/* Pagination */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:20px 0 4px;flex-wrap:wrap}
.page-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:8px 16px;border-radius:9px;border:1.5px solid #e0ddd5;background:#fff;color:var(--muted);font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;transition:.22s;font-family:inherit}
.page-btn:hover{border-color:var(--accent);color:var(--accent)}
.page-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.page-btn.disabled{opacity:.4;cursor:not-allowed;pointer-events:none}
/* Responsive */
@media(max-width:640px){th:nth-child(3),td:nth-child(3){display:none}.filter-bar{gap:6px}.filter-btn{padding:7px 12px;font-size:12px}}
</style>
</head>
<body>
<div class="top-header">
  <div class="top-header-inner">
    <a href="donor_dashboard.php" class="back-btn">← Back</a>
    <span class="page-logo"><img src="../assets/logo.png" alt="SoulServe" style="height:28px;object-fit:contain;vertical-align:middle;margin-right:6px">Donation History</span>
    <a href="donate.php" style="padding:8px 16px;border-radius:9px;background:var(--accent);color:#fff;font-size:13px;font-weight:700;text-decoration:none">+ Donate</a>
  </div>
</div>

<div class="page">
  <div class="page-title">Donation History</div>
  <div class="page-sub">All your food and clothing donations in one place.</div>

  <div class="filter-wrap">
    <div class="filter-bar">
      <?php
header('Content-Type: text/html; charset=utf-8');
      $filters=[['all','All'],['food','🍱 Food'],['cloth','👕 Clothes'],['pending','⏳ Pending'],['delivered','✅ Delivered']];
      foreach($filters as [$v,$l]):?>
      <a href="history.php?filter=<?=$v?>&page=1" class="filter-btn <?=$filter===$v?'active':''?>"><?=$l?></a>
      <?php endforeach; ?>
      <span class="result-count"><?=$total_rows?> result<?=$total_rows!=1?'s':''?></span>
    </div>
  </div>

  <div class="table-card">
    <?php if(empty($rows)): ?>
      <div class="empty"><span class="emoji">📭</span><p>No donations found for this filter.</p></div>
    <?php else: ?>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Donation ID</th><th>Type</th><th>Quantity</th><th>Pickup Address</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><span style="font-size:11px;font-weight:700;background:rgba(122,125,63,.1);color:#5a7a2e;padding:3px 10px;border-radius:20px;font-family:monospace"><?=htmlspecialchars($r['don_id']??'—')?></span></td>
          <td><span class="type-badge <?=$r['type']==='Food'?'type-food':'type-cloth'?>"><?=$r['type']==='Food'?'🍱 Food':'👕 Clothes'?></span></td>
          <td><?=htmlspecialchars($r['quantity']??'—')?></td>
          <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($r['pickup_address']??'—')?></td>
          <td style="white-space:nowrap"><?=date("d M Y",strtotime($r['created_at']))?></td>
          <td><span class="pill <?=htmlspecialchars($r['status'])?>"><?=ucfirst(str_replace('_',' ',$r['status']))?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if($total_pages>1): ?>
  <div class="pagination">
    <?php if($page>1): ?>
      <a href="?filter=<?=$filter?>&page=<?=$page-1?>" class="page-btn">← Prev</a>
    <?php else: ?>
      <span class="page-btn disabled">← Prev</span>
    <?php endif; ?>

    <?php
header('Content-Type: text/html; charset=utf-8');
    $start=max(1,$page-2); $end=min($total_pages,$page+2);
    if($start>1): ?><a href="?filter=<?=$filter?>&page=1" class="page-btn">1</a><?php if($start>2): ?><span class="page-btn disabled">…</span><?php endif; endif;
    for($i=$start;$i<=$end;$i++): ?>
      <a href="?filter=<?=$filter?>&page=<?=$i?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a>
    <?php endfor;
    if($end<$total_pages): if($end<$total_pages-1): ?><span class="page-btn disabled">…</span><?php endif; ?><a href="?filter=<?=$filter?>&page=<?=$total_pages?>" class="page-btn"><?=$total_pages?></a><?php endif; ?>

    <?php if($page<$total_pages): ?>
      <a href="?filter=<?=$filter?>&page=<?=$page+1?>" class="page-btn">Next →</a>
    <?php else: ?>
      <span class="page-btn disabled">Next →</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
