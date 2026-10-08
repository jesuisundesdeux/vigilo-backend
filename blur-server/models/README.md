`face_detection_yunet_2023mar.onnx`: YuNet face detector from the OpenCV model zoo
(https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet),
MIT licence, Copyright (c) 2020 Shiqi Yu. SHA-256
8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4.

`yolo11s_panoramax.onnx`: YOLO11s model detecting road signs, licence plates and faces on
street photos, trained by the Panoramax project for its blur service SGBlur
(https://github.com/cquest/sgblur, `models/yolo11s_panoramax.pt`, repository under MIT
licence; the model is trained with Ultralytics YOLO, AGPL-3.0). Exported to ONNX (opset 17,
dynamic input size) with Ultralytics 8.4.174:

    YOLO('yolo11s_panoramax.pt').export(format='onnx', dynamic=True, simplify=True, opset=17)

SHA-256 of the `.pt` file: 28222165ec8fd01dd7e377b2e9f4e2a7d937a15e4a3aa545d2431f317532e774,
of the `.onnx` file: 73d1fbd0d13095f52dc34a12322934ec1ff394888560c9fd936402749cfd7002.
Output `(1, 7, N)`: centre x, centre y, width, height, then the scores of the 3 classes
(`sign`, `plate`, `face`); the non-maximum suppression is done by `blur_server.py`.
