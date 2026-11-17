// ---- Toasts: show any flash messages
document.querySelectorAll('.toast').forEach(el => new bootstrap.Toast(el).show());

// ---- Confirm modal for any element with data-confirm (form or submit button)
(function () {
  const modalEl = document.getElementById('confirmModal');
  if (!modalEl) return;
  const modal = new bootstrap.Modal(modalEl);
  const text = document.getElementById('confirmText');
  let pending = null; // {form, submitter}

  document.addEventListener('click', e => {
    const btn = e.target.closest('button[data-confirm]');
    if (!btn || !btn.form) return;
    e.preventDefault();
    pending = { form: btn.form, submitter: btn };
    text.textContent = btn.dataset.confirm;
    modal.show();
  });
  document.addEventListener('submit', e => {
    const form = e.target;
    if (form.dataset.confirm && !form.dataset.confirmed) {
      e.preventDefault();
      pending = { form, submitter: e.submitter || null };
      text.textContent = form.dataset.confirm;
      modal.show();
    }
  });
  document.getElementById('confirmYes').addEventListener('click', () => {
    if (!pending) return;
    const { form, submitter } = pending;
    form.dataset.confirmed = '1';
    modal.hide();
    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
  });
})();

// ---- Booking form: vehicle size -> services, slot buttons, live summary
(function () {
  const vehicle = document.getElementById('vehicle');
  const pkg = document.getElementById('package');
  const date = document.getElementById('date');
  const slots = document.getElementById('slots');
  const time = document.getElementById('time');
  const form = document.getElementById('book-form');
  const summary = document.getElementById('summary');
  if (!vehicle || !pkg || !date || !slots || !time || !window.AVAILABILITY_API || !window.CATALOG) return;

  const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
  const pct = Number(window.PROMO_PCT || 0);

  // Rebuild the service list for the chosen vehicle type. The exact size (and price) is set by the shop from the customer's photos,
  // so each service shows its price range.
  const range = p => p.min === null ? '' : (p.min === p.max ? peso(p.min) : `${peso(p.min)} – ${peso(p.max)}`);
  function fillPackages() {
    const cat = vehicle.value;
    const prev = pkg.value || window.WANT_PACKAGE || '';
    pkg.innerHTML = '';
    window.CATALOG.filter(p => p.category === cat && p.min !== null).forEach(p => {
      const o = document.createElement('option');
      o.value = p.id;
      o.textContent = `${p.name} — ${p.starting ? 'from ' + peso(p.min) : range(p)} · ${p.duration} min`;
      o.dataset.name = p.name;
      o.dataset.min = p.min;
      o.dataset.max = p.max;
      o.dataset.duration = p.duration;
      o.dataset.starting = p.starting ? '1' : '';
      pkg.appendChild(o);
    });
    if ([...pkg.options].some(o => o.value === prev)) pkg.value = prev;
  }

  function updateSummary() {
    const opt = pkg.options[pkg.selectedIndex];
    if (!opt) { summary.textContent = 'No services available for this vehicle type.'; return; }
    const f = 1 - pct / 100, lo = parseFloat(opt.dataset.min) * f, hi = parseFloat(opt.dataset.max) * f;
    const price = lo === hi ? peso(lo) : `${peso(lo)} – ${peso(hi)}`;
    summary.innerHTML = `<strong>${opt.dataset.name}</strong> · ${vehicle.options[vehicle.selectedIndex].text} · ${opt.dataset.duration} min` +
      (time.value ? ` · starts ${time.dataset.label}` : '') +
      `<br><span class="price">${opt.dataset.starting ? 'from ' + peso(lo) : price}</span>` +
      (pct ? ` <span class="badge bg-warning text-dark">${pct}% promo applied</span>` : '') +
      '<br><small class="text-muted">The shop checks your photos and sets your vehicle size, which decides the final price.</small>';
  }

  async function loadSlots() {
    time.value = ''; time.dataset.label = '';
    updateSummary();
    if (!pkg.value) { slots.innerHTML = '<span class="text-muted">Choose a service to see available times.</span>'; return; }
    slots.innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"></div> <span class="text-muted small">Checking availability…</span>';
    try {
      const res = await fetch(`${window.AVAILABILITY_API}?package_id=${pkg.value}&date=${date.value}`);
      if (!res.ok) throw new Error('bad response');
      const list = await res.json();
      if (!list.length) { slots.innerHTML = '<span class="text-muted">No slots for this date.</span>'; return; }
      slots.innerHTML = '';
      list.forEach(s => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-primary btn-sm slot-btn';
        b.textContent = s.label;
        b.disabled = !s.available;
        if (!s.available) b.title = 'Not available';
        b.addEventListener('click', () => {
          slots.querySelectorAll('.slot-btn').forEach(x => x.classList.remove('active'));
          b.classList.add('active');
          time.value = s.time; time.dataset.label = s.label;
          updateSummary();
        });
        slots.appendChild(b);
      });
      if (window.WANT_TIME) {   // the form came back with an error: re-select the time the customer had chosen
        const want = [...slots.querySelectorAll('.slot-btn')].find((b, i) => list[i].time === window.WANT_TIME && !b.disabled);
        window.WANT_TIME = '';
        if (want) want.click();
      }
      if (!list.some(s => s.available)) slots.insertAdjacentHTML('beforeend', '<div class="w-100 text-danger small mt-1">Fully booked — try another date.</div>');
    } catch (e) {
      slots.innerHTML = '<span class="text-danger">Could not load slots. Refresh and try again.</span>';
    }
  }

  form.addEventListener('submit', e => {
    if (!time.value) {
      e.preventDefault();
      slots.classList.add('border', 'border-danger', 'rounded', 'p-2');
      slots.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
  vehicle.addEventListener('change', () => { fillPackages(); loadSlots(); });
  pkg.addEventListener('change', loadSlots);
  date.addEventListener('change', loadSlots);
  fillPackages();
  loadSlots();
})();

// ---- Live queue: poll every 10 seconds
(function () {
  if (!window.QUEUE_API) return;
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function refresh() {
    try {
      const d = await (await fetch(window.QUEUE_API)).json();
      document.getElementById('q-length').textContent = d.queue_length;
      document.getElementById('q-bays').textContent = `${d.busy_bays}/${d.total_bays}`;
      document.getElementById('q-wait').textContent = d.est_wait_min;
      const pct = d.total_bays ? Math.round(d.busy_bays / d.total_bays * 100) : 0;
      const bar = document.getElementById('q-bar');
      bar.style.width = pct + '%';
      bar.className = 'progress-bar ' + (pct >= 100 ? 'bg-danger' : pct >= 60 ? 'bg-warning' : 'bg-success');
      const pctEl = document.getElementById('q-pct');
      if (pctEl) pctEl.textContent = pct + '%';
      const carSvg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 17H3v-5l2-5h14l2 5v5h-2"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/><path d="M3 12h18"/></svg>';
      document.getElementById('bays').innerHTML = d.bays.map(b => {
        const busy = !!b.occupied_by;
        const parts = busy ? String(b.occupied_by).split(' · ') : [];
        const detail = busy
          ? (parts.length > 1 ? `<strong>${esc(parts[0])}</strong><span>${esc(parts.slice(1).join(' · '))}</span>` : `<strong>${esc(parts[0])}</strong>`)
          : '<span>Ready for the next vehicle</span>';
        return `<div class="q-bay ${busy ? 'busy' : 'free'}">` +
          `<div class="q-bay-top"><span class="q-bay-ico">${carSvg}</span><span class="q-badge">${busy ? 'In service' : 'Available'}</span></div>` +
          `<div class="q-bay-name">${esc(b.name)}</div><div class="q-bay-detail">${detail}</div></div>`;
      }).join('');
      document.getElementById('waiting').innerHTML = d.waiting.length
        ? d.waiting.map((w, i) => `<tr><td class="q-col-pos"><span class="q-pos">${i + 1}</span></td><td>${esc(w.scheduled_at.slice(11, 16))}</td><td class="q-plate">${esc(w.vehicle_plate)}</td><td>${esc(w.package)}</td></tr>`).join('')
        : '<tr class="q-empty"><td colspan="4"><div class="q-empty-box"><strong>No one waiting</strong><span>New bookings for today will appear here.</span></div></td></tr>';
      document.getElementById('q-updated').textContent = new Date().toLocaleTimeString();
    } catch (e) { /* keep last view */ }
  }
  refresh();
  setInterval(refresh, 10000);
})();

// ---- Sidebar toggle (mobile): open/close the off-canvas menu
(function () {
  const btn = document.getElementById('sidebarToggle');
  if (!btn) return;
  const close = () => { document.body.classList.remove('sidebar-open'); btn.setAttribute('aria-expanded', 'false'); };
  btn.addEventListener('click', () => {
    const open = document.body.classList.toggle('sidebar-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  const back = document.getElementById('sidebarBackdrop');
  if (back) back.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  document.querySelectorAll('.side-link').forEach(a => a.addEventListener('click', close));
})();


// ---- Login <-> Sign up: one slide per click (panel and form trade places), then open the page
(function () {
  const page = document.querySelector('.auth-page');
  if (!page) return;
  const side = page.querySelector('.auth-side'), main = page.querySelector('.auth-main');
  if (!side || !main) return;
  const canAnimate = () => side.offsetWidth > 0 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Leaving: the panel and the form trade places
  const link = page.querySelector('[data-auth-swap]');
  if (link) link.addEventListener('click', function (e) {
    if (!canAnimate() || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    const toRegister = !page.classList.contains('is-register');   // login -> register: panel goes left
    const dir = toRegister ? -1 : 1;
    page.classList.add('swapping');
    side.style.transform = 'translateX(' + dir * main.offsetWidth + 'px)';
    main.style.transform = 'translateX(' + (-dir) * side.offsetWidth + 'px)';
    setTimeout(() => { window.location.href = link.href; }, 620);
  });
  // The new page already opens in the swapped layout, so it needs no second animation.
})();

// ---- Stop double-submits on payment forms (a second click would try to complete the booking twice)
document.addEventListener('submit', e => {
  const f = e.target;
  if (!f.classList || !f.classList.contains('pay-form')) return;
  if (f.dataset.busy) { e.preventDefault(); return; }
  f.dataset.busy = '1';
  setTimeout(() => { delete f.dataset.busy; }, 8000);
});
