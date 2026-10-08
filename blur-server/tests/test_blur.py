#!/usr/bin/env python3
"""
Tests of the blur server: detection and masking on tests/fixtures/scene.jpg
(a face, a plate on a car and a small plate), and the HTTP calls.

  python3 -m unittest discover -s tests -v           # in-process server
  BLUR_URL=http://127.0.0.1:8000 python3 -m unittest discover -s tests -v   # running server
"""

import json
import os
import sys
import threading
import unittest
import urllib.error
import urllib.request
import uuid

import cv2
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
import blur_server  # noqa: E402

SCENE = open(os.path.join(HERE, 'fixtures', 'scene.jpg'), 'rb').read()
# Areas of the fixture (x0, y0, x1, y1)
FACE = (380, 40, 520, 200)
PLATE = (640, 600, 840, 642)
SMALL_PLATE = (120, 700, 230, 723)
FLAG_PATCH = (270, 570, 410, 710)   # text on a round patch: not a plate


def sharpness(img, area):
    x0, y0, x1, y1 = area
    return cv2.Laplacian(cv2.cvtColor(img[y0:y1, x0:x1], cv2.COLOR_BGR2GRAY), cv2.CV_64F).var()


def difference(a, b, area):
    x0, y0, x1, y1 = area
    return float(np.mean(cv2.absdiff(a[y0:y1, x0:x1], b[y0:y1, x0:x1])))


def decode(data):
    return cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_COLOR)


class Detection(unittest.TestCase):
    def test_masks_faces_and_plates(self):
        before = decode(SCENE)
        jpeg, faces, plates = blur_server.blur_bytes(SCENE)
        after = decode(jpeg)
        self.assertEqual(after.shape, before.shape)
        self.assertEqual((faces, plates), (1, 2))
        for name, area in [('face', FACE), ('plate', PLATE), ('small plate', SMALL_PLATE)]:
            self.assertLess(sharpness(after, area), sharpness(before, area) * 0.1, name + ' masked')
        self.assertLess(difference(before, after, FLAG_PATCH), 3, 'rest of the photo unchanged')

    def test_nothing_to_mask(self):
        img = np.full((300, 400, 3), 200, np.uint8)
        cv2.circle(img, (200, 150), 80, (30, 120, 30), -1)
        ok, data = cv2.imencode('.jpg', img)
        jpeg, faces, plates = blur_server.blur_bytes(data.tobytes())
        self.assertEqual((faces, plates), (0, 0))

    def test_not_an_image(self):
        self.assertIsNone(blur_server.blur_bytes(b'not an image'))


class Http(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.url = os.environ.get('BLUR_URL', '').rstrip('/')
        cls.httpd = None
        if not cls.url:
            cls.httpd = blur_server.ThreadingHTTPServer(('127.0.0.1', 0), blur_server.Handler)
            threading.Thread(target=cls.httpd.serve_forever, daemon=True).start()
            cls.url = 'http://127.0.0.1:%d' % cls.httpd.server_address[1]

    @classmethod
    def tearDownClass(cls):
        if cls.httpd:
            cls.httpd.shutdown()

    def post(self, path, body, content_type):
        req = urllib.request.Request(self.url + path, data=body, headers={'Content-Type': content_type})
        try:
            r = urllib.request.urlopen(req, timeout=60)
            return r.status, r.headers, r.read()
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read()

    def multipart(self, field, data):
        boundary = uuid.uuid4().hex
        body = ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="photo.jpg"\r\n'
                'Content-Type: image/jpeg\r\n\r\n' % (boundary, field)).encode() + data + ('\r\n--%s--\r\n' % boundary).encode()
        return body, 'multipart/form-data; boundary=' + boundary

    def check_blurred(self, status, headers, data):
        self.assertEqual(status, 200, data[:200])
        self.assertEqual(headers['Content-Type'], 'image/jpeg')
        self.assertEqual((headers['X-Blur-Faces'], headers['X-Blur-Plates']), ('1', '2'))
        self.assertLess(sharpness(decode(data), PLATE), sharpness(decode(SCENE), PLATE) * 0.1)

    def test_multipart_picture(self):
        # Same call as SGBlur, used by add_image.php
        self.check_blurred(*self.post('/blur', *self.multipart('picture', SCENE)))
        self.check_blurred(*self.post('/blur/', *self.multipart('picture', SCENE)))

    def test_raw_body(self):
        self.check_blurred(*self.post('/blur', SCENE, 'image/jpeg'))

    def test_errors(self):
        self.assertEqual(self.post('/blur', b'not an image', 'image/jpeg')[0], 400)
        self.assertEqual(self.post('/blur', *self.multipart('other', b'xx'))[0], 400)
        self.assertEqual(self.post('/elsewhere', SCENE, 'image/jpeg')[0], 404)

    def test_health(self):
        r = urllib.request.urlopen(self.url + '/health', timeout=10)
        self.assertEqual(json.loads(r.read()), {'status': 'ok'})


if __name__ == '__main__':
    unittest.main()
