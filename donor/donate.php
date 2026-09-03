<?php
header('Content-Type: text/html; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
if (!isset($_SESSION['user_email'])) { header("Location: ../auth/login.php"); exit; }
$email = $_SESSION['user_email'];
$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Donate | SoulServe</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--bg:#f5f8f4;--accent:#006D77;--accent2:#2E8B57;--text:#102A43;--muted:#5A7184;--card:#fff;--shadow:0 20px 60px rgba(16,42,67,.12);--radius:22px;--border:#E2EBE9}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;-webkit-tap-highlight-color:transparent}
body{background:linear-gradient(180deg,#f5f8f4,#edf4f1 50%,#f8f3ee);color:var(--text);min-height:100vh}
/* ── Header ── */
header{position:sticky;top:0;background:rgba(255,255,255,.95);backdrop-filter:blur(14px);box-shadow:0 2px 18px rgba(16,42,67,.07);z-index:200}
.nav{max-width:900px;margin:auto;padding:0 20px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.logo img{height:32px;object-fit:contain}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:var(--accent);font-weight:700;font-size:13px;text-decoration:none;padding:7px 14px;border-radius:8px;border:1.5px solid rgba(0,109,119,.3);transition:.2s}
.back-btn:hover{background:rgba(0,109,119,.07)}
.track-link{font-size:13px;font-weight:700;color:var(--muted);text-decoration:none;padding:7px 14px;border-radius:8px;transition:.2s}
.track-link:hover{color:var(--accent);background:rgba(0,109,119,.06)}
/* ── Page ── */
.page{max-width:720px;margin:0 auto;padding:32px 20px 80px}
/* ── Banners ── */
.banner{padding:14px 20px;border-radius:14px;font-size:13px;font-weight:600;margin-bottom:24px;display:flex;align-items:center;gap:10px}
.banner-success{background:#d1fae5;color:#065f46;border:1.5px solid #6ee7b7}
.banner-error{background:#fee2e2;color:#991b1b;border:1.5px solid #fca5a5}
/* ── Step indicator ── */
.steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:36px}
.step{display:flex;flex-direction:column;align-items:center;font-size:11px;font-weight:600;color:var(--muted);flex:1;text-align:center;gap:6px}
.step-dot{width:28px;height:28px;border-radius:50%;background:#e2ebe9;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;transition:.3s}
.step.active .step-dot{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;box-shadow:0 4px 14px rgba(0,109,119,.3)}
.step.active{color:var(--accent)}
.step.done .step-dot{background:#d1fae5;color:#065f46}
.step.done{color:#065f46}
.step-line{flex:1;height:2px;background:#e2ebe9;margin-bottom:20px;transition:.3s}
.step-line.done{background:linear-gradient(90deg,var(--accent),var(--accent2))}
/* ── Cards ── */
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:36px 36px;margin-bottom:24px}
.card-title{font-size:20px;font-weight:800;margin-bottom:6px}
.card-sub{font-size:13px;color:var(--muted);margin-bottom:28px;line-height:1.6}
/* ── Category grid ── */
.cat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:8px}
.cat-btn{background:#fafaf6;border:2px solid var(--border);border-radius:16px;padding:20px 12px;text-align:center;cursor:pointer;transition:.25s;display:flex;flex-direction:column;align-items:center;gap:8px}
.cat-btn:hover{border-color:var(--accent);background:#f0f9f8;transform:translateY(-2px)}
.cat-btn.selected{border-color:var(--accent);background:linear-gradient(135deg,rgba(0,109,119,.08),rgba(46,139,87,.06));box-shadow:0 4px 16px rgba(0,109,119,.12)}
.cat-btn .cat-icon{font-size:28px;line-height:1}
.cat-btn .cat-name{font-size:12px;font-weight:700;color:var(--text)}
.cat-btn .cat-desc{font-size:10px;color:var(--muted);line-height:1.4}
/* ── Form fields ── */
.field{margin-bottom:18px}
.field label{display:block;font-size:11px;font-weight:700;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.6px}
.field input,.field select,.field textarea{width:100%;padding:12px 16px;border:2px solid var(--border);border-radius:12px;font-size:14px;color:var(--text);background:#fafaf6;transition:.25s;outline:none;font-family:'Inter',sans-serif}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(0,109,119,.1)}
.field textarea{resize:vertical;min-height:80px}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.checkbox-row{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--muted);margin-bottom:16px;padding:12px 16px;background:#fafaf6;border-radius:10px;border:1.5px solid var(--border)}
.checkbox-row input[type=checkbox]{width:18px;height:18px;accent-color:var(--accent);flex-shrink:0}
/* ── Impact msg ── */
.impact{background:linear-gradient(135deg,#f0f9f8,#f0fdf4);border:1.5px solid #a7f3d0;border-radius:12px;padding:13px 18px;font-size:13px;font-weight:600;color:#065f46;margin-bottom:20px;display:flex;align-items:center;gap:8px}
/* ── Buttons ── */
.btn-primary{width:100%;padding:15px;border:none;border-radius:50px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 10px 28px rgba(0,109,119,.22);transition:.3s}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 16px 38px rgba(46,139,87,.28)}
.btn-primary:disabled{opacity:.6;cursor:not-allowed;transform:none}
.btn-back{display:block;text-align:center;margin-top:14px;color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;background:none;border:none;width:100%;padding:8px;border-radius:8px;transition:.2s}
.btn-back:hover{color:var(--accent);background:rgba(0,109,119,.05)}
/* ── Guidelines ── */
.guidelines{background:#fffbeb;border:1.5px solid #fde68a;border-radius:14px;padding:18px 20px;margin-bottom:24px}
.guidelines h4{font-size:13px;font-weight:700;color:#92400e;margin-bottom:10px}
.guidelines ul{padding-left:18px;font-size:12px;color:#78350f;line-height:1.9}
/* ── Hidden ── */
.hidden{display:none!important}
/* ── Responsive ── */
@media(max-width:600px){
  .cat-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .card{padding:24px 18px}
  .field-row{grid-template-columns:1fr}
  .cat-btn{padding:16px 8px}
}
</style>
</head>
<body>
<header>
  <div class="nav">
    <a href="../index.html" class="logo"><img src="../assets/logo.png" alt="SoulServe"></a>
    <a href="donor_dashboard.php" class="back-btn">← Dashboard</a>
    <a href="track.php" class="track-link">📍 Track</a>
  </div>
</header>

<div class="page">

<?php if($success): ?>
<div class="banner banner-success">✅ Donation submitted successfully! We'll review it shortly. <a href="track.php" style="color:#065f46;font-weight:800;margin-left:auto">Track →</a></div>
<?php endif; ?>
<?php if($error==='fields'): ?>
<div class="banner banner-error">⚠️ Please fill all required fields and try again.</div>
<?php elseif($error==='upload'): ?>
<div class="banner banner-error">⚠️ Image upload failed. Please try a smaller image (max 8MB).</div>
<?php elseif($error==='server'): ?>
<div class="banner banner-error">⚠️ Server error. Please try again in a moment.</div>
<?php endif; ?>

<!-- Step indicator -->
<div class="steps" id="stepIndicator">
  <div class="step active" id="step1"><div class="step-dot">1</div><span>Category</span></div>
  <div class="step-line" id="line1"></div>
  <div class="step" id="step2"><div class="step-dot">2</div><span>Details</span></div>
  <div class="step-line" id="line2"></div>
  <div class="step" id="step3"><div class="step-dot">3</div><span>Pickup</span></div>
  <div class="step-line" id="line3"></div>
  <div class="step" id="step4"><div class="step-dot">4</div><span>Submit</span></div>
</div>

<!-- ══ STEP 1: Category Selector ══ -->
<div id="panelCategory" class="card">
  <div class="card-title">🎁 What would you like to donate?</div>
  <div class="card-sub">Select a category — the form will adapt to your choice.</div>
  <div class="cat-grid">
    <div class="cat-btn" onclick="selectCat('food',this)">
      <div class="cat-icon">🍱</div>
      <div class="cat-name">Food</div>
      <div class="cat-desc">Cooked food, groceries, packed meals</div>
    </div>
    <div class="cat-btn" onclick="selectCat('clothes',this)">
      <div class="cat-icon">👕</div>
      <div class="cat-name">Clothes</div>
      <div class="cat-desc">Wearable clothing of any type</div>
    </div>
    <div class="cat-btn" onclick="selectCat('study_material',this)">
      <div class="cat-icon">📚</div>
      <div class="cat-name">Study Material</div>
      <div class="cat-desc">Books, notebooks, stationery</div>
    </div>
    <div class="cat-btn" onclick="selectCat('school_supplies',this)">
      <div class="cat-icon">🎒</div>
      <div class="cat-name">School Supplies</div>
      <div class="cat-desc">Bags, uniforms, shoes, geometry boxes</div>
    </div>
    <div class="cat-btn" onclick="selectCat('toys',this)">
      <div class="cat-icon">🧸</div>
      <div class="cat-name">Toys & Games</div>
      <div class="cat-desc">Toys, board games, sports equipment</div>
    </div>
    <div class="cat-btn" onclick="selectCat('medicines',this)">
      <div class="cat-icon">💊</div>
      <div class="cat-name">Medicines</div>
      <div class="cat-desc">Sealed, unexpired medicines only</div>
    </div>
    <div class="cat-btn" onclick="selectCat('electronics',this)">
      <div class="cat-icon">📱</div>
      <div class="cat-name">Electronics</div>
      <div class="cat-desc">Phones, tablets, laptops, chargers</div>
    </div>
    <div class="cat-btn" onclick="selectCat('furniture',this)">
      <div class="cat-icon">🪑</div>
      <div class="cat-name">Furniture</div>
      <div class="cat-desc">Chairs, tables, study desks, shelves</div>
    </div>
    <div class="cat-btn" onclick="selectCat('other',this)">
      <div class="cat-icon">📦</div>
      <div class="cat-name">Other</div>
      <div class="cat-desc">Anything else you'd like to donate</div>
    </div>
  </div>
</div>

<!-- ══ STEP 2+3+4: Donation Form ══ -->
<div id="panelForm" class="hidden">
  <div class="guidelines" id="guidelineBox"></div>

  <div class="card">
    <div class="card-title" id="formTitle">Donation Details</div>
    <div class="card-sub" id="formSub"></div>

    <form id="donateForm" action="../api/donate.php" method="POST" enctype="multipart/form-data">
      <?=csrf_field()?>
      <input type="hidden" name="category" id="fieldCategory">

      <!-- ── COMMON TO ALL ── -->
      <div class="field">
        <label>Quantity / Amount *</label>
        <input type="text" name="quantity" id="fieldQty" placeholder="e.g. 5 items / 10 kg / 3 bags" required>
      </div>
      <div class="field">
        <label>Brief Description *</label>
        <textarea name="description" id="fieldDesc" placeholder="Describe what you're donating..." required></textarea>
      </div>

      <!-- ── FOOD FIELDS ── -->
      <div id="grpFood" class="hidden">
        <div class="field-row">
          <div class="field">
            <label>When was food prepared? *</label>
            <input type="datetime-local" name="food_time" id="fieldFoodTime">
          </div>
          <div class="field">
            <label>Safe to eat for *</label>
            <select name="safe_hours" id="fieldSafeHours">
              <option value="">Select duration</option>
              <option value="2">2 hours</option>
              <option value="4">4 hours</option>
              <option value="6">6 hours</option>
              <option value="8">8+ hours</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label>Urgency *</label>
          <select name="priority" id="fieldPriority">
            <option value="high">🔴 High — needs pickup today</option>
            <option value="medium" selected>🟡 Medium — within 24 hours</option>
            <option value="low">🟢 Low — flexible timing</option>
          </select>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" name="food_safe_confirm" id="foodSafeConfirm">
          <label for="foodSafeConfirm">I confirm the food is safe, hygienic and freshly prepared</label>
        </div>
      </div>

      <!-- ── CLOTHES & FOOTWEAR FIELDS ── -->
      <div id="grpClothes" class="hidden">

        <!-- Sub-category: Clothes vs Footwear -->
        <div class="field">
          <label>What are you donating? *</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px">
            <label style="display:flex;align-items:center;gap:10px;padding:12px 16px;border:2px solid var(--border);border-radius:12px;cursor:pointer;font-size:13px;font-weight:600;transition:.2s" id="lblClothes">
              <input type="radio" name="cloth_subcat" value="clothes" id="subCatClothes" style="accent-color:var(--accent)" onchange="toggleClothSubcat('clothes')" checked>
              👕 Clothes / Garments
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:12px 16px;border:2px solid var(--border);border-radius:12px;cursor:pointer;font-size:13px;font-weight:600;transition:.2s" id="lblFootwear">
              <input type="radio" name="cloth_subcat" value="footwear" id="subCatFootwear" style="accent-color:var(--accent)" onchange="toggleClothSubcat('footwear')">
              👟 Footwear / Shoes
            </label>
          </div>
        </div>

        <!-- ── CLOTHES specific ── -->
        <div id="subGrpClothes">
          <div class="field-row">
            <div class="field">
              <label>For Whom? *</label>
              <select name="cloth_for" id="fieldClothFor" onchange="updateClothSizes()">
                <option value="">Select</option>
                <option value="Men">Men</option>
                <option value="Women">Women</option>
                <option value="Boys">Boys (5–14 yrs)</option>
                <option value="Girls">Girls (5–14 yrs)</option>
                <option value="Toddler">Toddler (2–5 yrs)</option>
                <option value="Infant">Infant (0–2 yrs)</option>
                <option value="Mixed">Mixed / All</option>
              </select>
            </div>
            <div class="field">
              <label>Garment Type *</label>
              <select name="cloth_garment_type" id="fieldGarmentType">
                <option value="">Select type</option>
                <optgroup label="Tops">
                  <option value="T-Shirt">T-Shirt</option>
                  <option value="Shirt">Shirt / Formal Shirt</option>
                  <option value="Kurta">Kurta / Kurti</option>
                  <option value="Blouse">Blouse / Top</option>
                  <option value="Sweater">Sweater / Pullover</option>
                  <option value="Jacket">Jacket / Hoodie</option>
                  <option value="Saree Blouse">Saree Blouse</option>
                </optgroup>
                <optgroup label="Bottoms">
                  <option value="Pant">Pant / Trouser</option>
                  <option value="Jeans">Jeans</option>
                  <option value="Salwar">Salwar / Churidar</option>
                  <option value="Skirt">Skirt / Lehenga</option>
                  <option value="Shorts">Shorts</option>
                </optgroup>
                <optgroup label="Full Sets">
                  <option value="Saree">Saree</option>
                  <option value="Suit">Suit Set (Salwar Kameez)</option>
                  <option value="School Uniform">School Uniform</option>
                  <option value="Track Suit">Track Suit / Sportswear</option>
                  <option value="Night Dress">Night Dress / Pyjamas</option>
                  <option value="Dress / Frock">Dress / Frock</option>
                </optgroup>
                <optgroup label="Winter Wear">
                  <option value="Jacket / Coat">Jacket / Coat</option>
                  <option value="Blanket / Shawl">Blanket / Shawl</option>
                  <option value="Woolen Cap / Gloves">Woolen Cap / Gloves</option>
                </optgroup>
                <optgroup label="Others">
                  <option value="Undergarments">Undergarments (new only)</option>
                  <option value="Dupatta / Stole">Dupatta / Stole</option>
                  <option value="Mixed Lot">Mixed / Assorted Lot</option>
                </optgroup>
              </select>
            </div>
          </div>

          <!-- Sizes -->
          <div class="field" id="clothSizeField">
            <label>Size(s) Available *</label>
            <div id="clothSizeOptions" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
              <!-- Populated by JS based on whom -->
            </div>
            <input type="hidden" name="cloth_sizes" id="fieldClothSizes">
            <div style="font-size:11px;color:var(--muted);margin-top:6px">Select all sizes you have</div>
          </div>

          <div class="field-row">
            <div class="field">
              <label>Number of Pieces *</label>
              <input type="number" name="cloth_pieces" id="fieldClothPieces" min="1" max="500" placeholder="e.g. 5">
            </div>
            <div class="field">
              <label>Condition *</label>
              <select name="condition_type" id="fieldCondition">
                <option value="new">New / Brand New (tags on)</option>
                <option value="like_new">Like New (worn once/twice)</option>
                <option value="good" selected>Good (gently used)</option>
                <option value="fair">Fair (minor wear/fade)</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label>Color / Pattern (optional)</label>
            <input type="text" name="cloth_color" placeholder="e.g. Blue, White stripes, Floral print, Mixed colors">
          </div>
        </div>

        <!-- ── FOOTWEAR specific ── -->
        <div id="subGrpFootwear" class="hidden">
          <div class="field-row">
            <div class="field">
              <label>For Whom? *</label>
              <select name="footwear_for" id="fieldFootwearFor" onchange="updateFootwearSizes()">
                <option value="">Select</option>
                <option value="Men">Men</option>
                <option value="Women">Women</option>
                <option value="Boys">Boys</option>
                <option value="Girls">Girls</option>
                <option value="Children">Children (unisex)</option>
                <option value="Infant">Infant / Toddler</option>
                <option value="Mixed">Mixed</option>
              </select>
            </div>
            <div class="field">
              <label>Footwear Type *</label>
              <select name="footwear_type">
                <option value="">Select type</option>
                <option value="School Shoes">School Shoes</option>
                <option value="Sports Shoes">Sports / Running Shoes</option>
                <option value="Sandals">Sandals / Chappals</option>
                <option value="Formal Shoes">Formal Shoes</option>
                <option value="Slippers">Slippers / Flip-flops</option>
                <option value="Boots">Boots / Ankle Boots</option>
                <option value="Heels">Heels / Wedges</option>
                <option value="Mixed">Mixed / Assorted</option>
              </select>
            </div>
          </div>

          <!-- Shoe sizes -->
          <div class="field">
            <label>Shoe Size(s) *</label>
            <div id="footwearSizeOptions" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
              <!-- Populated by JS -->
            </div>
            <input type="hidden" name="footwear_sizes" id="fieldFootwearSizes">
            <div style="font-size:11px;color:var(--muted);margin-top:6px">Select all sizes you have</div>
          </div>

          <div class="field-row">
            <div class="field">
              <label>Number of Pairs *</label>
              <input type="number" name="footwear_pairs" min="1" max="100" placeholder="e.g. 2">
            </div>
            <div class="field">
              <label>Condition *</label>
              <select name="condition_type">
                <option value="new">New / Unused</option>
                <option value="like_new">Like New (worn very little)</option>
                <option value="good" selected>Good (lightly worn)</option>
                <option value="fair">Fair (some wear)</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Common to both clothes + footwear -->
        <div class="checkbox-row" style="margin-top:6px">
          <input type="checkbox" name="is_clean" id="isClean" value="1">
          <label for="isClean">I confirm all items are clean, washed and ready to wear</label>
        </div>
        <div class="field">
          <label>Packing Status</label>
          <select name="cloth_packed">
            <option value="loose">Loose / Not packed</option>
            <option value="bag">Packed in bags</option>
            <option value="box">Packed in boxes</option>
            <option value="bundled">Bundled with rubber bands</option>
          </select>
        </div>
      </div>

      <!-- ── STUDY MATERIAL FIELDS ── -->
      <div id="grpStudy" class="hidden">
        <div class="field-row">
          <div class="field">
            <label>Subject / Grade Level</label>
            <input type="text" name="subject_grade" id="fieldSubjectGrade" placeholder="e.g. Class 5-8 / Science / Hindi">
          </div>
          <div class="field">
            <label>Number of Books/Items</label>
            <input type="number" name="book_count" id="fieldBookCount" min="1" placeholder="e.g. 10">
          </div>
        </div>
        <div class="field">
          <label>Condition *</label>
          <select name="condition_type">
            <option value="new">New</option>
            <option value="good" selected>Good (lightly used)</option>
            <option value="fair">Fair (usable)</option>
          </select>
        </div>
      </div>

      <!-- ── SCHOOL SUPPLIES FIELDS ── -->
      <div id="grpSchool" class="hidden">
        <div class="field-row">
          <div class="field">
            <label>Items included</label>
            <input type="text" name="subject_grade" placeholder="e.g. Bags, shoes, uniform, geometry box">
          </div>
          <div class="field">
            <label>Suitable for Grade</label>
            <input type="text" name="book_count" placeholder="e.g. Class 1-5">
          </div>
        </div>
        <div class="field">
          <label>Condition *</label>
          <select name="condition_type">
            <option value="new">New</option>
            <option value="good" selected>Good</option>
            <option value="fair">Fair</option>
          </select>
        </div>
      </div>

      <!-- ── TOYS FIELDS ── -->
      <div id="grpToys" class="hidden">
        <div class="field-row">
          <div class="field">
            <label>Age Group</label>
            <select name="subject_grade">
              <option value="">Select age group</option>
              <option value="0-3 years">0–3 years (Infant)</option>
              <option value="3-6 years">3–6 years (Toddler)</option>
              <option value="6-12 years">6–12 years (Child)</option>
              <option value="12+ years">12+ years (Teen)</option>
              <option value="All ages">All ages</option>
            </select>
          </div>
          <div class="field">
            <label>Condition *</label>
            <select name="condition_type">
              <option value="new">New</option>
              <option value="good" selected>Good</option>
              <option value="fair">Fair</option>
            </select>
          </div>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" name="toy_safe" id="toySafe">
          <label for="toySafe">I confirm toys are safe, complete and have no broken/sharp parts</label>
        </div>
      </div>

      <!-- ── MEDICINES FIELDS ── -->
      <div id="grpMedicines" class="hidden">
        <div class="field-row">
          <div class="field">
            <label>Medicine Type</label>
            <input type="text" name="medicine_type" placeholder="e.g. Paracetamol, Vitamins, ORS">
          </div>
          <div class="field">
            <label>Expiry Date *</label>
            <input type="date" name="expiry_date" id="fieldExpiry">
          </div>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" name="med_sealed" id="medSealed">
          <label for="medSealed">I confirm all medicines are sealed, unexpired and properly stored</label>
        </div>
      </div>

      <!-- ── ELECTRONICS FIELDS ── -->
      <div id="grpElectronics" class="hidden">
        <div class="field-row">
          <div class="field">
            <label>Device Type</label>
            <select name="device_type">
              <option value="">Select device</option>
              <option value="Mobile Phone">Mobile Phone</option>
              <option value="Tablet">Tablet</option>
              <option value="Laptop">Laptop</option>
              <option value="Desktop">Desktop Computer</option>
              <option value="Charger/Cable">Charger / Cable</option>
              <option value="Headphones">Headphones</option>
              <option value="Calculator">Calculator</option>
              <option value="Other">Other Electronics</option>
            </select>
          </div>
          <div class="field">
            <label>Working Status *</label>
            <select name="working_status">
              <option value="working">Fully Working</option>
              <option value="partially_working">Partially Working</option>
              <option value="not_working">Not Working (for parts)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- ── FURNITURE FIELDS ── -->
      <div id="grpFurniture" class="hidden">
        <div class="field-row">
          <div class="field">
            <label>Furniture Type</label>
            <input type="text" name="device_type" placeholder="e.g. Study desk, Chair, Shelf">
          </div>
          <div class="field">
            <label>Condition *</label>
            <select name="condition_type">
              <option value="new">New</option>
              <option value="good" selected>Good</option>
              <option value="fair">Fair</option>
            </select>
          </div>
        </div>
      </div>

      <!-- ── OTHER FIELDS ── -->
      <div id="grpOther" class="hidden">
        <div class="field">
          <label>What is it? *</label>
          <input type="text" name="device_type" placeholder="Describe the item briefly">
        </div>
        <div class="field">
          <label>Condition</label>
          <select name="condition_type">
            <option value="new">New</option>
            <option value="good" selected>Good</option>
            <option value="fair">Fair</option>
            <option value="worn">Worn</option>
          </select>
        </div>
      </div>

      <!-- ── PICKUP DETAILS (common) ── -->
      <div style="margin-top:24px;padding-top:24px;border-top:2px solid var(--border)">
        <div style="font-size:13px;font-weight:700;color:var(--accent);margin-bottom:16px;text-transform:uppercase;letter-spacing:.5px">📍 Pickup Details</div>
        <div class="field">
          <label>Pickup Address *</label>
          <textarea name="pickup_address" placeholder="Full address with landmark for volunteer to locate easily" required rows="2"></textarea>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Contact Number *</label>
            <input type="tel" name="contact" pattern="[6-9][0-9]{9}" placeholder="10-digit mobile" required>
          </div>
          <div class="field">
            <label>Preferred Pickup Date</label>
            <input type="date" name="pickup_date" id="fieldPickupDate">
          </div>
        </div>
        <div class="field">
          <label>Upload Photo (optional)</label>
          <input type="file" name="image" accept="image/*" id="fieldImage">
        </div>
        <div class="field">
          <label>Additional Notes</label>
          <textarea name="notes" placeholder="Any special instructions for the volunteer..." rows="2"></textarea>
        </div>
      </div>

      <div class="impact" id="impactMsg">🌱 Your donation will make a real difference!</div>

      <button type="submit" class="btn-primary" id="submitBtn">Submit Donation →</button>
      <button type="button" class="btn-back" onclick="backToCategory()">← Change Category</button>
    </form>
  </div>
</div>

</div><!-- .page -->

<script>
const CATS = {
  food: {
    icon:'🍱', name:'Food Donation',
    sub:'Food must be freshly prepared and properly packed. Pickup is same-day.',
    impact:'🌱 Your food donation can feed 5–20 people today.',
    guide: ['Fresh cooked food only (same day)', 'Properly packed in containers', 'No expired or stale food', 'Include prepared time & safe hours'],
    groups: ['grpFood'],
    qtyPlaceholder:'e.g. Serves 20 people / 5 kg',
    descPlaceholder:'What food is it? e.g. Dal-rice, chapati, biryani...',
    requiredGroups: ['food_time','safe_hours','foodSafeConfirm']
  },
  clothes: {
    icon:'👕', name:'Clothes & Footwear',
    sub:'Clothes, shoes, footwear — size details help us match the right beneficiary.',
    impact:'👕 Clean clothes and shoes bring dignity and confidence.',
    guide: ['Clean and washed items only', 'No torn or badly worn items', 'Select sizes accurately — it helps us match beneficiaries', 'Footwear: wipe clean before donating'],
    groups: ['grpClothes'],
    qtyPlaceholder:'e.g. 5 shirts, 3 pants / 2 pairs of shoes',
    descPlaceholder:'Describe what you\'re donating — type, color, brand if known...'
  },
  study_material: {
    icon:'📚', name:'Study Material Donation',
    sub:'Books, notebooks, stationery — give a child the gift of education.',
    impact:'📚 One set of books can change a child\'s academic year.',
    guide: ['NCERT / school books preferred', 'Notebooks can be partially used', 'No torn or water-damaged books', 'Stationery should be functional'],
    groups: ['grpStudy'],
    qtyPlaceholder:'e.g. 15 books, 10 notebooks, stationery kit',
    descPlaceholder:'List subjects and grades if known...'
  },
  school_supplies: {
    icon:'🎒', name:'School Supplies Donation',
    sub:'School bags, uniforms, shoes and more — help a child go to school.',
    impact:'🎒 A school bag and uniform can keep a child in school.',
    guide: ['School bags should be in good condition', 'Uniforms must be clean', 'Shoes should be wearable', 'Mention size/grade if possible'],
    groups: ['grpSchool'],
    qtyPlaceholder:'e.g. 2 bags, 3 uniforms, 2 pairs of shoes',
    descPlaceholder:'Describe the items — size, type, brand if relevant...'
  },
  toys: {
    icon:'🧸', name:'Toys & Games Donation',
    sub:'Toys, board games and sports equipment for children.',
    impact:'🧸 A toy brings joy and supports a child\'s development.',
    guide: ['No broken or sharp-edged toys', 'Board games must have all pieces', 'Clean and safe to use', 'Batteries removed from electronic toys'],
    groups: ['grpToys'],
    qtyPlaceholder:'e.g. 5 toys, 2 board games, 1 cricket bat',
    descPlaceholder:'What toys? Mention age suitability if known...'
  },
  medicines: {
    icon:'💊', name:'Medicine Donation',
    sub:'Sealed, unexpired medicines only. We verify before redistribution.',
    impact:'💊 Medicines can save lives in underserved communities.',
    guide: ['Sealed packaging only', 'Minimum 3 months before expiry', 'No prescription-only medicines (unless specified)', 'Store in cool dry place before pickup'],
    groups: ['grpMedicines'],
    qtyPlaceholder:'e.g. 20 strips, 5 bottles, 2 boxes',
    descPlaceholder:'List medicine names and quantities...',
    requiredGroups: ['expiry_date','medSealed']
  },
  electronics: {
    icon:'📱', name:'Electronics Donation',
    sub:'Working or partially working devices for students and families.',
    impact:'📱 A refurbished phone or tablet enables digital education.',
    guide: ['Factory reset phones/laptops before donating', 'Include charger if available', 'Mention if screen is cracked', 'All data must be wiped'],
    groups: ['grpElectronics'],
    qtyPlaceholder:'e.g. 1 phone, 2 chargers, 1 tablet',
    descPlaceholder:'Model, brand, any known issues...'
  },
  furniture: {
    icon:'🪑', name:'Furniture Donation',
    sub:'Study desks, chairs, shelves — support a child\'s study space.',
    impact:'🪑 A study desk can transform a child\'s ability to learn at home.',
    guide: ['Must be structurally sound', 'No heavily damaged items', 'Disassembled if possible for transport', 'Mention dimensions if large'],
    groups: ['grpFurniture'],
    qtyPlaceholder:'e.g. 1 study table, 2 chairs',
    descPlaceholder:'Describe material, color, size...'
  },
  other: {
    icon:'📦', name:'Other Donation',
    sub:'Have something else to donate? We\'ll do our best to find it a home.',
    impact:'📦 Every item donated is one less thing wasted.',
    guide: ['Must be in usable condition', 'Safe to handle and transport', 'Describe clearly so we can assess', 'Photos help a lot!'],
    groups: ['grpOther'],
    qtyPlaceholder:'e.g. 1 item / 3 pieces / 1 set',
    descPlaceholder:'What is it? Why would it be useful?'
  }
};

let selectedCat = null;

function selectCat(cat, el) {
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('selected'));
  el.classList.add('selected');
  selectedCat = cat;

  const cfg = CATS[cat];

  // Set hidden field
  document.getElementById('fieldCategory').value = cat;

  // Form title/sub
  document.getElementById('formTitle').textContent = cfg.icon + ' ' + cfg.name;
  document.getElementById('formSub').textContent   = cfg.sub;

  // Guidelines
  const gBox = document.getElementById('guidelineBox');
  gBox.innerHTML = '<h4>📋 Guidelines for ' + cfg.name + '</h4><ul>' +
    cfg.guide.map(g => '<li>' + g + '</li>').join('') + '</ul>';

  // Impact
  document.getElementById('impactMsg').textContent = cfg.impact;

  // Qty placeholder
  document.getElementById('fieldQty').placeholder  = cfg.qtyPlaceholder || 'Quantity';
  document.getElementById('fieldDesc').placeholder = cfg.descPlaceholder || 'Description';

  // Show/hide category-specific field groups
  ['grpFood','grpClothes','grpStudy','grpSchool','grpToys','grpMedicines','grpElectronics','grpFurniture','grpOther']
    .forEach(g => {
      const el2 = document.getElementById(g);
      if (el2) el2.classList.toggle('hidden', !cfg.groups.includes(g));
    });

  // Update step indicator
  setStep(2);

  // Show form panel
  document.getElementById('panelCategory').classList.add('hidden');
  document.getElementById('panelForm').classList.remove('hidden');
  window.scrollTo({top:0, behavior:'smooth'});
}

function backToCategory() {
  document.getElementById('panelCategory').classList.remove('hidden');
  document.getElementById('panelForm').classList.add('hidden');
  setStep(1);
  window.scrollTo({top:0, behavior:'smooth'});
}

function setStep(n) {
  for (let i = 1; i <= 4; i++) {
    const s = document.getElementById('step' + i);
    if (!s) continue;
    s.classList.remove('active','done');
    if (i < n) s.classList.add('done');
    else if (i === n) s.classList.add('active');
  }
  for (let i = 1; i <= 3; i++) {
    const l = document.getElementById('line' + i);
    if (l) l.classList.toggle('done', i < n);
  }
}

// Auto-set min pickup date to today
const pd = document.getElementById('fieldPickupDate');
if (pd) pd.min = new Date().toISOString().split('T')[0];

// Food time max = now
const ft = document.getElementById('fieldFoodTime');
if (ft) {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  ft.max = now.toISOString().slice(0,16);
}

// Form submit validation
document.getElementById('donateForm').addEventListener('submit', function(e) {
  const btn = document.getElementById('submitBtn');
  btn.textContent = '⏳ Submitting…';
  btn.disabled = true;

  // Food-specific validation
  if (selectedCat === 'food') {
    const foodSafe = document.getElementById('foodSafeConfirm');
    if (foodSafe && !foodSafe.checked) {
      e.preventDefault();
      alert('Please confirm the food is safe and hygienic.');
      btn.textContent = 'Submit Donation →';
      btn.disabled = false;
      return;
    }
  }

  // Medicines — check expiry date is in future
  if (selectedCat === 'medicines') {
    const exp = document.getElementById('fieldExpiry');
    const medSealed = document.getElementById('medSealed');
    if (exp && exp.value && new Date(exp.value) <= new Date()) {
      e.preventDefault();
      alert('Expiry date must be in the future. Expired medicines cannot be accepted.');
      btn.textContent = 'Submit Donation →';
      btn.disabled = false;
      return;
    }
    if (medSealed && !medSealed.checked) {
      e.preventDefault();
      alert('Please confirm medicines are sealed and unexpired.');
      btn.textContent = 'Submit Donation →';
      btn.disabled = false;
      return;
    }
  }

  setStep(4);
});

// ── Clothes sub-category toggle ──
function toggleClothSubcat(val) {
  const cg = document.getElementById('subGrpClothes');
  const fg = document.getElementById('subGrpFootwear');
  if (!cg || !fg) return;
  cg.classList.toggle('hidden', val !== 'clothes');
  fg.classList.toggle('hidden', val !== 'footwear');
  // highlight selected label
  document.getElementById('lblClothes').style.borderColor = val==='clothes' ? 'var(--accent)' : 'var(--border)';
  document.getElementById('lblFootwear').style.borderColor = val==='footwear' ? 'var(--accent)' : 'var(--border)';
}

// ── Size chip builder ──
const CLOTH_SIZES = {
  Men:     ['XS','S','M','L','XL','XXL','3XL','Free Size'],
  Women:   ['XS','S','M','L','XL','XXL','Free Size'],
  Boys:    ['2Y','3Y','4Y','5Y','6Y','7Y','8Y','9Y','10Y','11Y','12Y','13Y','14Y'],
  Girls:   ['2Y','3Y','4Y','5Y','6Y','7Y','8Y','9Y','10Y','11Y','12Y','13Y','14Y'],
  Toddler: ['6M','9M','12M','18M','24M','2Y','3Y','4Y','5Y'],
  Infant:  ['0-3M','3-6M','6-9M','9-12M','12-18M'],
  Mixed:   ['XS','S','M','L','XL','XXL','Kids','Free Size'],
};
const FOOTWEAR_SIZES = {
  Men:      ['6','7','8','9','10','11','12'],
  Women:    ['3','4','5','6','7','8','9'],
  Boys:     ['1','2','3','4','5','6','7','8','9','10'],
  Girls:    ['1','2','3','4','5','6','7','8'],
  Children: ['1','2','3','4','5','6','7','8','9','10'],
  Infant:   ['0','1','2','3','4','5'],
  Mixed:    ['All Sizes'],
};

function buildSizeChips(containerId, hiddenId, sizes) {
  const c = document.getElementById(containerId);
  if (!c) return;
  c.innerHTML = '';
  sizes.forEach(s => {
    const chip = document.createElement('label');
    chip.style.cssText = 'display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border:1.5px solid var(--border);border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;transition:.2s;background:#fafaf6;color:var(--muted)';
    const cb = document.createElement('input');
    cb.type = 'checkbox'; cb.value = s; cb.style.display = 'none';
    cb.addEventListener('change', () => {
      chip.style.borderColor    = cb.checked ? 'var(--accent)' : 'var(--border)';
      chip.style.background     = cb.checked ? 'rgba(0,109,119,.08)' : '#fafaf6';
      chip.style.color          = cb.checked ? 'var(--accent)' : 'var(--muted)';
      chip.style.fontWeight     = cb.checked ? '800' : '600';
      // Update hidden field
      const selected = [...c.querySelectorAll('input:checked')].map(i=>i.value);
      document.getElementById(hiddenId).value = selected.join(', ');
    });
    chip.appendChild(cb);
    chip.appendChild(document.createTextNode(s));
    c.appendChild(chip);
  });
}

function updateClothSizes() {
  const who = document.getElementById('fieldClothFor')?.value || 'Mixed';
  const sizes = CLOTH_SIZES[who] || CLOTH_SIZES['Mixed'];
  buildSizeChips('clothSizeOptions', 'fieldClothSizes', sizes);
}

function updateFootwearSizes() {
  const who = document.getElementById('fieldFootwearFor')?.value || 'Mixed';
  const sizes = FOOTWEAR_SIZES[who] || ['All Sizes'];
  buildSizeChips('footwearSizeOptions', 'fieldFootwearSizes', sizes);
}

// Init default sizes on page load
updateClothSizes();
updateFootwearSizes();

// Mobile menu
const mt = document.getElementById('menuToggle');
if (mt) mt.addEventListener('click', () => document.getElementById('mobileMenu')?.classList.toggle('show'));
</script>
</body>
</html>
