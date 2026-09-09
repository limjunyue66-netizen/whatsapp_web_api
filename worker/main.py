"""
WhatsApp Bot Windows Worker
- Never connects to MySQL
- Uses Bearer token against PHP API
- Persistent Playwright profile for WhatsApp Web
"""
from __future__ import annotations

import configparser
import logging
import os
import platform
import random
import sys
import time
import traceback
from pathlib import Path

from api_client import WorkerApi, WorkerApiError
from browser_manager import BrowserManager
from wa_adapter import WhatsAppAdapter, WhatsAppAuthRequired

WORKER_DIR = Path(__file__).resolve().parent
os.chdir(WORKER_DIR)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(WORKER_DIR / "worker.log", encoding="utf-8"),
    ],
)
log = logging.getLogger("worker")


def load_config() -> configparser.ConfigParser:
    cfg = configparser.ConfigParser()
    path = WORKER_DIR / "config.ini"
    if not path.is_file():
        raise SystemExit("Missing config.ini — create worker/config.ini and set worker_token")
    cfg.read(path, encoding="utf-8")
    return cfg


def main(link_only: bool = False) -> int:
    cfg = load_config()
    w = cfg["worker"]
    token = w.get("worker_token", "").strip()
    if not token or token.startswith("REPLACE_"):
        raise SystemExit("Set worker_token in config.ini (from admin Workers page — shown once)")

    api = WorkerApi(w.get("api_base_url"), token)
    try:
        st = api.status()
        log.info("API OK version=%s", (st.get("data") or {}).get("api_version"))
    except WorkerApiError as e:
        log.error("API auth/status failed: %s", e)
        return 1

    browser = BrowserManager(
        profile_dir=str(WORKER_DIR / w.get("profile_dir", "browser_profile")),
        browser_name=w.get("browser", "msedge"),
        headless=w.getboolean("headless", fallback=False),
        timeout_ms=w.getint("navigation_timeout_ms", fallback=60000),
    )
    temp_dir = WORKER_DIR / w.get("temp_media_dir", "temp_media")
    temp_dir.mkdir(exist_ok=True)
    shot_dir = str(WORKER_DIR / w.get("screenshot_dir", "screenshots"))

    page = browser.start()
    wa = WhatsAppAdapter(page, screenshot_dir=shot_dir)

    current_job_id = None
    batch_count = 0
    last_heartbeat = 0.0
    hb_every = w.getint("heartbeat_interval_seconds", fallback=20)
    poll = w.getint("poll_interval_seconds", fallback=5)
    version = w.get("worker_version", "1.0.0")
    rate = {}

    def heartbeat(status: str, wa_status: str, error: str = "") -> None:
        nonlocal last_heartbeat, rate
        payload = {
            "status": status,
            "whatsapp_status": wa_status,
            "browser_name": w.get("browser", "msedge"),
            "os_name": platform.platform(),
            "python_version": platform.python_version(),
            "worker_version": version,
            "current_job_id": current_job_id,
            "last_error": error[:500] if error else "",
        }
        try:
            resp = api.heartbeat(payload)
            rate = (resp.get("data") or {}).get("rate_limits") or rate
            last_heartbeat = time.time()
        except WorkerApiError as e:
            log.warning("Heartbeat failed: %s", e)

    try:
        # Link mode: wait for QR indefinitely without claiming jobs
        if link_only:
            log.info("LINK MODE — scan QR in the browser window. Do not close until connected.")
            wa.open_home(force=True)
            while True:
                state = wa.detect_login_state()
                heartbeat("online" if state == "connected" else "online", state)
                if state == "connected":
                    log.info("WhatsApp connected.")
                    time.sleep(3)
                    return 0
                if state != "qr_required":
                    # Only navigate if not already on WA / QR
                    wa.open_home(force=False)
                time.sleep(2)

        # Normal worker loop
        while True:
            try:
                if time.time() - last_heartbeat >= hb_every:
                    state = wa.detect_login_state()
                    heartbeat("online", state)

                try:
                    wa.ensure_connected(wait_qr=False, timeout_s=20)
                except WhatsAppAuthRequired as e:
                    log.error("%s", e)
                    heartbeat("error", "qr_required", str(e))
                    log.error("WhatsApp logged out. Run 2_LINK_WHATSAPP.bat, then restart 3_START_WORKER.bat.")
                    time.sleep(60)
                    continue

                state = wa.detect_login_state()
                if state != "connected":
                    heartbeat("online", state)
                    time.sleep(poll)
                    continue

                # Batch pause
                max_batch = int(rate.get("max_messages_per_batch") or 20)
                pause_batch = int(rate.get("pause_between_batches_seconds") or 60)
                if batch_count >= max_batch:
                    log.info("Batch limit reached (%s) — pausing %ss", max_batch, pause_batch)
                    heartbeat("online", "connected")
                    time.sleep(pause_batch)
                    batch_count = 0

                job = api.claim_job("connected")
                if not job:
                    time.sleep(poll)
                    continue

                current_job_id = int(job["id"])
                heartbeat("busy", "connected")
                media_path = None
                try:
                    phone = "".join(ch for ch in str(job.get("phone_e164") or "") if ch.isdigit())
                    if len(phone) < 8:
                        raise ValueError(f"Job {current_job_id} has invalid phone: {job.get('phone_e164')!r}")
                    log.info("Claimed job %s → exact phone %s", current_job_id, phone)

                    media = job.get("media")
                    if media and media.get("id"):
                        ext = media.get("extension") or "bin"
                        media_path = str(temp_dir / f"job_{current_job_id}.{ext}")
                        api.download_media(int(media["id"]), current_job_id, media_path)
                        # Always convert to JPEG photo so WA sends captionable image, not sticker
                        try:
                            from media_prep import prepare_whatsapp_image
                            media_path = prepare_whatsapp_image(media_path, str(temp_dir))
                        except Exception as conv_err:
                            log.warning("Image prepare failed, using original: %s", conv_err)

                    if wa.detect_login_state() != "connected":
                        raise WhatsAppAuthRequired(
                            "WhatsApp authentication_required. Please run 2_LINK_WHATSAPP.bat to reconnect."
                        )

                    # Always the job phone only — any number the operator typed
                    # For media: message is CAPTION on the same photo bubble (not a 2nd text msg)
                    wa.send_message(phone, job.get("message_body") or "", media_path)
                    api.report_result(current_job_id, "sent")
                    log.info("Job %s reported sent to %s", current_job_id, phone)
                    batch_count += 1
                except WhatsAppAuthRequired as e:
                    # Do not burn retries aggressively on auth — report failed with auth message
                    try:
                        api.report_result(current_job_id, "failed", str(e))
                    except Exception:
                        pass
                    log.error("Auth required during job %s", current_job_id)
                    heartbeat("error", "qr_required", str(e))
                    log.error("WhatsApp logged out. Stop this window, run 2_LINK_WHATSAPP.bat, then 3_START_WORKER.bat again.")
                    time.sleep(60)
                    continue
                except Exception as e:
                    err = str(e)
                    log.error("Job %s failed: %s", current_job_id, err)
                    log.debug(traceback.format_exc())
                    try:
                        api.report_result(current_job_id, "failed", err[:500])
                    except WorkerApiError as re:
                        log.error("Could not report failure: %s", re)
                finally:
                    if media_path and os.path.isfile(media_path):
                        try:
                            os.remove(media_path)
                        except OSError:
                            pass
                    current_job_id = None
                    heartbeat("online", wa.detect_login_state())

                # Inter-message delay from server rate settings
                dmin = int(rate.get("min_delay_seconds") or 3)
                dmax = int(rate.get("max_delay_seconds") or 8)
                if dmax < dmin:
                    dmax = dmin
                time.sleep(random.uniform(dmin, dmax))

            except WorkerApiError as e:
                log.warning("API error in loop: %s", e)
                time.sleep(max(poll, 10))
            except Exception as e:
                log.error("Worker loop error: %s", e)
                log.debug(traceback.format_exc())
                try:
                    browser.recover()
                    wa = WhatsAppAdapter(browser.ensure_page(), screenshot_dir=shot_dir)
                except Exception as re:
                    log.error("Browser recovery failed: %s", re)
                    time.sleep(15)
                time.sleep(5)
    except KeyboardInterrupt:
        log.info("Shutting down")
    finally:
        browser.close()
    return 0


if __name__ == "__main__":
    link = "--link" in sys.argv or "-l" in sys.argv
    raise SystemExit(main(link_only=link))
