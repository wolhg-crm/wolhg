#!/usr/bin/env python3
"""Capture a full-page PNG of a URL via Chrome DevTools Protocol."""
from __future__ import annotations

import argparse
import asyncio
import base64
import json
import subprocess
import urllib.request
from pathlib import Path


async def capture(url: str, out: Path, width: int = 1280, port: int = 9229) -> tuple[int, int]:
    proc = subprocess.Popen(
        [
            "google-chrome",
            "--headless=new",
            f"--remote-debugging-port={port}",
            "--disable-gpu",
            "--hide-scrollbars",
            "--no-first-run",
            "--no-default-browser-check",
            f"--window-size={width},900",
            "about:blank",
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        import websockets

        ws_url = None
        for _ in range(40):
            try:
                with urllib.request.urlopen(f"http://127.0.0.1:{port}/json/version", timeout=1) as r:
                    ws_url = json.load(r)["webSocketDebuggerUrl"]
                    break
            except Exception:
                await asyncio.sleep(0.25)
        if not ws_url:
            raise RuntimeError("Chrome CDP not ready")

        async with websockets.connect(ws_url, max_size=80_000_000) as ws:
            msg_id = 0

            async def call(method, params=None, session_id=None):
                nonlocal msg_id
                msg_id += 1
                payload = {"id": msg_id, "method": method}
                if params is not None:
                    payload["params"] = params
                if session_id:
                    payload["sessionId"] = session_id
                await ws.send(json.dumps(payload))
                while True:
                    raw = json.loads(await ws.recv())
                    if raw.get("id") == msg_id:
                        if "error" in raw:
                            raise RuntimeError(raw["error"])
                        return raw.get("result", {})

            targets = await call("Target.getTargets")
            page = next((t for t in targets["targetInfos"] if t.get("type") == "page"), None)
            if not page:
                raise RuntimeError("no page target")
            attached = await call(
                "Target.attachToTarget",
                {"targetId": page["targetId"], "flatten": True},
            )
            sid = attached["sessionId"]

            await call("Page.enable", session_id=sid)
            await call("Runtime.enable", session_id=sid)
            await call("Page.navigate", {"url": url}, session_id=sid)
            for _ in range(60):
                ready = await call(
                    "Runtime.evaluate",
                    {"expression": "document.readyState", "returnByValue": True},
                    session_id=sid,
                )
                if ready.get("result", {}).get("value") == "complete":
                    break
                await asyncio.sleep(0.2)
            await asyncio.sleep(1.2)

            metrics = await call(
                "Runtime.evaluate",
                {
                    "expression": f"""(() => {{
                      const body = document.body, html = document.documentElement;
                      const h = Math.max(
                        body.scrollHeight, body.offsetHeight,
                        html.clientHeight, html.scrollHeight, html.offsetHeight
                      );
                      return {{width: Math.max(html.clientWidth, {width}), height: h}};
                    }})()""",
                    "returnByValue": True,
                },
                session_id=sid,
            )
            height = int(metrics["result"]["value"]["height"])

            await call(
                "Emulation.setDeviceMetricsOverride",
                {
                    "width": width,
                    "height": height,
                    "deviceScaleFactor": 1,
                    "mobile": False,
                },
                session_id=sid,
            )
            await asyncio.sleep(0.4)

            shot = await call(
                "Page.captureScreenshot",
                {
                    "format": "png",
                    "fromSurface": True,
                    "captureBeyondViewport": True,
                    "clip": {"x": 0, "y": 0, "width": width, "height": height, "scale": 1},
                },
                session_id=sid,
            )
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_bytes(base64.b64decode(shot["data"]))
            return width, height
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=3)
        except Exception:
            proc.kill()


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("url")
    p.add_argument("out", type=Path)
    p.add_argument("--width", type=int, default=1280)
    p.add_argument("--port", type=int, default=9229)
    args = p.parse_args()
    w, h = asyncio.run(capture(args.url, args.out, args.width, args.port))
    print(f"wrote {args.out} ({w}x{h})")


if __name__ == "__main__":
    main()
