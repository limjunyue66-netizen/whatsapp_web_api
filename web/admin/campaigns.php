<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Campaigns', $user);
?>
<div class="panel">
  <h2>Create campaign</h2>
  <div id="alert" class="alert"></div>
  <form id="campForm" class="form-grid">
    <label>Name<input name="name" required></label>
    <label>Template ID (optional)<input name="template_id" type="number"></label>
    <label>Message<textarea name="message_body" placeholder="Used if no template / overrides blank"></textarea></label>
    <label>Contact IDs (comma)<input name="contact_ids" placeholder="1,2,3"></label>
    <label>Group IDs (comma)<input name="group_ids" placeholder="1"></label>
    <label>Media ID<input name="media_id" type="number"></label>
    <label>Schedule (Asia/Kuala_Lumpur, optional)<input name="scheduled_at" placeholder="YYYY-MM-DD HH:MM:SS"></label>
    <label><input type="checkbox" name="confirm_large" value="1"> Confirm large campaign</label>
    <button class="btn" type="submit">Queue campaign</button>
  </form>
</div>
<div class="panel">
  <h2>Campaigns</h2>
  <div id="list"></div>
</div>
<div class="panel">
  <h2>Campaign detail</h2>
  <div id="detail" class="muted">Select a campaign</div>
</div>
<script>
async function load() {
  const data = await WABot.api('../api/admin/campaigns.php');
  const rows = data.data.items.map(c => `<tr>
    <td>${c.id}</td><td>${c.name}</td><td>${WABot.badge(c.status)}</td>
    <td>${c.sent_count}/${c.total_count}</td><td>${c.failed_count}</td><td>${c.pending_count}</td>
    <td class="row">
      <button class="btn btn-ghost" data-view="${c.id}">View</button>
      <button class="btn btn-warn" data-pause="${c.id}">Pause</button>
      <button class="btn btn-ghost" data-resume="${c.id}">Resume</button>
      <button class="btn btn-danger" data-cancel="${c.id}">Cancel</button>
    </td></tr>`).join('') || '<tr><td colspan="7" class="muted">None</td></tr>';
  document.getElementById('list').innerHTML = `<table><thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Sent</th><th>Fail</th><th>Pending</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  document.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => view(b.dataset.view)));
  document.querySelectorAll('[data-pause]').forEach(b => b.addEventListener('click', async () => {
    await WABot.api('../api/admin/campaigns.php', {method:'POST', body:{action:'pause', id:b.dataset.pause}}); load();
  }));
  document.querySelectorAll('[data-resume]').forEach(b => b.addEventListener('click', async () => {
    await WABot.api('../api/admin/campaigns.php', {method:'POST', body:{action:'resume', id:b.dataset.resume}}); load();
  }));
  document.querySelectorAll('[data-cancel]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Cancel campaign?')) return;
    await WABot.api('../api/admin/campaigns.php', {method:'POST', body:{action:'cancel', id:b.dataset.cancel}}); load();
  }));
}
async function view(id) {
  const data = await WABot.api('../api/admin/campaigns.php?id=' + id);
  const c = data.data.campaign;
  const jobs = data.data.jobs.map(j => `<tr><td>${j.id}</td><td>${j.phone_e164}</td><td>${WABot.badge(j.status)}</td><td>${j.attempts}</td><td>${j.last_error||''}</td></tr>`).join('');
  document.getElementById('detail').innerHTML = `<p><strong>${c.name}</strong> ${WABot.badge(c.status)} — sent ${c.sent_count}/${c.total_count}</p>
    <table><thead><tr><th>Job</th><th>Phone</th><th>Status</th><th>Attempts</th><th>Error</th></tr></thead><tbody>${jobs}</tbody></table>`;
}
document.getElementById('campForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const body = {
    action: 'create',
    name: fd.get('name'),
    message_body: fd.get('message_body') || '',
    template_id: fd.get('template_id') || null,
    media_id: fd.get('media_id') || null,
    scheduled_at: fd.get('scheduled_at') || null,
    confirm_large: !!fd.get('confirm_large'),
    contact_ids: String(fd.get('contact_ids')||'').split(',').map(s=>parseInt(s.trim(),10)).filter(n=>n>0),
    group_ids: String(fd.get('group_ids')||'').split(',').map(s=>parseInt(s.trim(),10)).filter(n=>n>0),
  };
  const a = document.getElementById('alert');
  try {
    const res = await WABot.api('../api/admin/campaigns.php', {method:'POST', body});
    a.className = 'alert success show';
    a.textContent = 'Created campaign #' + res.data.campaign_id;
    e.target.reset(); load();
  } catch (err) {
    a.className = 'alert error show';
    a.textContent = err.message + (err.payload?.data?.requires_confirmation ? ' — check confirm large' : '');
  }
});
load().catch(e => alert(e.message));
</script>
<?php admin_footer(); ?>
