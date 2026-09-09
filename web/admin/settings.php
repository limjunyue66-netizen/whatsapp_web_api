<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Settings', $user);
?>
<div class="panel">
  <p class="muted">Rate limits do not guarantee WhatsApp account safety. Timezone for display/input: Asia/Kuala_Lumpur. DB stores UTC.</p>
  <div id="alert" class="alert"></div>
  <form id="setForm" class="form-grid"></form>
</div>
<script>
async function load() {
  const data = await WABot.api('../api/admin/settings.php');
  const editable = data.data.editable;
  const s = data.data.settings;
  const form = document.getElementById('setForm');
  form.innerHTML = editable.map(k => `<label>${k}<input name="${k}" value="${(s[k]??'').toString().replace(/"/g,'&quot;')}"></label>`).join('')
    + '<button class="btn" type="submit">Save</button>';
}
document.getElementById('setForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const settings = Object.fromEntries(fd.entries());
  try {
    await WABot.api('../api/admin/settings.php', {method:'POST', body:{settings}});
    const a = document.getElementById('alert');
    a.className = 'alert success show';
    a.textContent = 'Saved';
  } catch (err) {
    const a = document.getElementById('alert');
    a.className = 'alert error show';
    a.textContent = err.message;
  }
});
load().catch(e => alert(e.message));
</script>
<?php admin_footer(); ?>
