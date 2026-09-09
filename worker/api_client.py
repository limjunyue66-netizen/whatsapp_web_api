"""HTTP client for PHP worker API. Never connects to MySQL."""
from __future__ import annotations

import logging
import time
from typing import Any, Optional

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

log = logging.getLogger("worker.api")


class WorkerApiError(Exception):
    def __init__(self, message: str, status: int | None = None, payload: Any = None):
        super().__init__(message)
        self.status = status
        self.payload = payload


class WorkerApi:
    def __init__(self, base_url: str, token: str, timeout: int = 30):
        self.base_url = base_url.rstrip("/")
        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "User-Agent": "WhatsAppBotWorker/1.0",
            "Connection": "close",  # avoid stale keep-alive to Apache/PHP
        })
        retry = Retry(
            total=3,
            connect=3,
            read=3,
            backoff_factor=0.6,
            status_forcelist=(502, 503, 504),
            allowed_methods=frozenset(["GET", "POST"]),
            raise_on_status=False,
        )
        adapter = HTTPAdapter(max_retries=retry)
        self.session.mount("http://", adapter)
        self.session.mount("https://", adapter)
        self.timeout = timeout

    def _request(self, method: str, path: str, **kwargs) -> dict:
        url = f"{self.base_url}/{path.lstrip('/')}"
        last_err: Exception | None = None
        for attempt in range(1, 4):
            try:
                resp = self.session.request(method, url, timeout=self.timeout, **kwargs)
                last_err = None
                break
            except requests.RequestException as e:
                last_err = e
                log.warning("API %s %s attempt %s failed: %s", method, path, attempt, e)
                time.sleep(0.5 * attempt)
        if last_err is not None:
            raise WorkerApiError(f"Network error: {last_err}") from last_err

        if resp.status_code == 401:
            raise WorkerApiError("Unauthorized — check worker token", 401)
        if resp.status_code == 403:
            raise WorkerApiError("Forbidden — worker disabled or not allowed", 403)

        try:
            data = resp.json()
        except ValueError as e:
            raise WorkerApiError(f"Invalid JSON from API ({resp.status_code})", resp.status_code) from e

        if not data.get("success"):
            raise WorkerApiError(data.get("message") or "API error", resp.status_code, data)
        return data

    def status(self) -> dict:
        return self._request("GET", "status.php")

    def heartbeat(self, payload: dict) -> dict:
        return self._request("POST", "heartbeat.php", json=payload)

    def claim_job(self, whatsapp_status: str = "connected") -> Optional[dict]:
        data = self._request("POST", "claim_job.php", json={"whatsapp_status": whatsapp_status})
        return (data.get("data") or {}).get("job")

    def report_result(self, job_id: int, result: str, error: str = "") -> dict:
        return self._request(
            "POST",
            "report_result.php",
            json={"job_id": job_id, "result": result, "error": error},
        )

    def download_media(self, media_id: int, job_id: int, dest_path: str) -> str:
        url = f"{self.base_url}/download_media.php"
        last_err: Exception | None = None
        for attempt in range(1, 4):
            try:
                resp = self.session.get(
                    url,
                    params={"media_id": media_id, "job_id": job_id},
                    timeout=120,
                    stream=True,
                )
                last_err = None
                break
            except requests.RequestException as e:
                last_err = e
                time.sleep(0.5 * attempt)
        if last_err is not None:
            raise WorkerApiError(f"Media download network error: {last_err}") from last_err
        if resp.status_code != 200:
            raise WorkerApiError(f"Media download failed ({resp.status_code})", resp.status_code)
        with open(dest_path, "wb") as f:
            for chunk in resp.iter_content(65536):
                if chunk:
                    f.write(chunk)
        return dest_path
