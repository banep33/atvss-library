#############################
# Bib RAG mikroservis (FastAPI)
#############################
# Build kontekst je backend/ (ne rag_service/) da bi COPY video i
# resources/normalization/ — main.py traži normalization-map.json na
# ../resources/normalization/ (relativno na rag_service/).
#
# Baza je python:slim (Debian/glibc), NE alpine — sqlite-vec wheel
# zahteva glibc i loadable sqlite ekstenzije.
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1

WORKDIR /app/rag_service

# Zavisnosti (fastapi, uvicorn, sqlite-vec, langdetect...) — keširaju se
# odvojeno od koda da rebuild ne reinstalira sve pri svakoj izmeni.
COPY rag_service/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Kod servisa + ZAJEDNIČKA mapa normalizacije (isti izvor kao PHP TextUtils).
COPY rag_service/ ./
COPY resources/normalization/ /app/resources/normalization/

EXPOSE 8001

# main.py čita RAG_HOST/RAG_PORT iz env-a i sam diže uvicorn.
# U kontejneru RAG_HOST MORA biti 0.0.0.0 (postavlja se u docker-compose.yml).
CMD ["python", "main.py"]
