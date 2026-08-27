FROM python:3.12-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY ingest ./ingest
COPY tests ./tests
COPY bench.py ./

# The index is not built into the image, and not copied into it either.
#
# It is a build artefact of one particular export archive — its build report
# records that archive's SHA-256 — and the archive it currently comes from
# contains personal data the client has been asked to remove. Baking that into
# a layer would put it in every registry the image is pushed to, and layers
# outlive the decision to delete a file.
#
# Mount it instead, and rebuild it when a new export arrives:
#     python -m ingest.build export.zip --index /index/index.sqlite
VOLUME ["/index"]
ENV RAG_INDEX_PATH=/index/index.sqlite

EXPOSE 9200
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "9200"]
