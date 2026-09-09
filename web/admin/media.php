<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Media', $user);
?>
<div class="panel">
  <h2>Upload</h2>
  <form id="upForm" class="row">
    <input type="file" name="file" required>
    <button class="btn" type="submit">Upload</button>
  </form>
  <div id="alert" class="alert"></div>
</div>
<div class="panel">
  <h2>Files</h2>
  <div id="list"></div>
</div>
<script>
async function load() {
  const data = await WABot.api('../api/admin/media.php');
  const rows = data.data.items.map(m => `<tr>
    <td>${m.id}</td><td>${m.original_name}</td><td>${m.mime_type}</td><td>${m.size_bytes}</td>
    <td class="row">
      <a class="btn btn-ghost" href="../api/admin/media.php?download=${m.id}">Download</a>
      <button class="btn btn-danger" data-del="${m.id}">Delete</button>
    </td></tr>`).join('') || '<tr><td colspan="5" class="muted">None</td></tr>';
  document.getElementById('list').innerHTML = `<table><thead><tr><th>ID</th><th>Name</th><th>MIME</th><th>Size</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  document.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Delete?')) return;
    await WABot.api('../api/admin/media.php', {method:'POST', body:{action:'delete', id:b.dataset.del}});
    load();
  }));
}
document.getElementById('upForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'upload');
  fd.append('_csrf', WABot.csrf);
  const res = await fetch('../api/admin/media.php', {method:'POST', body:fd, credentials:'same-origin', headers:{'X-CSRF-Token': WABot.csrf}});
  const data = await res.json();
  const a = document.getElementById('alert');
  a.className = data.success ? 'alert success show' : 'alert error show';
  a.textContent = data.success ? ('Uploaded id=' + data.data.id) : data.message;
  e.target.reset(); load();
});
load().catch(e => alert(e.message));
</script>
<?php admin_footer(); ?>
