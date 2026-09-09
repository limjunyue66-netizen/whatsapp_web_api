"""
WhatsApp Web UI adapter — selectors and operations centralized here.

Recipient rule (permanent):
  Always open chat with https://web.whatsapp.com/send?phone={EXACT_DIGITS}
  Never search-and-click arbitrary chat list rows (that sends to wrong people).

Media rule (permanent):
  Image + Message = ONE bubble (photo with caption underneath).
  Never put caption text into the main chat composer (that creates 2 messages).
  Never use sticker file input.
"""
from __future__ import annotations

import logging
import re
import time
from pathlib import Path
from typing import Optional
from urllib.parse import quote

from playwright.sync_api import Page, TimeoutError as PlaywrightTimeout

log = logging.getLogger("worker.wa")

WA_URL = "https://web.whatsapp.com/"

SELECTORS = {
    "qr_canvas": 'canvas[aria-label*="Scan"], canvas[aria-label*="QR"], div[data-ref] canvas',
    "chat_list": '[data-testid="chat-list"], div[aria-label*="Chat list"]',
    "search_box": '[data-testid="chat-list-search"], div[contenteditable="true"][data-tab="3"]',
    # Strict chat message box only — never match random data-tab=10 elsewhere
    "composer": '#main footer [data-testid="conversation-compose-box-input"], #main footer div[contenteditable="true"][data-tab="10"], #main footer div[contenteditable="true"]',
    "send_button": '[data-testid="compose-btn-send"], button[aria-label="Send"], span[data-icon="send"]',
    "attach_button": '[data-testid="attach-menu-plus"], span[data-icon="plus"], div[title="Attach"]',
    "attach_file_input": 'input[type="file"]',
    "invalid_phone": 'div[data-animate-modal-popup="true"], div[role="dialog"]',
    "side_panel": "#side, #pane-side",
    "continue_chat": '#action-button, a#action-button, a[href*="send?phone="], button:has-text("Continue to Chat"), a:has-text("Continue to Chat")',
    "conversation_header": '[data-testid="conversation-info-header"], header',
    "media_send_button": '[data-testid="send"], div[role="button"] span[data-icon="send"]',
}


class WhatsAppAuthRequired(Exception):
    """Persistent session invalid — operator must re-link via QR."""


class WhatsAppAdapter:
    def __init__(self, page: Page, screenshot_dir: str = "screenshots"):
        self.page = page
        self.screenshot_dir = Path(screenshot_dir)
        self.screenshot_dir.mkdir(parents=True, exist_ok=True)
        self._qr_wait_started = False

    def _diag(self, reason: str) -> dict:
        info = {"reason": reason, "url": "", "title": ""}
        try:
            info["url"] = self.page.url
            info["title"] = self.page.title()
        except Exception as e:
            info["error"] = str(e)
        log.error("WA diagnostic: %s", info)
        try:
            path = self.screenshot_dir / f"diag_{int(time.time())}.png"
            self.page.screenshot(path=str(path), full_page=False)
            info["screenshot"] = str(path)
        except Exception:
            pass
        return info

    def open_home(self, force: bool = False) -> None:
        url = ""
        try:
            url = self.page.url or ""
        except Exception:
            force = True

        if "post_logout=1" in url:
            log.warning("Detected post_logout URL — waiting for QR without reload")
            return

        if not force and "web.whatsapp.com" in url and "/send?" not in url:
            return

        if not force and self.detect_qr() and self._qr_wait_started:
            log.info("QR present — skipping navigation reload")
            return

        log.info("Navigating to WhatsApp Web home")
        self.page.goto(WA_URL, wait_until="domcontentloaded")

    def detect_qr(self) -> bool:
        try:
            return self.page.locator(SELECTORS["qr_canvas"]).first.is_visible(timeout=1500)
        except Exception:
            return False

    def detect_connected(self) -> bool:
        try:
            if self.page.locator(SELECTORS["chat_list"]).first.is_visible(timeout=2000):
                return True
        except Exception:
            pass
        try:
            if self.page.locator(SELECTORS["side_panel"]).first.is_visible(timeout=1500):
                return True
        except Exception:
            pass
        return False

    def detect_login_state(self) -> str:
        try:
            url = self.page.url or ""
        except Exception:
            return "unknown"

        if "post_logout=1" in url:
            self._qr_wait_started = True
            return "qr_required"

        if self.detect_connected():
            self._qr_wait_started = False
            return "connected"

        if self.detect_qr():
            self._qr_wait_started = True
            return "qr_required"

        if "web.whatsapp.com" in url:
            return "disconnected"
        return "unknown"

    def ensure_connected(self, wait_qr: bool = False, timeout_s: int = 120) -> str:
        self.open_home(force=False)
        deadline = time.time() + timeout_s
        while time.time() < deadline:
            state = self.detect_login_state()
            if state == "connected":
                return state
            if state == "qr_required":
                if not wait_qr:
                    raise WhatsAppAuthRequired(
                        "WhatsApp authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect."
                    )
                log.info("Waiting for QR scan… (no page reload)")
                time.sleep(2)
                continue
            time.sleep(2)
            try:
                if "web.whatsapp.com" not in (self.page.url or ""):
                    self.open_home(force=True)
            except Exception:
                pass
        state = self.detect_login_state()
        if state != "connected":
            self._diag("ensure_connected_timeout")
            if state == "qr_required":
                raise WhatsAppAuthRequired(
                    "WhatsApp authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect."
                )
        return state

    def _click_continue_if_present(self) -> None:
        selectors = [
            SELECTORS["continue_chat"],
            'text=Continue to Chat',
            'text=Continue',
            'text=继续到聊天',
            'text=继续聊天',
            'text=继续',
            '[data-testid="popup-controls-ok"]',
            'div[role="button"]:has-text("Continue")',
            'div[role="button"]:has-text("继续")',
        ]
        for sel in selectors:
            try:
                btn = self.page.locator(sel).first
                if btn.is_visible(timeout=1200):
                    log.info("Auto-clicking WhatsApp continue/control: %s", sel)
                    btn.click(timeout=5000)
                    time.sleep(1.0)
                    return
            except Exception:
                continue

    def _dismiss_invalid_number(self) -> None:
        try:
            dlg = self.page.locator(SELECTORS["invalid_phone"]).first
            if dlg.is_visible(timeout=800):
                text = (dlg.inner_text(timeout=800) or "").lower()
                if "invalid" in text or "phone number" in text or "not on whatsapp" in text:
                    raise ValueError("Invalid WhatsApp number / not on WhatsApp")
        except ValueError:
            raise
        except Exception:
            pass

    def _composer_ready(self) -> bool:
        """True only when the real chat footer composer is visible."""
        strict = [
            '#main footer [data-testid="conversation-compose-box-input"]',
            '#main footer div[contenteditable="true"][data-tab="10"]',
            '#main footer div[contenteditable="true"]',
        ]
        for sel in strict:
            try:
                loc = self.page.locator(sel).first
                if loc.count() and loc.is_visible(timeout=800):
                    return True
            except Exception:
                continue
        return False

    def _wait_for_composer_after_send_nav(self, phone: str, nav_started_at: float, timeout_s: int = 60) -> None:
        deadline = time.time() + timeout_s
        min_ready_at = nav_started_at + 2.5
        while time.time() < deadline:
            if self.detect_login_state() == "qr_required":
                raise WhatsAppAuthRequired(
                    "WhatsApp authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect."
                )
            self._dismiss_invalid_number()
            self._click_continue_if_present()
            if self._composer_ready() and time.time() >= min_ready_at:
                time.sleep(0.8)
                if self._composer_ready():
                    log.info("Composer ready for phone=%s", phone)
                    return
            time.sleep(0.35)
        self._diag("composer_timeout_after_send")
        raise RuntimeError(f"Timed out opening WhatsApp chat for phone={phone}")

    def find_chat_via_send_url(self, phone_e164: str, prefill_text: str = "") -> None:
        phone = re.sub(r"\D", "", phone_e164 or "")
        if len(phone) < 8 or len(phone) > 15:
            raise ValueError(f"Invalid phone for WhatsApp send: {phone_e164!r}")

        try:
            self.page.keyboard.press("Escape")
            time.sleep(0.15)
            self.page.keyboard.press("Escape")
        except Exception:
            pass

        try:
            self.open_home(force=True)
            time.sleep(0.8)
        except Exception:
            pass

        q = f"phone={phone}"
        if prefill_text.strip():
            q += f"&text={quote(prefill_text)}"
        target = f"https://web.whatsapp.com/send?{q}"

        last_err: Exception | None = None
        for attempt in range(1, 3):
            log.info("Open exact phone chat attempt=%s phone=%s", attempt, phone)
            nav_started = time.time()
            try:
                self.page.goto(target, wait_until="domcontentloaded")
                time.sleep(1.2)
                url = self.page.url or ""
                if "post_logout=1" in url or self.detect_qr():
                    raise WhatsAppAuthRequired(
                        "WhatsApp authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect."
                    )
                self._click_continue_if_present()
                self._wait_for_composer_after_send_nav(phone, nav_started)
                return
            except WhatsAppAuthRequired:
                raise
            except Exception as e:
                last_err = e
                log.warning("Exact phone open failed attempt=%s phone=%s err=%s", attempt, phone, e)
                try:
                    self.open_home(force=True)
                    time.sleep(1.0)
                except Exception:
                    pass

        self._diag("open_chat_failed")
        raise RuntimeError(f"Could not open WhatsApp chat for phone={phone}: {last_err}")

    def type_message(self, text: str) -> None:
        box = self.page.locator(SELECTORS["composer"]).first
        box.click(timeout=10000)
        try:
            box.fill("")
        except Exception:
            pass
        box.fill(text)

    def _clear_main_composer_without_sending(self) -> None:
        try:
            box = self.page.locator(SELECTORS["composer"]).first
            if box.is_visible(timeout=800):
                box.click(timeout=1500)
                self.page.keyboard.press("Control+A")
                self.page.keyboard.press("Backspace")
                self.page.keyboard.press("Escape")
        except Exception:
            pass

    def _probe_caption_candidates(self) -> list:
        try:
            return self.page.evaluate(
                """() => {
                  const nodes = [...document.querySelectorAll('[contenteditable="true"], [role="textbox"]')];
                  return nodes.map((el, idx) => {
                    const ph = (
                      el.getAttribute('data-placeholder')
                      || el.getAttribute('aria-placeholder')
                      || el.getAttribute('aria-label')
                      || el.getAttribute('title')
                      || ''
                    );
                    const parentTest = el.closest('[data-testid]')?.getAttribute('data-testid') || '';
                    return {
                      idx,
                      ph,
                      parentTest,
                      tab: el.getAttribute('data-tab') || '',
                      inFooter: !!el.closest('#main > footer, #main footer, footer'),
                      inMainCompose: parentTest.includes('conversation-compose') || parentTest.includes('compose-box'),
                      visible: !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length),
                      text: (el.innerText || '').slice(0, 40),
                    };
                  });
                }"""
            ) or []
        except Exception as e:
            log.warning("Caption probe failed: %s", e)
            return []

    def type_caption(self, text: str) -> None:
        """Type caption into the media preview box so image+text stay ONE WhatsApp bubble."""
        if not text.strip():
            return

        # Give the media drawer a moment after paste/attach
        time.sleep(0.6)
        cands = self._probe_caption_candidates()
        log.info("Caption candidates: %s", cands)

        # Click obvious placeholder labels first (EN/ZH)
        for hint in (
            'text=/Add a caption/i',
            'text=/Type a caption/i',
            'text=/添加说明/i',
            'text=/添加标题/i',
            'text=/说明/i',
            '[data-testid="media-caption-input-container"]',
            'div[data-animate-media-caption="true"]',
            '[contenteditable="true"][data-placeholder*="caption" i]',
            '[contenteditable="true"][data-placeholder*="说明"]',
            '[aria-label*="caption" i]',
            '[aria-label*="说明"]',
        ):
            try:
                h = self.page.locator(hint).last
                if h.count() and h.is_visible(timeout=500):
                    h.click(timeout=2000)
                    time.sleep(0.2)
                    break
            except Exception:
                pass

        caption_selectors = [
            '[data-testid="media-caption-input-container"] [contenteditable="true"]',
            '[data-testid="media-caption-input-container"] div[contenteditable="true"]',
            'div[data-animate-media-caption="true"] [contenteditable="true"]',
            'div[data-testid="media-editor"] [contenteditable="true"]',
            'div[data-animate-media-viewer="true"] [contenteditable="true"]',
            '[data-testid="media-caption-input-container"]',
            'div[role="textbox"][data-tab="10"]',
            # Last resort: any visible contenteditable that is NOT the footer chat composer
        ]

        last_err: Exception | None = None
        deadline = time.time() + 20

        def _try_type_into(box, soft: bool = False) -> bool:
            box.click(timeout=3000)
            time.sleep(0.15)
            self.page.keyboard.press("Control+A")
            self.page.keyboard.press("Backspace")
            time.sleep(0.1)
            # Prefer execCommand/insertText — Lexical/React editors often ignore fill()
            try:
                handle = box.element_handle()
                if handle is not None:
                    self.page.evaluate(
                        """(el, value) => {
                          el.focus();
                          try { document.execCommand('selectAll', false, null); } catch (e) {}
                          const ok = document.execCommand('insertText', false, value);
                          if (!ok) {
                            el.textContent = value;
                          }
                          el.dispatchEvent(new InputEvent('input', {bubbles:true, data:value, inputType:'insertText'}));
                          el.dispatchEvent(new Event('change', {bubbles:true}));
                        }""",
                        handle,
                        text,
                    )
            except Exception:
                try:
                    self.page.keyboard.insert_text(text)
                except Exception:
                    self.page.keyboard.type(text, delay=8)
            time.sleep(0.55)
            sample = text.strip()[:8]
            got = ""
            try:
                got = (box.inner_text(timeout=1500) or "").strip().replace("\n", " ")
            except Exception:
                pass
            if sample and sample in got:
                return True
            if got and len(got) >= min(8, len(text.strip())):
                return True
            try:
                got2 = self.page.evaluate(
                    """() => {
                      const roots = [
                        document.querySelector('[data-testid="media-caption-input-container"]'),
                        document.querySelector('div[data-animate-media-caption="true"]'),
                        document.querySelector('div[data-animate-media-viewer="true"]'),
                        document.activeElement,
                      ].filter(Boolean);
                      return roots.map(r => (r.innerText || '').trim()).join('\\n');
                    }"""
                ) or ""
                if sample and sample in got2.replace("\n", " "):
                    return True
            except Exception:
                pass
            # Lexical sometimes won't reflect text via innerText immediately; if we
            # targeted a caption-like box, accept and send (still one bubble).
            if soft:
                log.warning("Caption readback empty — proceeding after soft type (%s chars)", len(text))
                return True
            return False

        while time.time() < deadline:
            for sel in caption_selectors:
                try:
                    box = self.page.locator(sel).last
                    if box.count() == 0:
                        continue
                    if not box.is_visible(timeout=600):
                        continue
                    if _try_type_into(box):
                        log.info("Caption locked via %s (%s chars)", sel, len(text))
                        return
                except Exception as e:
                    last_err = e

            # JS-index based: pick best non-footer contenteditable
            try:
                idx = self.page.evaluate(
                    """() => {
                      const nodes = [...document.querySelectorAll('[contenteditable="true"]')];
                      const scored = [];
                      for (let i = 0; i < nodes.length; i++) {
                        const el = nodes[i];
                        if (!(el.offsetWidth || el.offsetHeight)) continue;
                        const parentTest = el.closest('[data-testid]')?.getAttribute('data-testid') || '';
                        if (parentTest.includes('conversation-compose') || parentTest.includes('compose-box-input')) continue;
                        if (el.closest('#main footer') && !el.closest('[data-animate-media-viewer], [data-testid="media-caption-input-container"], [data-animate-media-caption]')) {
                          // footer compose while media open is wrong target
                          continue;
                        }
                        const ph = (
                          el.getAttribute('data-placeholder')
                          || el.getAttribute('aria-placeholder')
                          || el.getAttribute('aria-label')
                          || ''
                        ).toLowerCase();
                        let score = 0;
                        if (ph.includes('caption') || ph.includes('说明') || ph.includes('标题')) score += 5;
                        if (parentTest.includes('caption') || parentTest.includes('media')) score += 4;
                        if (el.closest('[data-animate-media-viewer="true"], [data-animate-media-caption="true"]')) score += 6;
                        if (el.getAttribute('data-tab') === '10') score += 1;
                        scored.push([score, i]);
                      }
                      scored.sort((a,b) => b[0]-a[0]);
                      return scored.length ? scored[0][1] : -1;
                    }"""
                )
                if isinstance(idx, int) and idx >= 0:
                    box = self.page.locator('[contenteditable="true"]').nth(idx)
                    if _try_type_into(box):
                        log.info("Caption locked via JS index=%s (%s chars)", idx, len(text))
                        return
            except Exception as e:
                last_err = e

            # If media preview is open, try typing into the currently focused element
            try:
                self.page.keyboard.press("Control+A")
                self.page.keyboard.press("Backspace")
                self.page.keyboard.insert_text(text)
                time.sleep(0.4)
                active = self.page.evaluate(
                    """() => {
                      const el = document.activeElement;
                      if (!el) return '';
                      return (el.innerText || el.textContent || '').trim();
                    }"""
                ) or ""
                sample = text.strip()[:8]
                if sample and sample in active.replace("\n", " "):
                    log.info("Caption locked via focused element (%s chars)", len(text))
                    return
            except Exception as e:
                last_err = e

            time.sleep(0.35)

        # Final soft attempt: type into best scored box even if readback fails
        try:
            for sel in caption_selectors:
                box = self.page.locator(sel).last
                if box.count() == 0:
                    continue
                if not box.is_visible(timeout=500):
                    continue
                if _try_type_into(box, soft=True):
                    log.info("Caption soft-locked via %s (%s chars)", sel, len(text))
                    return
        except Exception as e:
            last_err = e

        try:
            idx = self.page.evaluate(
                """() => {
                  const nodes = [...document.querySelectorAll('[contenteditable="true"]')];
                  for (let i = 0; i < nodes.length; i++) {
                    const el = nodes[i];
                    if (!(el.offsetWidth || el.offsetHeight)) continue;
                    const parentTest = el.closest('[data-testid]')?.getAttribute('data-testid') || '';
                    if (parentTest.includes('conversation-compose')) continue;
                    return i;
                  }
                  return -1;
                }"""
            )
            if isinstance(idx, int) and idx >= 0:
                box = self.page.locator('[contenteditable="true"]').nth(idx)
                if _try_type_into(box, soft=True):
                    log.info("Caption soft-locked via index=%s (%s chars)", idx, len(text))
                    return
        except Exception as e:
            last_err = e

        self._diag("caption_box_missing")
        log.error("Caption candidates at failure: %s", self._probe_caption_candidates())
        try:
            self.page.keyboard.press("Escape")
        except Exception:
            pass
        raise RuntimeError(
            f"Image caption UI missing — refusing 2-message send. Last error: {last_err}"
        )

    def _log_footer_icons(self) -> None:
        """Debug what attach-related controls exist in the chat footer."""
        try:
            info = self.page.evaluate(
                """() => {
                  const root = document.querySelector('#main footer')
                    || document.querySelector('footer')
                    || document.querySelector('#main')
                    || document.body;
                  const icons = [...root.querySelectorAll('[data-icon], [data-testid], [aria-label], [title]')]
                    .slice(0, 80)
                    .map(el => ({
                      icon: el.getAttribute('data-icon'),
                      testid: el.getAttribute('data-testid'),
                      aria: el.getAttribute('aria-label'),
                      title: el.getAttribute('title'),
                      tag: el.tagName,
                    }));
                  const inputs = [...document.querySelectorAll('input[type=file]')].map(el => ({
                    accept: el.getAttribute('accept'),
                    multiple: el.multiple,
                  }));
                  return { icons, inputs };
                }"""
            )
            log.info("Footer attach probe: %s", info)
        except Exception as e:
            log.warning("Footer attach probe failed: %s", e)

    def _open_attach_menu(self) -> bool:
        """Try to open attach (+ / paperclip) menu. Returns True if something was clicked."""
        # Wait a bit — composer can be ready before footer icons finish painting
        time.sleep(0.6)

        attach_sels = [
            '#main footer [data-testid="conversation-clip"]',
            '#main footer [data-testid="attach-menu-plus"]',
            '#main footer [data-testid="clip"]',
            '#main footer span[data-icon="plus-rounded"]',
            '#main footer span[data-icon="attach-menu-plus"]',
            '#main footer span[data-icon="plus"]',
            '#main footer span[data-icon="clip"]',
            '#main footer div[title="Attach"]',
            '#main footer button[aria-label="Attach"]',
            '#main footer button[aria-label*="Attach"]',
            '#main footer div[aria-label="Attach"]',
            '#main footer div[aria-label*="Attach"]',
            # Chinese / other locales
            '#main footer [aria-label*="附加"]',
            '#main footer [title*="附加"]',
            '#main footer [aria-label*="Anexo"]',
            'footer [data-testid="conversation-clip"]',
            'footer span[data-icon="plus"]',
            'footer span[data-icon="clip"]',
            '[data-testid="conversation-clip"]',
            '[data-testid="attach-menu-plus"]',
            'span[data-icon="plus-rounded"]',
            'span[data-icon="attach-menu-plus"]',
            'span[data-icon="plus"]',
            'span[data-icon="clip"]',
            'div[title="Attach"]',
            'button[aria-label*="Attach"]',
            'div[aria-label*="Attach"]',
        ]

        for sel in attach_sels:
            try:
                btn = self.page.locator(sel).last
                # Prefer attached+enabled over strict "visible" (WA icons often opacity-animated)
                if btn.count() == 0:
                    continue
                btn.wait_for(state="attached", timeout=1500)
                try:
                    btn.scroll_into_view_if_needed(timeout=1500)
                except Exception:
                    pass
                btn.click(timeout=4000, force=True)
                time.sleep(0.8)
                log.info("Attach menu opened via %s", sel)
                return True
            except Exception:
                continue

        # JS fallback: click nearest button around plus/clip icon in footer
        try:
            clicked = self.page.evaluate(
                """() => {
                  const root = document.querySelector('#main footer')
                    || document.querySelector('footer')
                    || document.querySelector('#main');
                  if (!root) return false;
                  const want = ['plus', 'plus-rounded', 'clip', 'attach-menu-plus'];
                  const icons = [...root.querySelectorAll('[data-icon]')];
                  for (const icon of icons) {
                    const name = (icon.getAttribute('data-icon') || '').toLowerCase();
                    if (!want.includes(name)) continue;
                    const clickable = icon.closest('button,div[role="button"],span[role="button"]') || icon;
                    clickable.dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true}));
                    return name;
                  }
                  // aria/title Attach
                  const labeled = [...root.querySelectorAll('[aria-label],[title]')].find(el => {
                    const t = ((el.getAttribute('aria-label')||'') + ' ' + (el.getAttribute('title')||'')).toLowerCase();
                    return t.includes('attach') || t.includes('附加') || t.includes('anexo');
                  });
                  if (labeled) {
                    labeled.dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true}));
                    return 'labeled';
                  }
                  return false;
                }"""
            )
            if clicked:
                time.sleep(0.8)
                log.info("Attach menu opened via JS click (%s)", clicked)
                return True
        except Exception as e:
            log.warning("JS attach click failed: %s", e)

        self._log_footer_icons()
        return False

    def _find_image_input_only(self):
        """Return ONLY a Photos/Videos file input — never Document (*) or sticker."""
        inputs = self.page.locator("input[type='file']")
        count = inputs.count()
        for i in range(count):
            inp = inputs.nth(i)
            try:
                accept = (inp.get_attribute("accept") or "").lower()
            except Exception:
                accept = ""
            if "webp" in accept and "image/*" not in accept and "video" not in accept:
                log.info("Skip sticker input accept=%s", accept)
                continue
            if accept.strip() in ("", "*", ".*") or (
                "image" not in accept and "video" not in accept
            ):
                log.info("Skip non-photo input accept=%r", accept)
                continue
            if (
                "image/*" in accept
                or "image/jpeg" in accept
                or "image/png" in accept
                or "image/" in accept
                or "video/" in accept
            ):
                log.info("Found photo file input accept=%r", accept)
                return inp
        return None

    def _set_photo_files(self, file_input, path: str) -> None:
        try:
            file_input.evaluate(
                "el => { el.style.display='block'; el.style.opacity='1'; "
                "el.removeAttribute('hidden'); el.style.visibility='visible'; "
                "el.style.position='fixed'; el.style.left='0'; el.style.top='0'; "
                "el.style.zIndex='99999'; }"
            )
        except Exception:
            pass
        file_input.set_input_files(path)

    def _preview_opened(self) -> bool:
        sels = [
            '[data-testid="media-caption-input-container"]',
            'div[data-animate-media-viewer="true"]',
            'div[data-animate-media-caption="true"]',
            'div[data-testid="drawable-canvas-container"]',
            'div[data-testid="media-canvas-wrapper"]',
            'div[data-testid="media-editor"]',
        ]
        for sel in sels:
            try:
                loc = self.page.locator(sel).first
                if loc.count() and loc.is_visible(timeout=400):
                    return True
            except Exception:
                continue
        return False

    def _wait_media_caption_ui(self, timeout_s: float = 20) -> None:
        """Wait until photo preview is open. Caption box is handled in type_caption()."""
        deadline = time.time() + timeout_s
        while time.time() < deadline:
            if self._preview_opened():
                # Best-effort click caption placeholder; do not fail if missing
                for hint in (
                    'text=/Add a caption/i',
                    'text=/Type a caption/i',
                    'text=/添加说明/i',
                    'text=/添加标题/i',
                    '[data-testid="media-caption-input-container"]',
                    'div[data-animate-media-caption="true"]',
                ):
                    try:
                        h = self.page.locator(hint).last
                        if h.count() and h.is_visible(timeout=400):
                            h.click(timeout=1500)
                            break
                    except Exception:
                        pass
                return
            time.sleep(0.35)
        self._diag("media_preview_missing")
        raise RuntimeError(
            "Photo preview did not open — cannot send image+caption as one message"
        )

    def _attach_via_photos_menu(self, path: str) -> bool:
        """Open + menu and attach through Photos and videos (image/*), not Document."""
        opened = self._open_attach_menu()
        if not opened:
            self._diag("attach_button_missing")
            raise RuntimeError("Attach (+) button not found")

        time.sleep(0.5)
        self._log_footer_icons()

        nested_sels = [
            '[data-testid="mi-attach-media"] input[type="file"]',
            'li[data-testid="mi-attach-media"] input[type="file"]',
            'button[aria-label*="Photos"] input[type="file"]',
            'button[aria-label*="Photo"] input[type="file"]',
            'button[aria-label*="照片"] input[type="file"]',
            'button[aria-label*="相片"] input[type="file"]',
            'span[data-icon="attach-image"] >> xpath=ancestor::*[self::button or self::li or self::div][1]//input[@type="file"]',
        ]
        for sel in nested_sels:
            try:
                inp = self.page.locator(sel).first
                if inp.count() == 0:
                    continue
                accept = (inp.get_attribute("accept") or "").lower()
                if "image" not in accept and "video" not in accept:
                    continue
                log.info("Nested Photos input via %s accept=%r", sel, accept)
                self._set_photo_files(inp, path)
                return True
            except Exception as e:
                log.info("Nested Photos input failed (%s): %s", sel, e)

        file_input = self._find_image_input_only()
        if file_input is not None:
            accept = file_input.get_attribute("accept") or ""
            log.info("Menu-open image input accept=%r", accept)
            self._set_photo_files(file_input, path)
            return True

        photo_menu_sels = [
            '[data-testid="mi-attach-media"]',
            'span[data-icon="attach-image"]',
            'span[data-icon="image"]',
            'li >> text=/Photos\\s*&\\s*videos/i',
            'div >> text=/Photos\\s*&\\s*videos/i',
            'button >> text=/Photos\\s*&\\s*videos/i',
            'span >> text=/Photos\\s*&\\s*videos/i',
            'li >> text=/照片/i',
            'div >> text=/照片与视频/i',
            'li >> text=/相片/i',
            'li >> text=/Photo/i',
        ]
        for sel in photo_menu_sels:
            try:
                el = self.page.locator(sel).first
                if el.count() == 0:
                    continue
                log.info("Clicking Photos menu via %s", sel)
                child = el.locator('input[type="file"]').first
                if child.count():
                    self._set_photo_files(child, path)
                    return True
                with self.page.expect_file_chooser(timeout=8000) as fc_info:
                    el.click(timeout=5000, force=True)
                fc_info.value.set_files(path)
                log.info("File chosen via Photos file-chooser")
                return True
            except Exception as e:
                log.info("Photos menu path failed (%s): %s", sel, e)
                continue

        return False

    def _focus_chat_composer(self) -> None:
        """Click the real chat message box in #main footer (not search)."""
        try:
            self.page.bring_to_front()
        except Exception:
            pass

        sels = [
            '#main footer [data-testid="conversation-compose-box-input"]',
            '#main footer div[contenteditable="true"][data-tab="10"]',
            '#main footer div[contenteditable="true"]',
            '#main [data-testid="conversation-compose-box-input"]',
            'footer [data-testid="conversation-compose-box-input"]',
        ]
        last_err: Exception | None = None
        for sel in sels:
            try:
                box = self.page.locator(sel).first
                if box.count() == 0:
                    continue
                box.wait_for(state="visible", timeout=4000)
                try:
                    box.scroll_into_view_if_needed(timeout=2000)
                except Exception:
                    pass
                box.click(timeout=4000, force=True)
                time.sleep(0.2)
                # Ensure DOM focus
                try:
                    box.evaluate("el => el.focus()")
                except Exception:
                    pass
                log.info("Focused chat composer via %s", sel)
                return
            except Exception as e:
                last_err = e
                continue

        # JS fallback: focus any contenteditable inside #main footer
        try:
            ok = self.page.evaluate(
                """() => {
                  const root = document.querySelector('#main footer') || document.querySelector('footer');
                  if (!root) return false;
                  const el = root.querySelector('[contenteditable="true"]');
                  if (!el) return false;
                  el.focus();
                  el.click();
                  return true;
                }"""
            )
            if ok:
                log.info("Focused chat composer via JS footer contenteditable")
                return
        except Exception as e:
            last_err = e

        raise RuntimeError(f"Chat composer not focusable ({last_err})")

    def _attach_via_clipboard_paste(self, path: str) -> bool:
        """
        Primary photo path: paste image into composer.
        WhatsApp opens the normal photo+caption preview (no Attach button needed).

        Important: PowerShell clipboard steals OS focus — must re-focus WA after copy.
        """
        # Focus chat FIRST (before clipboard steals window focus)
        try:
            self._focus_chat_composer()
        except Exception as e:
            log.warning("Could not focus composer before clipboard: %s", e)
            return False

        try:
            from media_prep import copy_image_to_clipboard
            copy_image_to_clipboard(path)
        except Exception as e:
            log.warning("Clipboard image copy failed: %s", e)
            return False

        # Reclaim focus after PowerShell SetImage
        time.sleep(0.35)
        try:
            self.page.bring_to_front()
        except Exception:
            pass
        try:
            self._focus_chat_composer()
        except Exception as e:
            log.warning("Could not re-focus composer after clipboard: %s", e)
            return False

        try:
            self.page.keyboard.press("Control+A")
            self.page.keyboard.press("Backspace")
        except Exception:
            pass
        time.sleep(0.15)

        log.info("Pasting image into chat composer (Ctrl+V)")
        self.page.keyboard.press("Control+V")
        time.sleep(1.4)

        deadline = time.time() + 15
        while time.time() < deadline:
            if self._preview_opened():
                log.info("Photo preview opened via clipboard paste")
                return True
            time.sleep(0.35)

        log.warning("Clipboard paste did not open photo preview")
        return False

    def attach_image_with_caption(
        self, file_path: str, caption: str, phone_e164: str = ""
    ) -> None:
        """
        ONE WhatsApp bubble: normal PHOTO (not sticker) + caption under it.
        Prefer Ctrl+V paste (stable). Never use Document accept='*'.
        """
        path = str(Path(file_path).resolve())
        if not Path(path).is_file():
            raise FileNotFoundError(path)

        phone = re.sub(r"\D", "", phone_e164 or "")

        # Ensure we are truly inside a chat with footer composer
        if not self._composer_ready():
            if phone:
                log.warning("Footer composer missing — reopening chat for paste")
                self.find_chat_via_send_url(phone, prefill_text="")
            else:
                raise RuntimeError("Chat footer composer not ready for media send")

        attached = False

        # Path 0 (preferred): clipboard paste — bypasses Attach (+) entirely
        if self._attach_via_clipboard_paste(path):
            attached = True
        elif phone:
            # One recovery: reopen exact chat, then paste again
            log.warning("Paste failed — reopening chat and retrying paste once")
            try:
                self.find_chat_via_send_url(phone, prefill_text="")
                if self._attach_via_clipboard_paste(path):
                    attached = True
            except Exception as e:
                log.warning("Paste retry after reopen failed: %s", e)

        # Path A: ONLY real image/* input already in DOM (never accept='*')
        if not attached:
            file_input = self._find_image_input_only()
            if file_input is not None:
                try:
                    accept = file_input.get_attribute("accept") or ""
                    log.info("Path A: set_input_files accept=%r", accept)
                    self._set_photo_files(file_input, path)
                    time.sleep(1.0)
                    if self._preview_opened():
                        attached = True
                    else:
                        log.warning("Path A did not open photo preview")
                        try:
                            self.page.keyboard.press("Escape")
                        except Exception:
                            pass
                        time.sleep(0.3)
                except Exception as e:
                    log.warning("Path A set_input_files failed: %s", e)

        # Path B: Attach (+) -> Photos and videos
        if not attached:
            try:
                ok = self._attach_via_photos_menu(path)
            except Exception as e:
                log.warning("Photos menu attach failed: %s", e)
                ok = False
            if ok:
                time.sleep(1.0)
                if self._preview_opened():
                    attached = True
                else:
                    log.warning("Photos menu attach did not open preview")

        if not attached:
            self._log_footer_icons()
            self._diag("photo_attach_all_paths_failed")
            raise RuntimeError(
                "Could not open photo preview (clipboard paste + Photos menu failed). "
                "Not your image format — WhatsApp Web attach UI could not be automated."
            )

        self._wait_media_caption_ui()
        time.sleep(0.35)

        if caption.strip():
            self.type_caption(caption.strip())
        else:
            log.info("Sending image-only single photo message")

        self._click_media_send()
        time.sleep(0.8)
        self._clear_main_composer_without_sending()

        try:
            main = self.page.locator(SELECTORS["composer"]).first
            leftover = (main.inner_text(timeout=800) or "").strip()
            if leftover:
                log.warning("Clearing leftover main-composer text after media send")
                self._clear_main_composer_without_sending()
        except Exception:
            pass

    def _click_media_send(self) -> None:
        scoped = [
            'div[data-animate-media-viewer="true"] span[data-icon="send"]',
            'div[data-testid="media-editor"] span[data-icon="send"]',
            '[data-testid="media-caption-input-container"] >> xpath=ancestor::div[1]//span[@data-icon="send"]',
            '[data-testid="send"]',
            'div[role="button"][aria-label="Send"]',
            'span[data-icon="send"]',
        ]
        for sel in scoped:
            try:
                btn = self.page.locator(sel).last
                if btn.is_visible(timeout=2000):
                    btn.click(timeout=8000)
                    time.sleep(1.5)
                    log.info("Combined media+caption sent via %s", sel)
                    return
            except Exception:
                continue
        # Enter while focus is in caption box = still one bubble
        self.page.keyboard.press("Enter")
        time.sleep(1.2)

    def attach_media(self, file_path: str) -> None:
        self.attach_image_with_caption(file_path, "")

    def click_send(self) -> None:
        for sel in (SELECTORS["send_button"], SELECTORS["media_send_button"]):
            try:
                btn = self.page.locator(sel).last
                if btn.is_visible(timeout=2000):
                    btn.click(timeout=8000)
                    time.sleep(1.2)
                    return
            except Exception:
                continue
        self.page.keyboard.press("Enter")
        time.sleep(1.0)

    def send_message(self, phone_e164: str, message: str, media_path: Optional[str] = None) -> None:
        phone = re.sub(r"\D", "", phone_e164 or "")
        if len(phone) < 8:
            raise ValueError(f"Refusing send: invalid phone {phone_e164!r}")
        log.info("send_message start phone=%s media=%s", phone, bool(media_path))

        state = self.detect_login_state()
        if state != "connected":
            self.ensure_connected(wait_qr=False, timeout_s=30)

        if media_path:
            # Never prefill ?text= when media exists — that creates a separate text bubble
            self.find_chat_via_send_url(phone, prefill_text="")
            self._clear_main_composer_without_sending()
            self.attach_image_with_caption(media_path, message or "", phone_e164=phone)
        else:
            self.find_chat_via_send_url(phone, prefill_text=message)
            try:
                box = self.page.locator(SELECTORS["composer"]).first
                current = (box.inner_text(timeout=2000) or "").strip()
                if message.strip() and message.strip() not in current:
                    self.type_message(message)
            except Exception:
                self.type_message(message)
            self.click_send()

        log.info("send_message finished for phone=%s", phone)
