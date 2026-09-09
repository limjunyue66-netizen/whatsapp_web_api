<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Templates', $user);
?>
<div class="panel">
  <p class="muted">Variables: <code>{{name}}</code> <code>{{phone}}</code> <code>{{company}}</code></p>
  <div id="list"></div>
</div>
<div class="panel">
  <h2>Create / edit</h2>
  <form id="tplForm" class="form-grid">
    <input type="hidden" name="id">
    <label>Name<input name="name" required></label>
    <label>Body<textarea name="body" required></textarea></label>
    <div class="row">
      <button class="btn" type="submit">Save</button>
      <button class="btn btn-ghost" type="button" id="previewBtn">Preview</button>
    </div>
  </form>
  <pre id="preview" class="token-box" style="white-space:pre-wrap"></pre>
</div>
<script>
async function load() {
  const data = await WABot.api('../api/admin/templates.php');
  const rows = data.data.items.map(t => `<tr>
    <td>${t.id}</td><td>${t.name}</td><td><pre style="white-space:pre-wrap;margin:0">${t.body}</pre></td>
    <td class="row">
      <button class="btn btn-ghost" data-edit='${JSON.stringify(t).replace(/'/g,"&#39;")}'>Edit</button>
      <button class="btn btn-danger" data-del="${t.id}">Delete</button>
    </td></tr>`).join('') || '<tr><td colspan="4" class="muted">None</td></tr>';
  document.getElementById('list').innerHTML = `<table><thead><tr><th>ID</th><th>Name</th><th>Body</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  document.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => {
    const t = JSON.parse(b.getAttribute('data-edit'));
    const f = document.getElementById('tplForm');
    f.id.value = t.id; f.name.value = t.name; f.body.value = t.body;
  }));
  document.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Delete?')) return;
    await WABot.api('../api/admin/templates.php', {method:'POST', body:{action:'delete', id:b.dataset.del}});
    load();
  }));
}
document.getElementById('tplForm').addEventListener('submit', async e => {
  e.preventDefault();
  const body = Object.fromEntries(new FormData(e.target).entries());
  body.action = body.id ? 'update' : 'create';
  await WABot.api('../api/admin/templates.php', {method:'POST', body});
  e.target.reset(); load();
});
document.getElementById('previewBtn').addEventListener('click', async () => {
  const body = document.getElementById('tplForm').body.value;
  const data = await WABot.api('../api/admin/templates.php?preview=1&body=' + encodeURIComponent(body));
  document.getElementById('preview').textContent = data.data.preview +
    (data.data.unsupported?.length ? '\n\nUnsupported: ' + data.data.unsupported.join(', ') : '');
});
load().catch(e => alert(e.message));
</script>
<?php admin_footer(); ?>
