"""Persistent Playwright browser lifecycle for WhatsApp Web."""
from __future__ import annotations

import logging
import os
from pathlib import Path
from typing import Optional

from playwright.sync_api import BrowserContext, Page, Playwright, sync_playwright

log = logging.getLogger("worker.browser")


class BrowserManager:
    def __init__(self, profile_dir: str, browser_name: str = "msedge", headless: bool = False, timeout_ms: int = 60000):
        self.profile_dir = str(Path(profile_dir).resolve())
        self.browser_name = browser_name
        self.headless = headless
        self.timeout_ms = timeout_ms
        self._pw: Optional[Playwright] = None
        self.context: Optional[BrowserContext] = None
        self.page: Optional[Page] = None

    def start(self) -> Page:
        os.makedirs(self.profile_dir, exist_ok=True)
        self._pw = sync_playwright().start()
        channel = None
        if self.browser_name in ("msedge", "chrome", "chromium"):
            channel = self.browser_name if self.browser_name != "chromium" else None
        launch_args = {
            "user_data_dir": self.profile_dir,
            "headless": self.headless,
            "args": ["--disable-dev-shm-usage"],
        }
        if channel:
            launch_args["channel"] = channel
        log.info("Launching browser channel=%s profile=%s", channel, self.profile_dir)
        self.context = self._pw.chromium.launch_persistent_context(**launch_args)
        self.context.set_default_timeout(self.timeout_ms)
        self.page = self.context.pages[0] if self.context.pages else self.context.new_page()
        return self.page

    def ensure_page(self) -> Page:
        if not self.context:
            return self.start()
        try:
            if self.page and not self.page.is_closed():
                return self.page
        except Exception:
            pass
        self.page = self.context.new_page()
        return self.page

    def recover(self) -> Page:
        """Restart browser context while keeping the same profile directory."""
        log.warning("Recovering browser context")
        self.close(keep_profile=True)
        return self.start()

    def close(self, keep_profile: bool = True) -> None:
        _ = keep_profile
        try:
            if self.context:
                self.context.close()
        except Exception as e:
            log.warning("Context close error: %s", e)
        try:
            if self._pw:
                self._pw.stop()
        except Exception as e:
            log.warning("Playwright stop error: %s", e)
        self.context = None
        self.page = None
        self._pw = None
