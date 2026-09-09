<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
$user = require_login_page();
admin_header('Send Message', $user);
?>
<div class="panel">
  <h2>Send now</h2>
  <p class="muted">
    <strong>Phone is required and is the only destination.</strong>
    With Media ID: one WhatsApp bubble = image on top + your Message as caption underneath (not sticker, not two separate messages).
    Keep <code>3_START_WORKER.bat</code> running — do not click Send inside WhatsApp yourself.
  </p>
  <div id="alert" class="alert"></div>
  <form id="sendForm" class="form-grid">
    <label>Contact ID (optional, fill name only)<input name="contact_id" type="number" min="1" placeholder="Optional"></label>
    <label>Phone (required)<input name="phone" required placeholder="e.g. 0123456789 or 60123456789"></label>
    <label>Name<input name="name" value="Recipient"></label>
    <label>Company<input name="company"></label>
    <label>Message<textarea name="message_body" required placeholder="Hello {{name}}, ..."></textarea></label>
    <label>Media ID (optional)<input name="media_id" type="number" min="1"></label>
    <div class="row">
      <button class="btn" type="submit">Send message</button>
      <button class="btn btn-ghost" type="button" id="clearDraftBtn">Clear draft</button>
    </div>
  </form>
</div>
<script>
const DRAFT_KEY = 'wa_bot_send_draft_v1';
const form = document.getElementById('sendForm');
const alertBox = document.getElementById('alert');
const fields = ['contact_id', 'phone', 'name', 'company', 'message_body', 'media_id'];

function saveDraft() {
  const data = {};
  fields.forEach((name) => {
    const el = form.elements.namedItem(name);
    if (el) data[name] = el.value;
  });
  localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
}

function loadDraft() {
  try {
    const raw = localStorage.getItem(DRAFT_KEY);
    if (!raw) return;
    const data = JSON.parse(raw);
    fields.forEach((name) => {
      const el = form.elements.namedItem(name);
      if (el && data[name] != null && data[name] !== '') el.value = data[name];
    });
  } catch (e) {}
}

function clearDraft() {
  localStorage.removeItem(DRAFT_KEY);
  form.reset();
  form.elements.namedItem('name').value = 'Recipient';
}

loadDraft();
form.addEventListener('input', saveDraft);
form.addEventListener('change', saveDraft);

document.getElementById('clearDraftBtn').addEventListener('click', () => {
  clearDraft();
  alertBox.className = 'alert success show';
  alertBox.textContent = 'Draft cleared';
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  alertBox.className = 'alert';
  const fd = new FormData(form);
  const body = Object.fromEntries(fd.entries());
  const phone = String(body.phone || '').trim();
  if (!phone) {
    alertBox.textContent = 'Phone is required';
    alertBox.className = 'alert error show';
    return;
  }
  const digits = phone.replace(/\D+/g, '');
  if (!confirm('Send WhatsApp message to this number?\n\n' + phone + '\n(' + digits + ')\n\nWorker will send automatically — you do not need to open WhatsApp.')) {
    return;
  }
  ['contact_id','media_id'].forEach(k => { if (!body[k]) delete body[k]; });
  const btn = form.querySelector('button[type="submit"]');
  if (btn) btn.disabled = true;
  try {
    const res = await WABot.api('../api/admin/send.php', { method: 'POST', body });
    alertBox.textContent = 'Sending to ' + (res.data.phone_e164 || '') + ' via worker (campaign #' + res.data.campaign_id + '). Keep 3_START_WORKER.bat running — do not open WhatsApp yourself.';
    alertBox.className = 'alert success show';
    saveDraft();
  } catch (err) {
    alertBox.textContent = err.message;
    alertBox.className = 'alert error show';
  } finally {
    if (btn) btn.disabled = false;
  }
});
</script>
<?php admin_footer(); ?>
