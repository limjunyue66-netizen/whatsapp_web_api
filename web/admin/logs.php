<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Logs', $user);
?>
<div class="panel">
  <div class="row" style="margin-bottom:0.75rem">
    <button class="btn" id="msgBtn">Message logs</button>
    <button class="btn btn-ghost" id="auditBtn">Audit logs</button>
  </div>
  <div id="list"></div>
</div>
<script>
let type = 'message';
async function load() {
  const data = await WABot.api('../api/admin/logs.php?type=' + type);
  if (type === 'message') {
    const rows = data.data.items.map(r => `<tr>
      <td>${r.id}</td><td>${r.created_at}</td><td>${r.job_id||''}</td><td>${r.phone_e164||''}</td>
      <td>${r.event_type}</td><td>${WABot.badge(r.status)}</td><td>${r.detail||''}</td></tr>`).join('');
    document.getElementById('list').innerHTML = `<table><thead><tr><th>ID</th><th>UTC</th><th>Job</th><th>Phone</th><th>Event</th><th>Status</th><th>Detail</th></tr></thead><tbody>${rows}</tbody></table>`;
  } else {
    const rows = data.data.items.map(r => `<tr>
      <td>${r.id}</td><td>${r.created_at}</td><td>${r.user_id||''}</td><td>${r.worker_id||''}</td>
      <td>${r.action}</td><td>${r.entity_type} ${r.entity_id||''}</td><td>${r.ip_address||''}</td></tr>`).join('');
    document.getElementById('list').innerHTML = `<table><thead><tr><th>ID</th><th>UTC</th><th>User</th><th>Worker</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead><tbody>${rows}</tbody></table>`;
  }
}
document.getElementById('msgBtn').addEventListener('click', () => { type='message'; load(); });
document.getElementById('auditBtn').addEventListener('click', () => { type='audit'; load(); });
load().catch(e => alert(e.message));
</script>
<?php admin_footer(); ?>
