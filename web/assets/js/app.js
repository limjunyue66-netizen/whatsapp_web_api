(function () {
  function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  async function api(url, options = {}) {
    const csrf = getCsrf();
    const opts = { credentials: 'same-origin', ...options };
    opts.headers = opts.headers || {};
    if (!(opts.body instanceof FormData)) {
      opts.headers['Content-Type'] = opts.headers['Content-Type'] || 'application/json';
    }
    if (opts.method && opts.method.toUpperCase() !== 'GET') {
      opts.headers['X-CSRF-Token'] = csrf;
      if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
        if (!opts.body._csrf) opts.body._csrf = csrf;
        opts.body = JSON.stringify(opts.body);
      }
    }
    const res = await fetch(url, opts);
    const data = await res.json().catch(() => ({ success: false, message: 'Invalid JSON', data: null }));
    if (!res.ok || !data.success) {
      const err = new Error(data.message || 'Request failed');
      err.payload = data;
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function badge(status) {
    const s = String(status || '').toLowerCase();
    let cls = 'badge';
    if (['connected', 'online', 'sent', 'completed', 'ok'].includes(s)) cls += ' ok';
    else if (['failed', 'error', 'offline', 'cancelled', 'disconnected'].includes(s)) cls += ' bad';
    else cls += ' warn';
    return `<span class="${cls}">${s}</span>`;
  }

  window.WABot = {
    api,
    badge,
    get csrf() { return getCsrf(); },
  };

  document.addEventListener('DOMContentLoaded', () => {
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', async () => {
        try {
          await api('../api/admin/logout.php', { method: 'POST', body: {} });
        } catch (e) {}
        location.href = 'login.php';
      });
    }
  });
})();
