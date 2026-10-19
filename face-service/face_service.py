#!/usr/bin/env python3
"""
GHIS HR face service — local-only face detection, liveness (head-turn) check and matching.

Models (both from OpenCV Zoo, commercial use allowed):
  * YuNet  face_detection_yunet_2023mar.onnx   — MIT License
  * SFace  face_recognition_sface_2021dec.onnx — Apache License 2.0

Stateless: frames arrive in the request body, are processed in memory and dropped when the
request ends. Nothing is written to disk and nothing is logged about the person.

Listens on a systemd-provided unix socket (socket activation, LISTEN_FDS) or, for local
testing, on --unix PATH / --tcp 127.0.0.1:PORT. Every request must carry the shared token.

API (JSON over HTTP):
  POST /enroll  {"frames": [b64 jpeg, ...], "params": {...}}
      -> {"ok": true, "embedding": b64 float32[128], "photo": b64 jpeg, "metrics": {...}}
  POST /verify  {"frames": [...], "reference": b64 float32[128], "params": {...}}
      -> {"ok": true, "match": bool, "score": float, "metrics": {...}}
  GET  /health  -> {"ok": true}
  Failures: {"ok": false, "code": "...", "message": "<Arabic, shown to the employee>"}
"""
import argparse
import base64
import hmac
import json
import os
import socket
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer
from socketserver import BaseServer, ThreadingMixIn

import cv2
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
MODELS = os.environ.get("GH_FACE_MODELS", os.path.join(HERE, "models"))
TOKEN = os.environ.get("GH_FACE_TOKEN", "")
MAX_BODY = 6 * 1024 * 1024
MAX_FRAMES = 8
MAX_INFLIGHT = 4      # requests decoded/analysed at once; bounds memory under MemoryMax
QUEUE_WAIT = 10       # seconds a request may wait for a slot before getting "busy"
DETECT_SIDE = 320  # YuNet is trained for faces up to ~300px, so detection runs on a downscaled copy

DEFAULTS = {
    "min_frames": 4,
    "min_yaw_range": 0.25,     # head turn: spread of the nose offset between the eyes (in eye-distances)
    "max_frontal_yaw": 0.15,   # the frame used for the embedding must be roughly frontal
    "min_face_px": 90,         # face width on the full-size frame
    "min_face_ratio": 0.18,    # face width / frame width
    "min_brightness": 60,
    "max_brightness": 210,
    "min_sharpness": 35.0,     # variance of the Laplacian on the face crop
    "min_det_score": 0.80,
    "match_threshold": 0.40,   # SFace cosine similarity (OpenCV reference value 0.363)
    "same_person_threshold": 0.30,  # all frames in one capture must be the same person
    "min_motion": 1.5,         # mean abs pixel change between frames (grey, 64x64)
}

MSG = {
    "bad_request": "طلب غير صالح.",
    "few_frames": "مفيش لقطات كفاية. جرّب تاني.",
    "bad_image": "اللقطة مش صورة صالحة.",
    "no_face": "مفيش وش واضح في الكاميرا. خلي وشك كله جوه الإطار.",
    "multi_face": "فيه أكتر من وش في الصورة. لازم تكون لوحدك قدام الكاميرا.",
    "too_small": "وشك صغير أو بعيد. قرّب من الكاميرا.",
    "too_dark": "الإضاءة ضعيفة. قف في مكان منوّر.",
    "too_bright": "الإضاءة قوية جداً أو فيه نور ورا وشك. غيّر مكانك.",
    "blurry": "الصورة مش واضحة (مهزوزة). اثبت ونضّف الكاميرا.",
    "not_frontal": "بص للكاميرا على طول في أول لقطة.",
    "no_motion": "مفيش حركة. لازم تلف راسك يمين وشمال لما يطلب منك.",
    "no_turn": "ملفّتش راسك كفاية. لف راسك يمين وشمال براحة.",
    "inconsistent": "الوش اتغير في نص اللقطات. جرّب تاني لوحدك.",
    "no_reference": "مفيش صورة مرجعية.",
    "busy": "الخدمة مشغولة دلوقتي. استنى ثواني وجرّب تاني.",
}


class FaceEngine:
    def __init__(self, models_dir):
        self.det = cv2.FaceDetectorYN.create(
            os.path.join(models_dir, "face_detection_yunet_2023mar.onnx"), "", (DETECT_SIDE, DETECT_SIDE), 0.6, 0.3, 50)
        self.rec = cv2.FaceRecognizerSF.create(os.path.join(models_dir, "face_recognition_sface_2021dec.onnx"), "")
        self.lock = threading.Lock()  # cv2 dnn nets are not re-entrant

    def detect(self, img):
        h, w = img.shape[:2]
        s = DETECT_SIDE / max(h, w)
        small = cv2.resize(img, (max(1, round(w * s)), max(1, round(h * s)))) if s < 1 else img
        s = min(s, 1.0)
        self.det.setInputSize((small.shape[1], small.shape[0]))
        _, faces = self.det.detect(small)
        if faces is None:
            return []
        faces = faces.copy()
        faces[:, :14] /= s  # bbox + 5 landmarks back to full-size coordinates
        return faces

    def feature(self, img, face):
        return self.rec.feature(self.rec.alignCrop(img, face)).flatten().astype(np.float32)

    def cosine(self, a, b):
        return float(self.rec.match(a.reshape(1, -1), b.reshape(1, -1), cv2.FaceRecognizerSF_FR_COSINE))


def yaw_proxy(f):
    """Horizontal nose offset from the eye midpoint, in units of eye distance.
    Constant for a flat photo moved in front of the camera; changes when a real head turns."""
    rx, ry, lx, ly, nx = f[4], f[5], f[6], f[7], f[8]
    eye_d = max(1.0, float(np.hypot(lx - rx, ly - ry)))
    return float((nx - (rx + lx) / 2.0) / eye_d)


def fail(code, **extra):
    return {"ok": False, "code": code, "message": MSG[code], **extra}


def decode_frames(frames):
    out = []
    for b in frames:
        try:
            raw = base64.b64decode(b, validate=True)
        except Exception:
            return None
        if len(raw) > 1_500_000:
            return None
        img = cv2.imdecode(np.frombuffer(raw, np.uint8), cv2.IMREAD_COLOR)
        if img is None or img.shape[0] < 120 or img.shape[1] < 120 or max(img.shape[:2]) > 1920:
            return None
        out.append(img)
    return out


def analyze(engine, frames_b64, params, reference=None):
    p = {**DEFAULTS, **{k: float(v) for k, v in (params or {}).items() if k in DEFAULTS}}
    t0 = time.perf_counter()
    if not isinstance(frames_b64, list) or len(frames_b64) < int(p["min_frames"]) or len(frames_b64) > MAX_FRAMES:
        return fail("few_frames")
    imgs = decode_frames(frames_b64)
    if imgs is None:
        return fail("bad_image")

    with engine.lock:
        dets = []
        for img in imgs:
            faces = [f for f in engine.detect(img) if f[14] >= p["min_det_score"]]
            if len(faces) == 0:
                return fail("no_face")
            if len(faces) > 1:
                return fail("multi_face")
            dets.append(faces[0])

        yaws = [yaw_proxy(f) for f in dets]
        # The first frame is the "look straight" frame used for quality + embedding.
        img0, f0 = imgs[0], dets[0]
        x, y, w, h = [int(round(v)) for v in f0[:4]]
        if w < p["min_face_px"] or w / img0.shape[1] < p["min_face_ratio"]:
            return fail("too_small")
        crop = img0[max(0, y):max(0, y) + h, max(0, x):max(0, x) + w]
        if crop.size == 0:
            return fail("no_face")
        grey = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        brightness = float(grey.mean())
        sharpness = float(cv2.Laplacian(grey, cv2.CV_64F).var())
        if brightness < p["min_brightness"]:
            return fail("too_dark")
        if brightness > p["max_brightness"]:
            return fail("too_bright")
        if sharpness < p["min_sharpness"]:
            return fail("blurry")
        if abs(yaws[0]) > p["max_frontal_yaw"]:
            return fail("not_frontal")

        # Liveness: real pixel change between frames + a real 3-D head turn.
        smalls = [cv2.resize(cv2.cvtColor(i, cv2.COLOR_BGR2GRAY), (64, 64)).astype(np.float32) for i in imgs]
        motion = max(float(np.abs(smalls[i] - smalls[i - 1]).mean()) for i in range(1, len(smalls)))
        yaw_range = max(yaws) - min(yaws)
        metrics = {"frames": len(imgs), "yaw_range": round(yaw_range, 3), "motion": round(motion, 2),
                   "brightness": round(brightness, 1), "sharpness": round(sharpness, 1)}
        if motion < p["min_motion"]:
            return fail("no_motion", metrics=metrics)
        if yaw_range < p["min_yaw_range"]:
            return fail("no_turn", metrics=metrics)

        emb = engine.feature(img0, f0)
        # Every frame must be the same person as frame 0 (blocks swapping faces mid-capture).
        turned = int(np.argmax(np.abs(yaws)))
        same = engine.cosine(emb, engine.feature(imgs[turned], dets[turned]))
        metrics["same_person"] = round(same, 3)
        if same < p["same_person_threshold"]:
            return fail("inconsistent", metrics=metrics)

        result = {"ok": True, "metrics": metrics}
        if reference is not None:
            score = engine.cosine(emb, reference)
            result.update(match=score >= p["match_threshold"], score=round(score, 4))
        else:
            ok, jpg = cv2.imencode(".jpg", img0, [cv2.IMWRITE_JPEG_QUALITY, 88])
            result.update(embedding=base64.b64encode(emb.tobytes()).decode(), photo=base64.b64encode(jpg.tobytes()).decode())
    metrics["ms"] = round((time.perf_counter() - t0) * 1000, 1)
    return result


class Handler(BaseHTTPRequestHandler):
    server_version = "gh-face"
    sys_version = ""

    def log_message(self, fmt, *args):  # no per-request logs: nothing about people ever hits disk
        pass

    def _send(self, code, obj):
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _authorized(self):
        return bool(TOKEN) and hmac.compare_digest(self.headers.get("X-Face-Token", ""), TOKEN)

    def address_string(self):
        return "local"

    def do_GET(self):
        if self.path == "/health" and self._authorized():
            return self._send(200, {"ok": True})
        self._send(404, {"ok": False})

    def do_POST(self):
        if not self._authorized():
            return self._send(403, {"ok": False, "code": "forbidden"})
        try:
            n = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            n = -1
        if n <= 0 or n > MAX_BODY:
            return self._send(413, fail("bad_request"))
        if not self.server.slots.acquire(timeout=QUEUE_WAIT):
            return self._send(503, fail("busy"))
        try:
            self._post(n)
        finally:
            self.server.slots.release()

    def _post(self, n):
        try:
            req = json.loads(self.rfile.read(n))
            frames, params = req.get("frames"), req.get("params") or {}
            if self.path == "/enroll":
                res = analyze(self.server.engine, frames, params)
            elif self.path == "/verify":
                ref = req.get("reference")
                if not ref:
                    return self._send(200, fail("no_reference"))
                ref = np.frombuffer(base64.b64decode(ref, validate=True), dtype=np.float32)
                if ref.size != 128:
                    return self._send(200, fail("no_reference"))
                res = analyze(self.server.engine, frames, params, reference=ref)
            else:
                return self._send(404, {"ok": False})
            del req, frames
            self._send(200, res)
        except Exception as e:  # no details: avoid leaking anything into logs
            sys.stderr.write("gh-face error: %s\n" % type(e).__name__)
            self._send(400, fail("bad_request"))


class Server(ThreadingMixIn, HTTPServer):
    """HTTP over an already-listening socket (systemd's fd 3, or one bound in main()).

    TCPServer.__init__ would create its own socket(AF_INET) first, which systemd's
    RestrictAddressFamilies=AF_UNIX forbids (EAFNOSUPPORT). So BaseServer.__init__ is
    used directly and no socket is ever created here."""
    daemon_threads = True
    address_family = socket.AF_UNIX

    def __init__(self, sock, engine):
        BaseServer.__init__(self, "local", Handler)
        self.socket = sock
        self.server_name, self.server_port = "local", 0
        self.engine = engine
        self.slots = threading.BoundedSemaphore(MAX_INFLIGHT)

    def server_bind(self):
        pass

    def server_activate(self):
        pass

    def get_request(self):
        conn, _ = self.socket.accept()
        return conn, ("local", 0)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--unix")
    ap.add_argument("--tcp", help="127.0.0.1:PORT (testing only)")
    ap.add_argument("--threads", type=int, default=int(os.environ.get("GH_FACE_THREADS", "2")))
    a = ap.parse_args()
    if not TOKEN or len(TOKEN) < 32:
        sys.exit("GH_FACE_TOKEN missing or too short")
    cv2.setNumThreads(a.threads)
    engine = FaceEngine(MODELS)

    if os.environ.get("LISTEN_FDS") == "1" and os.environ.get("LISTEN_PID") == str(os.getpid()):
        sock = socket.socket(fileno=3)  # systemd socket activation: unix socket owned/permissioned by systemd
    elif a.unix:
        if os.path.exists(a.unix):
            os.unlink(a.unix)
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.bind(a.unix)
        os.chmod(a.unix, 0o660)
        sock.listen(16)
    elif a.tcp:
        host, port = a.tcp.rsplit(":", 1)
        if host not in ("127.0.0.1", "::1", "localhost"):
            sys.exit("TCP mode is loopback only")
        sock = socket.create_server((host, int(port)))
    else:
        sys.exit("no listening socket")
    Server(sock, engine).serve_forever()


if __name__ == "__main__":
    main()
