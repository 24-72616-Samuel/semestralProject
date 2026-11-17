// Live chat box: sends without reloading and checks for new messages every few seconds.
(function () {
  const box = document.getElementById('chat');
  if (!box) return;
  const log = document.getElementById('chatLog'), form = document.getElementById('chatForm'), body = document.getElementById('chatBody');
  const api = box.dataset.api, customer = box.dataset.customer;
  let last = parseInt(box.dataset.last || '0', 10), busy = false;

  const toBottom = () => { log.scrollTop = log.scrollHeight; };
  toBottom();

  function add(m) {
    const empty = document.getElementById('chatEmpty'); if (empty) empty.remove();
    const wrap = document.createElement('div'); wrap.className = 'chat-msg ' + (m.mine ? 'mine' : 'theirs');
    const meta = document.createElement('div'); meta.className = 'chat-meta'; meta.textContent = m.meta;
    const b = document.createElement('div'); b.className = 'chat-bubble'; b.textContent = m.body;   // textContent: message text can never run as HTML
    wrap.append(meta, b); log.appendChild(wrap);
    last = Math.max(last, m.id);
  }

  async function poll() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const res = await fetch(`${api}?customer=${encodeURIComponent(customer)}&after=${last}`, { headers: { 'X-Requested-With': 'fetch' } });
      if (res.ok) {
        const list = await res.json();
        if (list.length) {
          const atEnd = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
          list.forEach(add);
          if (atEnd) toBottom();
        }
      }
    } catch (e) { /* offline: try again next tick */ }
    busy = false;
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const text = body.value.trim();
    if (!text) return;
    const btn = form.querySelector('button'); btn.disabled = true;
    try {
      const res = await fetch(location.href, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } });
      if (res.ok) { body.value = ''; await poll(); toBottom(); }
      else { const j = await res.json().catch(() => ({})); alert(j.error || 'Could not send. Please try again.'); }
    } catch (err) { alert('Could not send. Check your connection.'); }
    btn.disabled = false; body.focus();
  });
  // Enter sends, Shift+Enter makes a new line.
  body.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

  setInterval(poll, 5000);
  document.addEventListener('visibilitychange', poll);
})();
