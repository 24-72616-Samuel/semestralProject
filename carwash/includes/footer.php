</main>

<?php if (empty($authPage) && !current_user()): /* footer only for guests; hidden for admin, owner, staff and customers */ ?>
<footer class="footer site-footer">
  <div class="container py-4">
    <div class="row gy-3">
      <div class="col-md-5">
        <div class="brand mb-2"><img class="brand-logo" src="<?= url('assets/img/logo.png') ?>" alt="">EAYILA <span class="text-red">CARWASH</span></div>
        <p class="mb-0 small"><?= e(SHOP_NAME) ?> — book online, follow the live queue and get a digital receipt.</p>
      </div>
      <div class="col-md-4 small">
        <div class="footer-h">Contact</div>
        <div><?= icon('pin') ?> <?= e(SHOP_ADDRESS) ?></div>
        <div><?= icon('phone') ?> <?= e(SHOP_PHONE) ?></div>
        <div><?= icon('mail') ?> <?= e(SHOP_EMAIL) ?></div>
      </div>
      <div class="col-md-3 small">
        <div class="footer-h">Opening hours</div>
        <div><?= icon('clock') ?> Daily<br><?= e(hours_label()) ?></div>
      </div>
    </div>
    <hr class="border-secondary my-3">
    <div class="small text-center">© <?= date('Y') ?> <?= e(SHOP_NAME) ?> · IT 314 Project</div>
  </div>
</footer>
<?php endif; ?>
<?php if (current_user()): /* close .app-main and .app-shell opened in header.php */ ?>
  </div><!-- /.app-main -->
</div><!-- /.app-shell -->
<?php endif; ?>

<!-- Shared confirm modal (used by any element with data-confirm) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Please confirm</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="confirmText">Are you sure?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No, go back</button>
        <button type="button" class="btn btn-danger" id="confirmYes">Yes, continue</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
