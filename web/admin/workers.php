<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Workers', $user);
?>
<div class="panel">
  <h2>Register worker</h2>
  <form id="regForm" class="row">
    <input name="name" placeholder="Worker name" required>
    <button class="btn" type="submit">Register</button>
  </form>
  <div id="tokenAlert" class="alert success"></div>
  <div id="tokenBox" class="token-box" style="display:none"></div>
</div>
<div class="panel">
  <h2>Workers</h2>
  <div id="list"></div>
</div>
<script>
async function load() {
  const data = await WABot.api('../api/admin/workers.php');
  const rows = data.data.items.map(w => `<tr>
    <td>${w.id}</td><td>${w.name}</td><td>${w.token_hint}</td>
    <td>${w.is_enabled==1?'Yes':'No'}</td>
    <td>${WABot.badge(w.status)}</td><td>${WABot.badge(w.whatsapp_status)}</td>
    <td>${w.last_heartbeat_at||'-'}</td><td>${w.worker_version||'-'}</td>
    <td class="row">
      <button class="btn btn-ghost" data-en="${w.id}" data-v="${w.is_enabled==1?0:1}">${w.is_enabled==1?'Disable':'Enable'}</button>
      <button class="btn btn-warn" data-regen="${w.id}">Regen token</button>
      <button class="btn btn-danger" data-del="${w.id}">Delete</button>
    </td></tr>`).join('') || '<tr><td colspan="9" class="muted">No workers</td></tr>';
  document.getElementById('list').innerHTML = `<table><thead><tr><th>ID</th><th>Name</th><th>Hint</th><th>Enabled</th><th>Status</th><th>WA</th><th>Heartbeat</th><th>Ver</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  document.querySelectorAll('[data-en]').forEach(b => b.addEventListener('click', async () => {
    await WABot.api('../api/admin/workers.php', {method:'POST', body:{action:'set_enabled', id:b.dataset.en, is_enabled: b.dataset.v==='1'}});
    load();
  }));
  document.querySelectorAll('[data-regen]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Regenerate token? Old token stops working immediately.')) return;
    const res = await WABot.api('../api/admin/workers.php', {method:'POST', body:{action:'regenerate_token', id:b.dataset.regen}});
    showToken(res.data.token);
    load();
  }));
  document.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Delete worker?')) return;
    await WABot.api('../api/admin/workers.php', {method:'POST', body:{action:'delete', id:b.dataset.del}});
    load();
  }));
}
function showToken(token) {
  const a = document.getElementById('tokenAlert');
  a.className = 'alert success show';
  a.textContent = 'Copy this token now — it will not be shown again.';
  const box = document.getElementById('tokenBox');
  box.style.display = 'block';
  box.textContent = token;
}
document.getElementById('regForm').addEventListener('submit', async e => {
  e.preventDefault();
  const name = new FormData(e.target).get('name');
  const res = await WABot.api('../api/admin/workers.php', {method:'POST', body:{action:'register', name}});
  showToken(res.data.token);
  e.target.reset();
  load();
});
load().catch(e => alert(e.message));
setInterval(() => load().catch(()=>{}), 20000);
</script>
<?php admin_footer(); ?>
