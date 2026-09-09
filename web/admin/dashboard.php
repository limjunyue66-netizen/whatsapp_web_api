<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Dashboard', $user);
?>
<div class="grid" id="stats"></div>
<div class="panel">
  <h2>Workers</h2>
  <div id="workers"></div>
</div>
<div class="panel">
  <h2>Active campaigns</h2>
  <div id="campaigns"></div>
</div>
<div class="panel">
  <h2>Recent activity</h2>
  <div id="activity"></div>
</div>
<script>
async function load() {
  const data = await WABot.api('../api/admin/dashboard.php');
  const s = data.data.stats;
  document.getElementById('stats').innerHTML = `
    <div class="stat"><div class="label">Queue</div><div class="value">${s.queue}</div></div>
    <div class="stat"><div class="label">Processing</div><div class="value">${s.processing}</div></div>
    <div class="stat"><div class="label">Sent (24h)</div><div class="value">${s.sent_today}</div></div>
    <div class="stat"><div class="label">Failed (24h)</div><div class="value">${s.failed_today}</div></div>`;
  const wrows = (data.data.workers || []).map(w => `<tr>
    <td>${w.name}</td><td>${WABot.badge(w.status)}</td><td>${WABot.badge(w.whatsapp_status)}</td>
    <td>${w.last_heartbeat_at || '-'}</td><td>${w.current_job_id || '-'}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">No workers</td></tr>';
  document.getElementById('workers').innerHTML = `<table><thead><tr><th>Name</th><th>Status</th><th>WhatsApp</th><th>Heartbeat (UTC)</th><th>Job</th></tr></thead><tbody>${wrows}</tbody></table>`;
  const crows = (data.data.active_campaigns || []).map(c => `<tr>
    <td>${c.name}</td><td>${WABot.badge(c.status)}</td>
    <td>${c.sent_count}/${c.total_count}</td><td>fail ${c.failed_count}</td></tr>`).join('') || '<tr><td class="muted" colspan="4">None</td></tr>';
  document.getElementById('campaigns').innerHTML = `<table><thead><tr><th>Name</th><th>Status</th><th>Progress</th><th>Failed</th></tr></thead><tbody>${crows}</tbody></table>`;
  const arows = (data.data.recent_activity || []).map(a => `<tr>
    <td>${a.created_at}</td><td>${a.event_type}</td><td>${a.phone_e164||''}</td>
    <td>${WABot.badge(a.status)}</td><td>${a.detail||''}</td></tr>`).join('') || '<tr><td class="muted" colspan="5">No activity</td></tr>';
  document.getElementById('activity').innerHTML = `<table><thead><tr><th>UTC</th><th>Event</th><th>Phone</th><th>Status</th><th>Detail</th></tr></thead><tbody>${arows}</tbody></table>`;
}
load().catch(e => alert(e.message));
setInterval(() => load().catch(()=>{}), 15000);
</script>
<?php admin_footer(); ?>
