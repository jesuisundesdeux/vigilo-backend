#!/usr/bin/env python3
"""
Vigilo blur server: masks the faces and the licence plates of a photo.

  POST /blur      the photo, as a multipart field "picture" (same call as SGBlur)
                  or as the raw request body (Content-Type image/jpeg, image/png...)
                  -> 200 image/jpeg, the blurred photo
                     headers X-Blur-Faces / X-Blur-Plates: number of masked areas
                  -> 400 not an image, 413 too large
  GET  /health    -> 200 {"status": "ok", "model": ...}

Detection (CPU only, no GPU or network needed), as Panoramax does (SGBlur):
- a YOLO11 model trained by Panoramax on street-level pictures, which finds faces,
  licence plates and road signs (models/yolo11s_panoramax.onnx, run with ONNX Runtime);
  only the faces and the plates are masked, road signs are kept (they are often what
  the observation is about);
- YuNet (OpenCV, models/face_detection_yunet_2023mar.onnx) in addition for the faces:
  it finds the close-up faces the street model may miss.

Every detected area is pixelated then blurred: the result can not be reversed.

Settings (environment):
  BLUR_PORT              listening port (8000)
  BLUR_WORKERS           photos processed at the same time (2)
  BLUR_THREADS           CPU threads per photo for the model (number of CPUs / workers)
  BLUR_SIZES             detection passes, image sizes in pixels (1024); "1024,2048" finds
                         more small faces far away, about 5 times slower
  BLUR_FACE_CONFIDENCE   minimum score of a face for the model (0.2, lower finds more)
  BLUR_PLATE_CONFIDENCE  minimum score of a plate for the model (0.3)
  BLUR_FACE_THRESHOLD    minimum score of a face for YuNet (0.6)
  BLUR_MAX_BYTES         maximum size of a photo (20 MB)
  BLUR_JPEG_QUALITY      quality of the returned JPEG (90)
  BLUR_MODEL             path of the detection model (models/yolo11s_panoramax.onnx)
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
import onnxruntime

HERE = os.path.dirname(os.path.abspath(__file__))
MODEL = os.environ.get('BLUR_MODEL', os.path.join(HERE, 'models', 'yolo11s_panoramax.onnx'))
FACE_MODEL = os.path.join(HERE, 'models', 'face_detection_yunet_2023mar.onnx')
# Classes of the Panoramax model
CLASSES = ['sign', 'plate', 'face']

WORKERS = max(1, int(os.environ.get('BLUR_WORKERS', '2')))
THREADS = max(1, int(os.environ.get('BLUR_THREADS', str(max(1, (os.cpu_count() or 1) // WORKERS)))))
SIZES = [max(320, int(s) // 32 * 32) for s in os.environ.get('BLUR_SIZES', '1024').split(',') if s.strip()]
FACE_CONFIDENCE = float(os.environ.get('BLUR_FACE_CONFIDENCE', '0.2'))
PLATE_CONFIDENCE = float(os.environ.get('BLUR_PLATE_CONFIDENCE', '0.3'))
FACE_THRESHOLD = float(os.environ.get('BLUR_FACE_THRESHOLD', '0.6'))
MAX_BYTES = int(os.environ.get('BLUR_MAX_BYTES', str(20 * 1024 * 1024)))
JPEG_QUALITY = int(os.environ.get('BLUR_JPEG_QUALITY', '90'))
# YuNet runs on a copy of the photo at most this large (the areas are mapped back)
FACE_MAX_SIDE = 1600

_options = onnxruntime.SessionOptions()
_options.intra_op_num_threads = THREADS
_options.inter_op_num_threads = 1
# One session for every thread: ONNX Runtime sessions can run concurrently
_session = onnxruntime.InferenceSession(MODEL, _options, providers=['CPUExecutionProvider'])
_input = _session.get_inputs()[0].name

_local = threading.local()
_workers = threading.BoundedSemaphore(WORKERS)


def _yolo(img, size):
    """Detections of the model on one pass: list of (class, score, (x, y, w, h))."""
    H, W = img.shape[:2]
    ratio = size / max(H, W)
    h, w = int(round(H * ratio)), int(round(W * ratio))
    # Rectangular input, padded to a multiple of 32 (as the model was trained)
    pad_h, pad_w = (-h) % 32, (-w) % 32
    top, left = pad_h // 2, pad_w // 2
    resized = cv2.resize(img, (w, h), interpolation=cv2.INTER_AREA if ratio < 1 else cv2.INTER_LINEAR)
    resized = cv2.copyMakeBorder(resized, top, pad_h - top, left, pad_w - left, cv2.BORDER_CONSTANT, value=(114, 114, 114))
    blob = np.ascontiguousarray(resized[:, :, ::-1].transpose(2, 0, 1)[None], dtype=np.float32) / 255.0
    out = _session.run(None, {_input: blob})[0][0]      # (4 + classes, candidates): cx, cy, w, h, scores
    scores = out[4:]
    classes = scores.argmax(0)
    best = scores.max(0)
    found = []
    for c, minimum in ((CLASSES.index('face'), FACE_CONFIDENCE), (CLASSES.index('plate'), PLATE_CONFIDENCE)):
        keep = (classes == c) & (best >= minimum)
        if not keep.any():
            continue
        cx, cy, bw, bh = out[0, keep], out[1, keep], out[2, keep], out[3, keep]
        boxes = np.stack([(cx - bw / 2 - left) / ratio, (cy - bh / 2 - top) / ratio, bw / ratio, bh / ratio], 1)
        conf = best[keep]
        for i in np.array(cv2.dnn.NMSBoxes(boxes.tolist(), conf.tolist(), minimum, 0.45)).flatten():
            x, y, bw_, bh_ = boxes[i]
            x0, y0 = max(0, int(x)), max(0, int(y))
            x1, y1 = min(W, int(x + bw_)), min(H, int(y + bh_))
            if x1 > x0 and y1 > y0:
                found.append((CLASSES[c], float(conf[i]), (x0, y0, x1 - x0, y1 - y0)))
    return found


def _yunet():
    # cv2 detectors are not thread safe: one per thread
    if not hasattr(_local, 'faces'):
        _local.faces = cv2.FaceDetectorYN.create(FACE_MODEL, '', (320, 320), FACE_THRESHOLD, 0.3, 5000)
    return _local.faces


def _yunet_faces(img):
    """Boxes (x, y, w, h) of the faces found by YuNet."""
    H, W = img.shape[:2]
    scale = min(1.0, FACE_MAX_SIDE / max(H, W))
    work = cv2.resize(img, (int(W * scale), int(H * scale)), interpolation=cv2.INTER_AREA) if scale < 1 else img
    detector = _yunet()
    detector.setInputSize((work.shape[1], work.shape[0]))
    _, found = detector.detect(work)
    return [tuple(int(v / scale) for v in f[:4]) for f in (found if found is not None else [])]


def _dedupe(boxes):
    """Drops a box mostly inside a box already kept (same object found twice)."""
    kept = []
    for box in sorted(boxes, key=lambda b: b[2] * b[3], reverse=True):
        x, y, w, h = box
        inside = False
        for k in kept:
            ix = max(0, min(x + w, k[0] + k[2]) - max(x, k[0]))
            iy = max(0, min(y + h, k[1] + k[3]) - max(y, k[1]))
            if ix * iy > 0.6 * w * h:
                inside = True
                break
        if not inside:
            kept.append(box)
    return kept


def detect(img):
    """Faces and plates of a BGR image: (faces, plates), lists of boxes (x, y, w, h)."""
    faces, plates = [], []
    for size in SIZES:
        for name, _, box in _yolo(img, size):
            (faces if name == 'face' else plates).append(box)
    faces += _yunet_faces(img)
    return _dedupe(faces), _dedupe(plates)


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
    faces, plates = detect(img)
    for box in faces:
        _mask(img, box, 0.2, ellipse=True)
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
            self._send(200, json.dumps({'status': 'ok', 'model': os.path.basename(MODEL), 'sizes': SIZES}).encode())
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
    print('vigilo blur server listening on port %d (model %s, sizes %s, %d workers x %d threads)'
          % (port, os.path.basename(MODEL), SIZES, WORKERS, THREADS), flush=True)
    httpd.serve_forever()


if __name__ == '__main__':
    main()
