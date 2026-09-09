"""Prepare media files before WhatsApp attach — always send as JPEG photo, never sticker webp."""
from __future__ import annotations

import logging
from pathlib import Path

log = logging.getLogger("worker.media")


def copy_image_to_clipboard(image_path: str) -> None:
    """Put an image on the Windows clipboard so WhatsApp accepts Ctrl+V paste."""
    from PIL import Image

    src = Path(image_path)
    if not src.is_file():
        raise FileNotFoundError(image_path)

    # Prefer BMP-on-clipboard via PowerShell (STA) — most reliable on Windows
    tmp_bmp = src.with_suffix(".clip.bmp")
    try:
        with Image.open(src) as im:
            if im.mode in ("RGBA", "LA", "P"):
                rgba = im.convert("RGBA")
                background = Image.new("RGB", rgba.size, (255, 255, 255))
                background.paste(rgba, mask=rgba.split()[-1])
                rgb = background
            else:
                rgb = im.convert("RGB")
            rgb.save(tmp_bmp, "BMP")

        import subprocess

        ps = (
            "Add-Type -AssemblyName System.Windows.Forms; "
            "Add-Type -AssemblyName System.Drawing; "
            f"$img = [System.Drawing.Image]::FromFile('{str(tmp_bmp).replace(chr(39), chr(39)+chr(39))}'); "
            "[System.Windows.Forms.Clipboard]::SetImage($img); "
            "$img.Dispose();"
        )
        proc = subprocess.run(
            [
                "powershell",
                "-NoProfile",
                "-STA",
                "-ExecutionPolicy",
                "Bypass",
                "-Command",
                ps,
            ],
            capture_output=True,
            text=True,
            timeout=20,
        )
        if proc.returncode != 0:
            err = (proc.stderr or proc.stdout or "").strip()
            raise RuntimeError(f"PowerShell SetImage failed: {err}")
        log.info("Copied image to Windows clipboard via PowerShell (%s)", src.name)
        return
    finally:
        try:
            if tmp_bmp.exists():
                tmp_bmp.unlink()
        except OSError:
            pass


def prepare_whatsapp_image(src_path: str, dest_dir: str) -> str:
    """
    Convert any common image to JPEG so WhatsApp treats it as a normal photo
    (caption-capable), not a sticker.
    """
    src = Path(src_path)
    if not src.is_file():
        raise FileNotFoundError(src_path)

    dest_dir_p = Path(dest_dir)
    dest_dir_p.mkdir(parents=True, exist_ok=True)
    out = dest_dir_p / f"{src.stem}_wa.jpg"

    try:
        from PIL import Image
    except ImportError:
        log.warning("Pillow missing — using original file %s", src.name)
        return str(src.resolve())

    with Image.open(src) as im:
        # Flatten alpha onto white for JPEG
        if im.mode in ("RGBA", "LA", "P"):
            rgba = im.convert("RGBA")
            background = Image.new("RGB", rgba.size, (255, 255, 255))
            background.paste(rgba, mask=rgba.split()[-1])
            rgb = background
        else:
            rgb = im.convert("RGB")
        # Reasonable size for WA
        max_side = 2560
        w, h = rgb.size
        if max(w, h) > max_side:
            scale = max_side / float(max(w, h))
            rgb = rgb.resize((int(w * scale), int(h * scale)), Image.Resampling.LANCZOS)
        rgb.save(out, format="JPEG", quality=90, optimize=True)

    log.info("Prepared WhatsApp JPEG %s -> %s", src.name, out.name)
    return str(out.resolve())
