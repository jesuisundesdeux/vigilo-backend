#!/usr/bin/env python3
"""
Vigilo blur server: masks the faces and the licence plates of a photo.

  POST /blur      the photo, as a multipart field "picture" (same call as SGBlur)
                  or as the raw request body (Content-Type image/jpeg, image/png...)
                  -> 200 image/jpeg, the blurred photo
                     headers X-Blur-Faces / X-Blur-Plates: number of masked areas
                  -> 400 not an image, 413 too large
  GET  /health    -> 200 {"status": "ok"}

Detection (CPU only, no GPU or network needed):
- faces: YuNet (OpenCV DNN, models/face_detection_yunet_2023mar.onnx)
- licence plates: rows of characters on a plate background (European plates,
  any colour) and the OpenCV plate cascade (cv2.data.haarcascades)

Every detected area is pixelated then blurred: the result can not be reversed.

Settings (environment):
  BLUR_PORT            listening port (8000)
  BLUR_FACE_THRESHOLD  YuNet score threshold, lower finds more faces (0.6)
  BLUR_MAX_BYTES       maximum size of a photo (20 MB)
  BLUR_WORKERS         photos processed at the same time (2)
  BLUR_JPEG_QUALITY    quality of the returned JPEG (90)
"""

import email.parser
import email.policy
import json
import os
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import cv2
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
FACE_MODEL = os.path.join(HERE, 'models', 'face_detection_yunet_2023mar.onnx')
PLATE_CASCADE = os.path.join(cv2.data.haarcascades, 'haarcascade_russian_plate_number.xml')

FACE_THRESHOLD = float(os.environ.get('BLUR_FACE_THRESHOLD', '0.6'))
MAX_BYTES = int(os.environ.get('BLUR_MAX_BYTES', str(20 * 1024 * 1024)))
JPEG_QUALITY = int(os.environ.get('BLUR_JPEG_QUALITY', '90'))
# Detection runs on a copy of the photo at most this large (the areas are mapped back)
DETECT_MAX_SIDE = 1600

_local = threading.local()
_workers = threading.BoundedSemaphore(int(os.environ.get('BLUR_WORKERS', '2')))


def _face_detector():
    # cv2 detectors are not thread safe: one per thread
    if not hasattr(_local, 'faces'):
        _local.faces = cv2.FaceDetectorYN.create(FACE_MODEL, '', (320, 320), FACE_THRESHOLD, 0.3, 5000)
        _local.plates = cv2.CascadeClassifier(PLATE_CASCADE)
    return _local.faces


def _plate_cascade():
    _face_detector()
    return _local.plates


def detect_faces(img):
    """Boxes (x, y, w, h) of the faces."""
    h, w = img.shape[:2]
    detector = _face_detector()
    detector.setInputSize((w, h))
    _, found = detector.detect(img)
    boxes = []
    if found is not None:
        for f in found:
            boxes.append((int(f[0]), int(f[1]), int(f[2]), int(f[3])))
    return boxes


def _character_rows(gray):
    """Rows of 4 to 10 character-like blobs of the same height (dark on light, then light on dark)."""
    height = gray.shape[0]
    rows = []
    for g in (gray, 255 - gray):
        for block in (15, 31):
            binary = cv2.adaptiveThreshold(g, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV, block, 10)
            n, _, stats, _ = cv2.connectedComponentsWithStats(binary, 8)
            chars = []
            for i in range(1, n):
                x, y, w, h, area = stats[i]
                if h < 7 or h > height * 0.2 or w < 2:
                    continue
                if not 0.12 <= w / h <= 1.0 or not 0.15 <= area / (w * h) <= 0.9:
                    continue
                chars.append((int(x), int(y), int(w), int(h)))
            chars.sort()
            used = [False] * len(chars)
            for i, first in enumerate(chars):
                if used[i]:
                    continue
                row, last = [first], first
                for j in range(i + 1, len(chars)):
                    if used[j]:
                        continue
                    c = chars[j]
                    # Gap up to about two characters (the dashes are not characters)
                    if c[0] > last[0] + last[2] + last[3] * 2.2:
                        break
                    mean_h = sum(r[3] for r in row) / len(row)
                    if abs(c[3] - mean_h) > 0.25 * mean_h or c[0] < last[0] + last[2] * 0.5:
                        continue
                    if abs((c[1] + c[3] / 2) - (last[1] + last[3] / 2)) > 0.3 * mean_h:
                        continue
                    row.append(c)
                    last = c
                    used[j] = True
                if 4 <= len(row) <= 10:
                    x0 = min(r[0] for r in row)
                    y0 = min(r[1] for r in row)
                    x1 = max(r[0] + r[2] for r in row)
                    y1 = max(r[1] + r[3] for r in row)
                    # A plate is a short line of characters: not a long text
                    if (x1 - x0) <= (y1 - y0) * 10:
                        rows.append((x0, y0, x1 - x0, y1 - y0))
    return rows


def detect_plates(img):
    """Boxes (x, y, w, h) of the licence plates."""
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    boxes = []
    for x, y, w, h in _character_rows(gray):
        # The whole plate: margins and the country bands around the characters
        boxes.append((x - int(w * 0.15), y - int(h * 0.45), int(w * 1.3), int(h * 1.9)))
    for x, y, w, h in _plate_cascade().detectMultiScale(gray, 1.1, 4, minSize=(30, 8)):
        boxes.append((int(x), int(y), int(w), int(h)))
    return boxes


def _merge(boxes):
    """Merges overlapping boxes (a plate found by both detectors is masked once)."""
    boxes = [list(b) for b in boxes]
    merged = True
    while merged:
        merged = False
        for i in range(len(boxes)):
            for j in range(i + 1, len(boxes)):
                a, b = boxes[i], boxes[j]
                if a[0] < b[0] + b[2] and b[0] < a[0] + a[2] and a[1] < b[1] + b[3] and b[1] < a[1] + a[3]:
                    x0, y0 = min(a[0], b[0]), min(a[1], b[1])
                    x1, y1 = max(a[0] + a[2], b[0] + b[2]), max(a[1] + a[3], b[1] + b[3])
                    boxes[i] = [x0, y0, x1 - x0, y1 - y0]
                    del boxes[j]
                    merged = True
                    break
            if merged:
                break
    return [tuple(b) for b in boxes]


def _mask(img, box, pad, ellipse=False):
    x, y, w, h = box
    H, W = img.shape[:2]
    x0, y0 = max(0, int(x - w * pad)), max(0, int(y - h * pad))
    x1, y1 = min(W, int(x + w * (1 + pad))), min(H, int(y + h * (1 + pad)))
    if x1 - x0 < 2 or y1 - y0 < 2:
        return
    area = img[y0:y1, x0:x1]
    # Pixelate (about 6 blocks on the short side) then blur the blocks
    small = max(1, min(x1 - x0, y1 - y0) // 6)
    pixelated = cv2.resize(area, (max(1, (x1 - x0) // small), max(1, (y1 - y0) // small)), interpolation=cv2.INTER_AREA)
    pixelated = cv2.resize(pixelated, (x1 - x0, y1 - y0), interpolation=cv2.INTER_NEAREST)
    k = (max(3, small * 2) | 1)
    blurred = cv2.GaussianBlur(pixelated, (k, k), 0)
    if ellipse:
        mask = np.zeros(area.shape[:2], np.uint8)
        cv2.ellipse(mask, ((x1 - x0) // 2, (y1 - y0) // 2), ((x1 - x0) // 2, (y1 - y0) // 2), 0, 0, 360, 255, -1)
        area[mask > 0] = blurred[mask > 0]
    else:
        area[:] = blurred


def blur_image(img):
    """Masks the faces and plates of a BGR image in place. Returns (faces, plates)."""
    H, W = img.shape[:2]
    scale = min(1.0, DETECT_MAX_SIDE / max(H, W))
    work = cv2.resize(img, (int(W * scale), int(H * scale)), interpolation=cv2.INTER_AREA) if scale < 1 else img
    faces = [tuple(int(v / scale) for v in b) for b in detect_faces(work)]
    plates = [tuple(int(v / scale) for v in b) for b in _merge(detect_plates(work))]
    for box in faces:
        _mask(img, box, 0.25, ellipse=True)
    for box in plates:
        _mask(img, box, 0.1)
    return len(faces), len(plates)


def blur_bytes(data):
    """Blurs an encoded image. Returns (jpeg bytes, faces, plates), None if not an image."""
    img = cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_COLOR)
    if img is None:
        return None
    faces, plates = blur_image(img)
    ok, out = cv2.imencode('.jpg', img, [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY])
    return out.tobytes(), faces, plates


def _picture(content_type, body):
    """The photo of a request: multipart field "picture" (or the first file), or the raw body."""
    if content_type.lower().startswith('multipart/form-data'):
        message = email.parser.BytesParser(policy=email.policy.HTTP).parsebytes(
            b'Content-Type: ' + content_type.encode('latin-1') + b'\r\n\r\n' + body)
        files = [p for p in message.iter_parts() if p.get_filename() or p.get_param('name', header='content-disposition') == 'picture']
        named = [p for p in files if p.get_param('name', header='content-disposition') == 'picture']
        part = (named or files or [None])[0]
        return part.get_payload(decode=True) if part is not None else None
    return body


class Handler(BaseHTTPRequestHandler):
    server_version = 'vigilo-blur'
    protocol_version = 'HTTP/1.1'

    def _send(self, code, body, content_type='application/json', headers=None):
        self.send_response(code)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _error(self, code, message):
        self._send(code, json.dumps({'error': message}).encode())

    def do_GET(self):
        if self.path.rstrip('/') in ('/health', ''):
            self._send(200, b'{"status": "ok"}')
        else:
            self._error(404, 'not found')

    def do_POST(self):
        if self.path.split('?')[0].rstrip('/') != '/blur':
            return self._error(404, 'not found')
        try:
            length = int(self.headers.get('Content-Length', '0'))
        except ValueError:
            length = -1
        if length <= 0:
            return self._error(400, 'no photo')
        if length > MAX_BYTES:
            self.close_connection = True
            return self._error(413, 'photo too large')
        body = self.rfile.read(length)
        try:
            data = _picture(self.headers.get('Content-Type', ''), body)
        except Exception:
            data = None
        if not data:
            return self._error(400, 'no photo')
        with _workers:
            result = blur_bytes(data)
        if result is None:
            return self._error(400, 'not an image')
        jpeg, faces, plates = result
        self._send(200, jpeg, 'image/jpeg', {'X-Blur-Faces': str(faces), 'X-Blur-Plates': str(plates)})

    def log_message(self, fmt, *args):
        sys.stderr.write('%s %s\n' % (self.address_string(), fmt % args))


def main():
    port = int(os.environ.get('BLUR_PORT', '8000'))
    httpd = ThreadingHTTPServer(('', port), Handler)
    httpd.daemon_threads = True
    print('vigilo blur server listening on port %d' % port, flush=True)
    httpd.serve_forever()


if __name__ == '__main__':
    main()
