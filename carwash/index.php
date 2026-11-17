<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/catalog.php';
require __DIR__ . '/includes/scheduling.php';
require __DIR__ . '/includes/reviews.php';

$title = 'Home';
$homePage = true;
$extraCss = ['assets/css/home.css'];   // front-page design layer
$fullWidth = true;      // sections span the whole page width
$heroPhoto = is_file(__DIR__ . '/assets/img/hero.jpg');   // drop a photo here to use it as the hero background

$packages = load_packages($pdo, true);
$bookUrl  = url(current_user() ? 'customer/book.php' : 'register.php');
$promos   = $pdo->query('SELECT * FROM promos WHERE is_active = 1 AND CURDATE() BETWEEN start_date AND end_date')->fetchAll();
$snap     = queue_snapshot($pdo);
$revStats = review_stats($pdo);
$reviews  = $pdo->query("SELECT rv.rating, rv.comment, rv.staff_reply, rv.created_at, u.name AS customer, p.name AS package
    FROM reviews rv JOIN users u ON u.id = rv.user_id JOIN packages p ON p.id = rv.package_id
    WHERE rv.is_visible = 1 ORDER BY (rv.comment IS NOT NULL AND rv.comment <> '') DESC, rv.id DESC LIMIT 6")->fetchAll();


// "Our Work" gallery: photos live in assets/img/gallery/. Add a line here to add a photo.
// type: 'results' (before/after + finished work) or 'work' (our team at work)
$gallery = [
    ['file' => 'exterior-before-after.jpg', 'type' => 'results', 'tag' => 'Before & After', 'title' => 'Exterior detailing',        'text' => 'Hood and door of a Navara: paint cleaned and brought back to a deep, even shine.', 'w' => 940,  'h' => 788],
    ['file' => 'seat-shampoo.jpg',          'type' => 'work',    'tag' => 'At work',        'title' => 'Seat shampoo',              'text' => 'Fabric seats hand-scrubbed with foam to lift stains and everyday dirt.',           'w' => 1050, 'h' => 1400],
    ['file' => 'interior-before-after.jpg', 'type' => 'results', 'tag' => 'Before & After', 'title' => 'Interior detailing',        'text' => 'Seats and floor panels taken from stained and grimy to fresh and clean.',          'w' => 940,  'h' => 788],
    ['file' => 'carpet-wash.jpg',           'type' => 'work',    'tag' => 'At work',        'title' => 'Carpet deep wash',          'text' => 'Floor carpet removed from the car and scrubbed outside for a thorough clean.',     'w' => 1050, 'h' => 1400],
    ['file' => 'floor-after.jpg',           'type' => 'results', 'tag' => 'Result',         'title' => 'Bare floor, fully cleaned', 'text' => 'With the carpet out, the floor pan is washed, dried and ready to go back in.',   'w' => 1400, 'h' => 1050],
    ['file' => 'floor-scrub.jpg',           'type' => 'work',    'tag' => 'At work',        'title' => 'Floor pan scrub',           'text' => 'Every corner under the carpet is scrubbed by hand, with no shortcuts.',            'w' => 1050, 'h' => 1400],
];
$gallery = array_values(array_filter($gallery, fn($g) => is_file(__DIR__ . '/assets/img/gallery/' . $g['file'])));

// Per-category summary for the service tiles
$cats = [];
foreach (category_labels() as $key => $label) {
    if ($key === 'special') continue;   // special services are not shown on the public page
    $rows = array_values(array_filter($packages, fn($p) => $p['category'] === $key));
    if (!$rows) continue;
    $all = [];
    foreach ($rows as $r) foreach ($r['prices'] as $v) $all[] = $v;
    $cats[$key] = [
        'label' => $label,
        'rows'  => $rows,
        'min'   => $all ? min($all) : 0,
        'sizes' => array_map(fn($s) => preg_replace('/^Motorcycle - /', '', $s[0]), array_values(sizes_for($key))),
    ];
}
$catIcon = ['car' => 'car', 'motorcycle' => 'bike', 'special' => 'star'];
$catBlurb = [
    'car'        => 'Washes, wax, detailing and restoration for every car size, from small hatchbacks to XXL vehicles.',
    'motorcycle' => 'Quick washes and full care packages for motorcycles, e-bikes, tricycles and jet skis.',
    'special'    => 'Flat-rate service for unusual rides that need extra attention.',
];
$minAll = 0;
foreach ($cats as $c) { if ($c['min'] && (!$minAll || $c['min'] < $minAll)) $minAll = $c['min']; }
$nowHr    = (int)date('G');
$shopOpen = $nowHr >= OPEN_HOUR && $nowHr < CLOSE_HOUR;
$freeBays = max(0, (int)$snap['total_bays'] - (int)$snap['busy_bays']);
$mapQuery = rawurlencode(SHOP_NAME . ', ' . SHOP_ADDRESS);

require __DIR__ . '/includes/header.php';
?>

<script>document.documentElement.classList.add('js');</script>

<!-- ================= HERO ================= -->
<header class="hero-wrap">
  <section class="hero-full <?= $heroPhoto ? 'has-photo' : '' ?>" <?= $heroPhoto ? 'style="--hero-img:url(\'' . url('assets/img/hero.jpg') . '\')"' : '' ?>>
    <div class="container position-relative">
      <div class="row align-items-center gy-5">
        <div class="col-lg-7">
          <div class="hero-kicker"><i></i>Nasugbu, Batangas · Wash · Wax · Detail</div>
          <h1 class="hero-title">Book your wash.<br><span>Skip the wait.</span></h1>
          <p class="hero-sub">Reserve a slot online, see live bay availability and get a digital receipt. Car, motorcycle or jet ski, we've got you covered.</p>
          <div class="hero-actions">
            <a class="btn btn-red btn-lg-cta rounded-pill" href="<?= $bookUrl ?>">Get an appointment <?= icon('arrow-right') ?></a>
            <a class="btn btn-outline-ghost btn-lg-cta rounded-pill" href="#rates">View prices</a>
          </div>
        </div>

        <div class="col-lg-5">
          <aside class="live-card" aria-label="Live shop status">
            <div class="live-head">
              <span class="live-title">Live at the shop</span>
              <span class="live-pill <?= $shopOpen ? 'is-open' : 'is-closed' ?>"><i></i><?= $shopOpen ? 'Open now' : 'Closed' ?></span>
            </div>
            <div class="live-grid">
              <div><b><?= $freeBays ?><small>/<?= (int)$snap['total_bays'] ?></small></b><span>Bays free</span></div>
              <div><b><?= (int)$snap['queue_length'] ?></b><span>In queue</span></div>
              <div><b><?= (int)$snap['est_wait_min'] ?><small> min</small></b><span>Est. wait</span></div>
            </div>
            <div class="live-meta">
              <span><?= icon('clock') ?>Daily <?= e(hours_label()) ?></span>
              <?php if ($minAll): ?><span>Washes from <strong><?= peso($minAll) ?></strong></span><?php endif; ?>
            </div>
            <a class="live-link" href="<?= url('queue.php') ?>">See the live queue <?= icon('arrow-right') ?></a>
          </aside>
        </div>
      </div>
    </div>
  </section>

  <div class="container trust-strip-wrap">
    <ul class="trust-strip">
      <li><span class="trust-ico"><?= icon('calendar') ?></span><div><strong>Open slots only</strong><small>No double bookings</small></div></li>
      <li><span class="trust-ico"><?= icon('queue') ?></span><div><strong>Live queue &amp; bays</strong><small>Know the wait first</small></div></li>
      <li><span class="trust-ico"><?= icon('tag') ?></span><div><strong>Clear pricing</strong><small>By vehicle size</small></div></li>
      <li><span class="trust-ico"><?= icon('receipt') ?></span><div><strong>Digital receipts</strong><small>Saved to your account</small></div></li>
    </ul>
  </div>
</header>

<?php if ($promos): ?>
<!-- ================= PROMOS ================= -->
<section id="promos" class="home-section pb-0">
  <div class="container">
    <div class="section-head text-center reveal">
      <div class="section-kicker">Current offers</div>
      <h2>Promos</h2>
    </div>
    <div class="row g-4 justify-content-center reveal">
      <?php foreach ($promos as $p): ?>
      <div class="col-md-6 col-lg-4">
        <div class="card promo shadow-sm hover-lift h-100"><div class="card-body">
          <span class="badge bg-danger mb-2"><?= rtrim(rtrim(number_format($p['discount_percent'], 2), '0'), '.') ?>% OFF</span>
          <h3 class="h5"><?= e($p['title']) ?></h3>
          <p><?= e($p['description']) ?></p>
          <small class="text-muted">Valid <?= e($p['start_date']) ?> to <?= e($p['end_date']) ?></small>
        </div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ================= SERVICES & RATES (tabs) ================= -->
<section id="rates" class="home-section">
  <div class="container">
    <div class="section-head text-center reveal">
      <div class="section-kicker">Prices by vehicle size</div>
      <h2>Services &amp; Rates</h2>
      <p class="text-muted mx-auto">Prices depend on your vehicle size. Services marked <strong>from</strong> start at the amount shown and may vary with the extent of work.</p>
    </div>

    <ul class="nav rate-tabs justify-content-center" id="rateTabs" role="tablist">
      <?php $first = true; foreach ($cats as $key => $c): ?>
        <li class="nav-item" role="presentation">
          <button class="nav-link <?= $first ? 'active' : '' ?>" id="tab-<?= e($key) ?>" data-bs-toggle="pill" data-bs-target="#pane-<?= e($key) ?>" type="button" role="tab" aria-controls="pane-<?= e($key) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>">
            <?= e($c['label']) ?> <span><?= count($c['rows']) ?></span>
          </button>
        </li>
      <?php $first = false; endforeach; ?>
    </ul>

    <div class="tab-content">
    <?php $first = true; foreach ($cats as $catKey => $c):
        $rows = $c['rows']; $cols = sizes_for($catKey); ?>
      <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="pane-<?= e($catKey) ?>" role="tabpanel" aria-labelledby="tab-<?= e($catKey) ?>" tabindex="0">
        <div class="rate-tools" data-pane="<?= e($catKey) ?>">
          <label class="rate-filter">
            <span>Choose a service</span>
            <select class="form-select rate-select" aria-label="Filter services">
              <option value="">All services (<?= count($rows) ?>)</option>
              <?php foreach ($rows as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="rate-grid reveal">
          <?php foreach ($rows as $p):
              $shown = [];
              foreach ($cols as $key => [$label]) { if (isset($p['prices'][$key])) $shown[$key] = [preg_replace('/^Motorcycle - /', '', $label), $p['prices'][$key]]; }
              $isFlat = $shown && count(array_unique(array_column($shown, 1))) === 1 && count($shown) > 1;
              $isStart = !empty($p['price_is_starting']); ?>
            <article class="rate-card hover-lift" data-id="<?= (int)$p['id'] ?>">
              <header class="rate-card-head">
                <h4 class="rate-card-name"><?= e($p['name']) ?></h4>
                <span class="rate-time" title="Estimated duration"><?= icon('clock') ?>≈ <?= (int)$p['duration_min'] ?> min</span>
              </header>
              <p class="rate-card-desc"><?= e($p['description']) ?></p>

              <?php if ($isFlat): $f = reset($shown); ?>
                <div class="rate-flat">
                  <span class="rate-flat-label"><?= $isStart ? 'Starting price' : 'Flat rate' ?></span>
                  <span class="rate-flat-price"><?= $isStart ? '<small>from</small> ' : '' ?><?= peso($f[1]) ?></span>
                </div>
              <?php else: ?>
                <div class="rate-sizes">
                  <?php foreach ($shown as [$label, $price]): ?>
                    <div class="rate-size">
                      <span class="rate-size-label"><?= e($label) ?></span>
                      <span class="rate-size-price"><?= $isStart ? '<small>from</small> ' : '' ?><?= peso($price) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <a class="btn btn-red rate-book" href="<?= $bookUrl ?>">Book this service</a>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if (count($rows) > 4): ?><div class="text-center mt-4"><button type="button" class="btn btn-outline-primary rounded-pill px-4 rate-more" data-total="<?= count($rows) ?>">Show all <?= count($rows) ?> services</button></div><?php endif; ?>
      </div>
    <?php $first = false; endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section id="how" class="home-section band-dark">
  <div class="container">
    <div class="section-head text-center reveal">
      <div class="section-kicker">Simple as 1-2-3-4</div>
      <h2 class="text-white">How it works</h2>
    </div>
    <div class="row g-4">
      <div class="col-sm-6 col-lg-3"><div class="step"><span class="step-no">01</span><h3>Create an account</h3><p>Sign up in a minute so your bookings and receipts are in one place.</p></div></div>
      <div class="col-sm-6 col-lg-3"><div class="step"><span class="step-no">02</span><h3>Choose service &amp; time</h3><p>Pick your vehicle size and a package, then reserve any open time slot.</p></div></div>
      <div class="col-sm-6 col-lg-3"><div class="step"><span class="step-no">03</span><h3>Check the live queue</h3><p>See which bays are free and the current wait before you head over.</p></div></div>
      <div class="col-sm-6 col-lg-3"><div class="step"><span class="step-no">04</span><h3>Drive in &amp; get a receipt</h3><p>We wash your ride, and you get a digital receipt once it's paid.</p></div></div>
    </div>
  </div>
</section>

<!-- ================= WHY CHOOSE US ================= -->
<section id="why" class="home-section pt-0">
  <div class="container">
    <div class="row align-items-center gy-4">
      <div class="col-lg-5">
        <div class="about-visual">
          <div class="about-stat"><b><?= array_sum(array_map(fn($c) => count($c['rows']), $cats)) ?></b><span>Services to choose from</span></div>
          <div class="about-stat"><b><?= count(array_filter(vehicle_sizes(), fn($v) => $v[1] !== 'special')) ?></b><span>Vehicle sizes &amp; types</span></div>
          <div class="about-stat"><b><?= (int)$snap['total_bays'] ?></b><span>Wash bays, tracked live</span></div>
        </div>
      </div>
      <div class="col-lg-7 ps-lg-5">
        <div class="section-kicker">Why Eayila</div>
        <h2>Why choose Eayila Carwash</h2>
        <p class="text-muted mb-0">Clear prices for every vehicle size and no guessing how long the line is. Pick a service, pick a time, and we'll have a bay ready.</p>
        <ul class="feature-list">
          <li><?= icon('check') ?><span><strong>Reserve a time slot</strong> online — only open slots are shown.</span></li>
          <li><?= icon('check') ?><span><strong>See the live queue</strong> and bay availability before you arrive.</span></li>
          <li><?= icon('check') ?><span><strong>Transparent pricing</strong> for cars, motorcycles, e-bikes, tricycles and jet skis.</span></li>
          <li><?= icon('check') ?><span><strong>Digital receipt</strong> once your service is paid.</span></li>
        </ul>
        <a class="btn btn-red rounded-pill px-4 text-uppercase" href="<?= $bookUrl ?>">Book now</a>
        <a class="btn btn-outline-primary rounded-pill px-4 text-uppercase ms-1" href="<?= url('queue.php') ?>">Live queue</a>
      </div>
    </div>
  </div>
</section>

<?php if ($gallery): ?>
<!-- ================= OUR WORK (interactive gallery) ================= -->
<section id="gallery" class="home-section band-sand">
  <div class="container">
    <div class="section-head text-center reveal">
      <div class="section-kicker">Our work</div>
      <h2>See the difference</h2>
      <p class="text-muted mx-auto">Real jobs from our shop. Tap any photo to view it full size.</p>
    </div>

    <div class="gal-filters reveal" role="group" aria-label="Filter photos">
      <button type="button" class="gal-chip active" data-filter="all" aria-pressed="true">All <span><?= count($gallery) ?></span></button>
      <button type="button" class="gal-chip" data-filter="results" aria-pressed="false">Results <span><?= count(array_filter($gallery, fn($g) => $g['type'] === 'results')) ?></span></button>
      <button type="button" class="gal-chip" data-filter="work" aria-pressed="false">At work <span><?= count(array_filter($gallery, fn($g) => $g['type'] === 'work')) ?></span></button>
    </div>

    <div class="gal-grid reveal" id="galGrid">
      <?php foreach ($gallery as $g): $src = url('assets/img/gallery/' . $g['file']); ?>
      <figure class="gal-item" data-type="<?= e($g['type']) ?>">
        <button type="button" class="gal-open" data-src="<?= e($src) ?>" data-title="<?= e($g['title']) ?>" data-text="<?= e($g['text']) ?>" data-tag="<?= e($g['tag']) ?>" aria-label="View photo: <?= e($g['title']) ?>">
          <img src="<?= e($src) ?>" width="<?= (int)$g['w'] ?>" height="<?= (int)$g['h'] ?>" loading="lazy" alt="<?= e($g['title'] . ': ' . $g['text']) ?>">
          <span class="gal-tag"><?= e($g['tag']) ?></span>
          <span class="gal-zoom" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3M11 8v6M8 11h6"/></svg></span>
          <figcaption class="gal-cap"><strong><?= e($g['title']) ?></strong><span><?= e($g['text']) ?></span></figcaption>
        </button>
      </figure>
      <?php endforeach; ?>
    </div>

    <div class="text-center mt-5 reveal">
      <a class="btn btn-red btn-lg-cta rounded-pill" href="<?= $bookUrl ?>">Book your detail <?= icon('arrow-right') ?></a>
    </div>
  </div>
</section>

<!-- Lightbox -->
<div class="lbx" id="lbx" role="dialog" aria-modal="true" aria-label="Photo viewer" hidden>
  <button type="button" class="lbx-close" aria-label="Close">&times;</button>
  <button type="button" class="lbx-nav lbx-prev" aria-label="Previous photo">&#8249;</button>
  <button type="button" class="lbx-nav lbx-next" aria-label="Next photo">&#8250;</button>
  <figure class="lbx-stage">
    <img id="lbxImg" src="" alt="">
    <figcaption>
      <span class="lbx-tag" id="lbxTag"></span>
      <strong id="lbxTitle"></strong>
      <span id="lbxText"></span>
      <small id="lbxCount"></small>
    </figcaption>
  </figure>
</div>

<?php endif; ?>
<?php if ($revStats['count']): ?>
<!-- ================= CUSTOMER REVIEWS ================= -->
<section id="reviews" class="home-section">
  <div class="container">
    <div class="section-head text-center reveal">
      <div class="section-kicker">Customer feedback</div>
      <h2>What our customers say</h2>
      <div class="rating-summary"><span class="rating-big"><?= number_format($revStats['avg'], 1) ?></span> <?= stars_html($revStats['avg'], 'lg') ?>
        <span class="text-muted">based on <?= (int)$revStats['count'] ?> review<?= $revStats['count'] === 1 ? '' : 's' ?></span></div>
    </div>
    <div class="row g-4">
      <?php foreach ($reviews as $rv): ?>
      <div class="col-md-6 col-lg-4">
        <figure class="card review-card shadow-sm hover-lift h-100 mb-0"><div class="card-body">
          <?= stars_html($rv['rating']) ?>
          <blockquote class="mb-3 mt-2"><?= $rv['comment'] ? nl2br(e($rv['comment'])) : '<span class="text-muted fst-italic">Rated this service ' . (int)$rv['rating'] . ' out of 5.</span>' ?></blockquote>
          <figcaption class="small"><strong><?= e(public_name($rv['customer'])) ?></strong> <span class="text-muted">· <?= e($rv['package']) ?> · <?= e(date('M Y', strtotime($rv['created_at']))) ?></span></figcaption>
          <?php if ($rv['staff_reply']): ?><div class="review-reply mt-3"><strong>Reply from the shop</strong><br><?= nl2br(e($rv['staff_reply'])) ?></div><?php endif; ?>
        </div></figure>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php endif; ?>
<!-- ================= FAQ ================= -->
<section id="faq" class="home-section band-light">
  <div class="container">
    <div class="row gy-4">
      <div class="col-lg-4">
        <div class="section-kicker">Good to know</div>
        <h2>Frequently asked questions</h2>
        <p class="text-muted">Can't find your answer? Contact us using the details below and we'll help.</p>
        <a class="btn btn-outline-primary rounded-pill px-4 text-uppercase" href="#location">Contact us</a>
      </div>
      <div class="col-lg-8">
        <div class="accordion faq" id="faqList">
          <?php $faq = [
              ['How do I book a wash?', 'Create a free account, choose your vehicle size and service, then pick an open time slot. You can book from your phone or computer.'],
              ['What vehicles do you wash?', 'Cars of every size (small to XXL), motorcycles, e-bikes, tricycles and jet skis. The rate card above shows the price for each type.'],
              ['How long does a service take?', 'Each service card shows an estimated duration — for example a Premium Carwash takes about 45 minutes, while full detailing packages can take several hours.'],
              ['Can I check how busy you are?', 'Yes. The Live Queue page shows which of our ' . (int)$snap['total_bays'] . ' bays are in use and the estimated wait, updated in real time.'],
              ['Do I need an account to see prices?', 'No — rates are public. You only need an account when you want to reserve a slot and keep your digital receipts.'],
              ['What are your opening hours?', 'We are open daily from ' . hours_label() . '.'],
          ]; foreach ($faq as $i => [$q, $a]): ?>
          <div class="accordion-item">
            <h3 class="accordion-header" id="fq<?= $i ?>">
              <button class="accordion-button <?= $i ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#fa<?= $i ?>" aria-expanded="<?= $i ? 'false' : 'true' ?>" aria-controls="fa<?= $i ?>"><?= e($q) ?></button>
            </h3>
            <div id="fa<?= $i ?>" class="accordion-collapse collapse <?= $i ? '' : 'show' ?>" aria-labelledby="fq<?= $i ?>" data-bs-parent="#faqList">
              <div class="accordion-body"><?= e($a) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= LOCATION ================= -->
<section id="location" class="home-section">
  <div class="container">
    <div class="section-head text-center reveal">
      <div class="section-kicker">Visit us</div>
      <h2>Find us in Nasugbu</h2>
    </div>
    <div class="row g-4 align-items-stretch">
      <div class="col-lg-5">
        <div class="contact-card h-100">
          <div class="contact-row"><span class="contact-ico"><?= icon('pin') ?></span><div><strong>Address</strong><span><?= e(SHOP_ADDRESS) ?></span></div></div>
          <div class="contact-row"><span class="contact-ico"><?= icon('clock') ?></span><div><strong>Opening hours</strong><span>Daily, <?= e(hours_label()) ?></span></div></div>
          <div class="contact-row"><span class="contact-ico"><?= icon('phone') ?></span><div><strong>Phone</strong><span><?= e(SHOP_PHONE) ?></span></div></div>
          <div class="contact-row"><span class="contact-ico"><?= icon('mail') ?></span><div><strong>Email</strong><span><?= e(SHOP_EMAIL) ?></span></div></div>
          <a class="btn btn-red rounded-pill px-4 text-uppercase mt-2" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&amp;query=<?= $mapQuery ?>"><?= icon('nav') ?> Get directions</a>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="map-frame">
          <iframe title="Map to <?= e(SHOP_NAME) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                  src="https://maps.google.com/maps?q=<?= $mapQuery ?>&amp;z=16&amp;output=embed"></iframe>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= CTA ================= -->
<section class="cta-band">
  <div class="container text-center">
    <h2>Ready for a spotless ride?</h2>
    <p>Reserve your slot in a minute and skip the wait.</p>
    <a class="btn btn-light btn-lg-cta rounded-pill" href="<?= $bookUrl ?>">Get an appointment</a>
  </div>
</section>

<script>
// Services & Rates: dropdown filter + "show more" so the page is not a wall of boxes
document.addEventListener('DOMContentLoaded', function () {
  var LIMIT = 4;
  document.querySelectorAll('.tab-pane').forEach(function (pane) {
    var cards = Array.prototype.slice.call(pane.querySelectorAll('.rate-card'));
    var sel = pane.querySelector('.rate-select'), more = pane.querySelector('.rate-more'), expanded = false;
    if (!sel) return;
    function render() {
      var pick = sel.value;
      cards.forEach(function (c, i) {
        var show = pick ? c.dataset.id === pick : (expanded || i < LIMIT);
        c.classList.toggle('d-none', !show);
      });
      if (more) {
        more.hidden = !!pick;
        more.textContent = expanded ? 'Show fewer services' : 'Show all ' + more.dataset.total + ' services';
      }
    }
    sel.addEventListener('change', render);
    if (more) more.addEventListener('click', function () { expanded = !expanded; render(); });
    render();
  });
});
// Our Work gallery: filter chips + lightbox (keyboard, swipe, prev/next within the current filter)
document.addEventListener('DOMContentLoaded', function () {
  var grid = document.getElementById('galGrid'), box = document.getElementById('lbx');
  if (!grid || !box) return;
  var items = Array.prototype.slice.call(grid.querySelectorAll('.gal-item'));
  var chips = document.querySelectorAll('.gal-chip');
  var img = document.getElementById('lbxImg'), tag = document.getElementById('lbxTag'),
      ttl = document.getElementById('lbxTitle'), txt = document.getElementById('lbxText'), cnt = document.getElementById('lbxCount');
  var list = [], idx = 0, opener = null;

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      var f = chip.dataset.filter;
      chips.forEach(function (c) { var on = c === chip; c.classList.toggle('active', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      items.forEach(function (it) {
        var show = f === 'all' || it.dataset.type === f;
        it.classList.toggle('d-none', !show);
        if (show) { it.classList.remove('gal-in'); void it.offsetWidth; it.classList.add('gal-in'); }
      });
    });
  });

  function visible() { return items.filter(function (it) { return !it.classList.contains('d-none'); }); }
  function show(i) {
    idx = (i + list.length) % list.length;
    var b = list[idx].querySelector('.gal-open');
    img.classList.remove('lbx-fade'); void img.offsetWidth; img.classList.add('lbx-fade');
    img.src = b.dataset.src; img.alt = b.dataset.title;
    tag.textContent = b.dataset.tag; ttl.textContent = b.dataset.title; txt.textContent = b.dataset.text;
    cnt.textContent = (idx + 1) + ' / ' + list.length;
    box.classList.toggle('single', list.length < 2);
  }
  function open(it) {
    list = visible(); opener = it.querySelector('.gal-open');
    box.hidden = false; document.body.classList.add('lbx-lock');
    show(list.indexOf(it));
    requestAnimationFrame(function () { box.classList.add('open'); box.querySelector('.lbx-close').focus(); });
  }
  function close() {
    box.classList.remove('open'); document.body.classList.remove('lbx-lock');
    setTimeout(function () { box.hidden = true; img.src = ''; }, 200);
    if (opener) opener.focus();
  }
  items.forEach(function (it) { it.querySelector('.gal-open').addEventListener('click', function () { open(it); }); });
  box.querySelector('.lbx-close').addEventListener('click', close);
  box.querySelector('.lbx-prev').addEventListener('click', function () { show(idx - 1); });
  box.querySelector('.lbx-next').addEventListener('click', function () { show(idx + 1); });
  box.addEventListener('click', function (e) { if (e.target === box || e.target.classList.contains('lbx-stage')) close(); });
  document.addEventListener('keydown', function (e) {
    if (box.hidden) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowLeft') show(idx - 1);
    else if (e.key === 'ArrowRight') show(idx + 1);
    else if (e.key === 'Tab') { // keep focus inside the viewer
      var f = Array.prototype.slice.call(box.querySelectorAll('button')).filter(function (b) { return b.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
  var x0 = null;   // swipe on touch screens
  box.addEventListener('touchstart', function (e) { x0 = e.changedTouches[0].clientX; }, { passive: true });
  box.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 50) show(idx + (dx < 0 ? 1 : -1));
  });
});
// Gentle fade-up as sections scroll into view
document.addEventListener('DOMContentLoaded', function () {
  var els = document.querySelectorAll('.reveal');
  if (!('IntersectionObserver' in window)) { els.forEach(function (el) { el.classList.add('in'); }); return; }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
  }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
  els.forEach(function (el) { io.observe(el); });
});
// "View all prices" tiles: open the matching tab, then scroll to the rates section
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-tab]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var btn = document.getElementById(a.dataset.tab);
      if (btn && window.bootstrap) new bootstrap.Tab(btn).show();
      document.getElementById('rates').scrollIntoView({ behavior: 'smooth' });
    });
  });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
