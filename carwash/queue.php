<?php
require __DIR__ . '/includes/bootstrap.php';
$title = 'Live Queue';
require __DIR__ . '/includes/header.php';
?>
<div class="queue-page">
  <div class="q-head">
    <div>
      <h1>Live Queue &amp; Bay Monitor</h1>
      <p class="q-sub">Real-time bay status and today's waiting list.</p>
    </div>
    <span class="q-live"><span class="live-dot"></span> Live · updated <span id="q-updated">–</span></span>
  </div>

  <!-- Summary band -->
  <section class="q-summary" aria-label="Queue summary">
    <div class="q-metrics">
      <div class="q-metric"><span class="q-metric-ico"><?= icon('queue') ?></span><div><b id="q-length">–</b><small>Waiting today</small></div></div>
      <div class="q-metric"><span class="q-metric-ico"><?= icon('car') ?></span><div><b id="q-bays">–</b><small>Bays busy</small></div></div>
      <div class="q-metric"><span class="q-metric-ico"><?= icon('clock') ?></span><div><b><span id="q-wait">–</span><em>min</em></b><small>Est. wait</small></div></div>
    </div>
    <div class="q-occupancy">
      <div class="q-occ-label"><span>Bay occupancy</span><strong id="q-pct">0%</strong></div>
      <div class="progress" title="Bay occupancy"><div id="q-bar" class="progress-bar bg-success" style="width:0%"></div></div>
    </div>
  </section>

  <div class="q-title">Bays</div>
  <div class="q-bays" id="bays"></div>

  <div class="q-title">Waiting list</div>
  <div class="table-responsive card q-table mb-4">
    <table class="table align-middle mb-0">
      <thead><tr><th class="q-col-pos">#</th><th>Time</th><th>Plate</th><th>Package</th></tr></thead>
      <tbody id="waiting"></tbody>
    </table>
  </div>
</div>
<script>window.QUEUE_API = "<?= url('api/queue.php') ?>";</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
