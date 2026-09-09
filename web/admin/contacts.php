<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Contacts', $user);
?>
<div class="panel">
  <div class="row" style="justify-content:space-between;margin-bottom:0.75rem">
    <form id="searchForm" class="row">
      <input name="q" placeholder="Search name/phone/company" style="min-width:220px">
      <button class="btn" type="submit">Search</button>
    </form>
    <div class="row">
      <a class="btn btn-ghost" href="../api/admin/export_contacts.php">Export CSV</a>
      <label class="btn btn-ghost" style="cursor:pointer">Import CSV<input type="file" id="csvFile" accept=".csv,text/csv" hidden></label>
    </div>
  </div>
  <div id="alert" class="alert"></div>
  <div id="list"></div>
</div>
<div class="panel">
  <h2>Add / edit contact</h2>
  <form id="contactForm" class="form-grid">
    <input type="hidden" name="id">
    <label>Name<input name="name" required></label>
    <label>Phone<input name="phone" required></label>
    <label>Company<input name="company"></label>
    <label>Notes<textarea name="notes"></textarea></label>
    <label>Active<select name="is_active"><option value="1">Yes</option><option value="0">No</option></select></label>
    <div class="row">
      <button class="btn" type="submit">Save</button>
      <button class="btn btn-ghost" type="button" id="resetBtn">Reset</button>
    </div>
  </form>
</div>
<script>
let q = '';
async function load() {
  const data = await WABot.api('../api/admin/contacts.php?q=' + encodeURIComponent(q));
  const rows = data.data.items.map(c => `<tr>
    <td>${c.id}</td><td>${c.name}</td><td>${c.phone_e164}</td><td>${c.company||''}</td>
    <td>${c.is_active==1?'Yes':'No'}</td>
    <td class="row">
      <button class="btn btn-ghost" data-edit='${JSON.stringify(c).replace(/'/g,"&#39;")}'>Edit</button>
      <button class="btn btn-danger" data-del="${c.id}">Delete</button>
    </td></tr>`).join('') || '<tr><td colspan="6" class="muted">No contacts</td></tr>';
  document.getElementById('list').innerHTML = `<table><thead><tr><th>ID</th><th>Name</th><th>Phone</th><th>Company</th><th>Active</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  document.querySelectorAll('[data-edit]').forEach(btn => btn.addEventListener('click', () => {
    const c = JSON.parse(btn.getAttribute('data-edit'));
    const f = document.getElementById('contactForm');
    f.id.value = c.id; f.name.value = c.name; f.phone.value = c.phone_e164;
    f.company.value = c.company||''; f.notes.value = c.notes||''; f.is_active.value = c.is_active;
  }));
  document.querySelectorAll('[data-del]').forEach(btn => btn.addEventListener('click', async () => {
    if (!confirm('Delete contact?')) return;
    await WABot.api('../api/admin/contacts.php', {method:'POST', body:{action:'delete', id: btn.dataset.del}});
    load();
  }));
}
document.getElementById('searchForm').addEventListener('submit', e => { e.preventDefault(); q = new FormData(e.target).get('q'); load(); });
document.getElementById('resetBtn').addEventListener('click', () => document.getElementById('contactForm').reset());
document.getElementById('contactForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const body = Object.fromEntries(fd.entries());
  body.action = body.id ? 'update' : 'create';
  await WABot.api('../api/admin/contacts.php', {method:'POST', body});
  e.target.reset();
  load();
});
document.getElementById('csvFile').addEventListener('change', async e => {
  const file = e.target.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  fd.append('action', 'import_csv');
  fd.append('_csrf', WABot.csrf);
  const res = await fetch('../api/admin/contacts.php', {method:'POST', body: fd, credentials:'same-origin', headers:{'X-CSRF-Token': WABot.csrf}});
  const data = await res.json();
  const a = document.getElementById('alert');
  a.className = data.success ? 'alert success show' : 'alert error show';
  a.textContent = data.message + (data.data ? ` imported=${data.data.imported}` : '');
  load();
});
load().catch(e => alert(e.message));
</script>
<?php admin_footer(); ?>
