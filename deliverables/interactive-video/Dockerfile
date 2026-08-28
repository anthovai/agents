FROM python:3.12-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY tests ./tests
COPY pytest.ini ./

# Answers and progress live here. Mount it, or they go when the container
# does — and they are the record of what somebody was marked on.
ENV VIDEO_DB_PATH=/data/interactive-video.sqlite

EXPOSE 9300
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "9300"]
