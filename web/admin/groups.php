<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Groups', $user);
?>
<div class="panel">
  <h2>Groups</h2>
  <div id="groups"></div>
</div>
<div class="panel">
  <h2>Create group</h2>
  <form id="groupForm" class="form-grid">
    <label>Name<input name="name" required></label>
    <label>Description<input name="description"></label>
    <button class="btn" type="submit">Create</button>
  </form>
</div>
<div class="panel">
  <h2>Set members</h2>
  <form id="memberForm" class="form-grid">
    <label>Group ID<input name="id" type="number" required></label>
    <label>Contact IDs (comma-separated)<input name="contact_ids" placeholder="1,2,3"></label>
    <button class="btn" type="submit">Save members</button>
  </form>
  <div id="members" class="muted" style="margin-top:0.75rem"></div>
</div>
<script>
async function load(id) {
  const url = id ? '../api/admin/groups.php?id=' + id : '../api/admin/groups.php';
  const data = await WABot.api(url);
  const rows = data.data.groups.map(g => `<tr>
    <td>${g.id}</td><td>${g.name}</td><td>${g.member_count}</td>
    <td class="row">
      <button class="btn btn-ghost" data-view="${g.id}">View</button>
      <button class="btn btn-danger" data-del="${g.id}">Delete</button>
    </td></tr>`).join('') || '<tr><td colspan="4" class="muted">No groups</td></tr>';
  document.getElementById('groups').innerHTML = `<table><thead><tr><th>ID</th><th>Name</th><th>Members</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  document.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => load(b.dataset.view)));
  document.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Delete group?')) return;
    await WABot.api('../api/admin/groups.php', {method:'POST', body:{action:'delete', id:b.dataset.del}});
    load();
  }));
  if (data.data.members) {
    document.getElementById('members').innerHTML = data.data.members.map(m => `${m.id}: ${m.name} (${m.phone_e164})`).join('<br>') || 'No members';
  }
}
document.getElementById('groupForm').addEventListener('submit', async e => {
  e.preventDefault();
  const body = Object.fromEntries(new FormData(e.target).entries());
  body.action = 'create';
  await WABot.api('../api/admin/groups.php', {method:'POST', body});
  e.target.reset(); load();
});
document.getElementById('memberForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const ids = String(fd.get('contact_ids')||'').split(',').map(s=>parseInt(s.trim(),10)).filter(n=>n>0);
  await WABot.api('../api/admin/groups.php', {method:'POST', body:{action:'set_members', id:fd.get('id'), contact_ids: ids}});
  load(fd.get('id'));
});
load().catch(e => alert(e.message));
</script>
<?php admin_footer(); ?>
