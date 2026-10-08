# Vigilo blur server: masks faces and licence plates on the photos (CPU only)
FROM python:3.12-slim-bookworm

LABEL org.opencontainers.image.title="vigilo-blur" \
      org.opencontainers.image.source="https://github.com/jesuisundesdeux/vigilo-backend" \
      org.opencontainers.image.licenses="GPL-3.0"

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY blur_server.py .
COPY models models

USER nobody
ENV BLUR_PORT=8000 PYTHONUNBUFFERED=1
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s CMD python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4)"
CMD ["python3", "blur_server.py"]
