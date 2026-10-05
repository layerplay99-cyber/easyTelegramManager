FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app

# Telethon .session 与下载媒体的挂载点（与 PHP 侧共享）
RUN mkdir -p /data/sessions /data/media
VOLUME ["/data"]

EXPOSE 8081

CMD ["uvicorn", "app.server:app", "--host", "0.0.0.0", "--port", "8081"]
