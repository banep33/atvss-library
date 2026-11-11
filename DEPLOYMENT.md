# Isporuka na server

Aplikacija se na serveru pokreće u Docker kontejnerima. Ispod je opisan taj
način, a zatim i ručna instalacija bez Docker-a.

## Docker (način koji se koristi)

### Kako se prave image-i

CI pipeline (`bitbucket-pipelines.yml`) na svaki push gradi i objavljuje
dva image-a u registar:

| Image | Dockerfile | Sadržaj |
|-------|-----------|---------|
| `atvss-library` | `backend/Dockerfile` | Laravel (php-fpm + nginx) sa izgrađenim Vite asset-ima |
| `atvss-library-rag` | `backend/rag_service/Dockerfile` | Python RAG mikroservis |

### Servisi u `docker-compose.yml`

| Servis | Uloga |
|--------|-------|
| `app` | veb aplikacija, iza Traefik reverse proxy-ja (HTTPS, Let's Encrypt) |
| `scheduler` | `php artisan schedule:work` — pokreće zakazane poslove |
| `rag` | RAG mikroservis; bazu čita isključivo read-only |

Sva tri servisa dele isti volume sa SQLite bazom. Ollama (jezički i embedding
model) radi kao zaseban stack na mreži `ai-net`, pa se mreže `proxy` i `ai-net`
prave unapred kao spoljne (`external`).

### Promenljive okruženja

Tajne se ne pišu u `docker-compose.yml`, nego u env stack-a (npr. u Portainer-u):

- `APP_KEY` — generisati sa `php artisan key:generate --show`
- `RAG_SERVICE_KEY` — ista vrednost za `app` i `rag`
- podaci za slanje pošte: `MAIL_*`, `GMAIL_*`, `OUTLOOK_*`
- `OLLAMA_URL` / `OLLAMA_MODEL` — ako Ollama nije na podrazumevanoj adresi

### Posle prvog pokretanja

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan ai:reindex   # indeksira katalog za AI asistenta
```

Na Ollama strani modeli se preuzimaju jednom:

```bash
ollama pull llama3.2:3b
ollama pull nomic-embed-text
```

## Ručna instalacija (bez Docker-a)

Zahtevi: PHP 8.2+ (ekstenzije `pdo_sqlite`, `intl`, `mbstring`, `gd`),
Composer, Node.js, Nginx ili Apache; za AI asistenta Python 3.12 i Ollama.

```bash
cd /var/www
git clone https://github.com/banep33/atvss-library.git
cd atvss-library/backend

composer install --optimize-autoloader --no-dev
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

U `.env` podesiti najmanje:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domen>`
- `DB_DATABASE` — apsolutna putanja do `database/database.sqlite`
- podatke za slanje pošte (`MAIL_*`)
- `OLLAMA_URL`, `RAG_SERVICE_URL`, `RAG_SERVICE_KEY` — ako se koristi AI asistent

Baza i prava pristupa:

```bash
touch database/database.sqlite
php artisan migrate --force --seed

chown -R www-data:www-data storage bootstrap/cache database
chmod -R 775 storage bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Seeder pravi početne naloge iz `database/seeders/UserSeeder.php` — njihove
lozinke treba promeniti odmah posle prve prijave.

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name <domen>;
    root /var/www/atvss-library/backend/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/<domen>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<domen>/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Zakazani poslovi (obavezno)

Bez ovoga se ne ističu rezervacije i ne šalju se podsetnici o rokovima:

```
* * * * * cd /var/www/atvss-library/backend && php artisan schedule:run >> /dev/null 2>&1
```

Raspored je u `routes/console.php`: istek rezervacija i isteklih pozajmica
(svaki sat), podsetnici o rokovima (svaki dan u 08:05) i osvežavanje liste
najčitanijih (nedeljno).

### RAG mikroservis

Pokretanje i konfiguracija su opisani u
[`backend/rag_service/README.md`](backend/rag_service/README.md).
Posle prvog pokretanja: `php artisan ai:reindex`.

## Ažuriranje

```bash
git pull
composer install --optimize-autoloader --no-dev
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Provera posle isporuke

- [ ] HTTPS radi, a `https://<domen>/.env` vraća 403 ili 404
- [ ] `APP_DEBUG=false` (greška ne prikazuje stack trace)
- [ ] prijava, rezervacija i zaduživanje rade
- [ ] stižu obaveštenja e-poštom
- [ ] početne lozinke iz seedera su promenjene
- [ ] scheduler radi (`php artisan schedule:list`)
- [ ] AI asistent odgovara (`GET http://<rag>:8001/health` vraća `indexed` > 0)
- [ ] podešen je redovan backup fajla `database.sqlite` i `storage/app`
