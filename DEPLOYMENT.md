# 🚀 Deployment Guide - ATVSSLibrary

Ovaj dokument opisuje kako postaviti aplikaciju na production server.

---

## 📋 Pre-deployment Checklist

Pre nego što postaviš aplikaciju na server, proveri:

- [ ] Svi bugovi ispravljeni i testirani lokalno
- [ ] Git repoa push-ovan na GitHub/GitLab
- [ ] `.env.production.example` popunjen sa pravim vrednostima
- [ ] SSL certifikat za HTTPS nabavljen
- [ ] Server pristup (SSH) dobijen

---

## 🖥️ Server Requirements

**Minimalni zahtevi**:
- PHP 8.2 ili noviji
- Composer
- Node.js i npm (za Vite build)
- Nginx ili Apache
- SQLite ekstenzija za PHP
- SSL certifikat (Let's Encrypt preporučen)

**PHP ekstenzije** (obavezne):
```bash
php -m | grep -E 'pdo|sqlite|mbstring|openssl|tokenizer|xml|ctype|json|bcmath'
```

---

## 📦 Deployment Steps

### 1. Clone repozitorijum na server

```bash
cd /var/www
git clone https://github.com/banep33/atvss-library.git ATVSSLibrary
cd ATVSSLibrary/backend
```

### 2. Instalacija dependencies

```bash
# Composer packages
composer install --optimize-autoloader --no-dev

# Node packages
npm install

# Build frontend asseta
npm run build
```

### 3. Kopiraj i podesi .env fajl

```bash
cp .env.production.example .env
nano .env
```

**Izmeni sledeće vrednosti**:
- `APP_URL` → https://vaša-domena.com
- `DB_DATABASE` → /var/www/ATVSSLibrary/backend/database/database.sqlite
- `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` → pravi mail settings
- Proveri da `APP_DEBUG=false` i `APP_ENV=production`

### 4. Generiši APP_KEY

```bash
php artisan key:generate
```

### 5. Podesi bazu podataka

```bash
# Napravi SQLite fajl
touch database/database.sqlite

# Pokreni migracije
php artisan migrate --force

# Seed početne podatke (admin, žanrove, knjige)
php artisan db:seed --force
```

**VAŽNO**: Seederi će kreirati default admin korisnika:
- Email: `admin@atvss.com`
- Password: `password`

**ODMAH PROMENI PASSWORD** nakon prvog logovanja!

### 6. Podesi permissions

```bash
# Storage i cache direktorijumi moraju biti writable
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Make database writable
chmod 664 database/database.sqlite
chown www-data:www-data database/database.sqlite
```

### 7. Optimizuj aplikaciju

```bash
# Clear i cache config
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize
```

### 8. Podesi web server (Nginx primer)

Napravi fajl `/etc/nginx/sites-available/atvss-library`:

```nginx
server {
    listen 80;
    server_name vasa-domena.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name vasa-domena.com;

    root /var/www/ATVSSLibrary/backend/public;
    index index.php;

    # SSL certifikat (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/vasa-domena.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/vasa-domena.com/privkey.pem;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

Aktiviraj site:
```bash
ln -s /etc/nginx/sites-available/atvss-library /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

### 9. SSL Certifikat (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d vasa-domena.com
```

### 10. Setup Cron za scheduled tasks (opciono)

```bash
crontab -e
```

Dodaj liniju:
```
* * * * * cd /var/www/ATVSSLibrary/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 🔒 Post-deployment Security

### 1. Promeni default admin password

Loguj se kao `admin@atvss.com` / `password` i ODMAH promeni password!

### 2. Провери .env security

```bash
# .env fajl NE SME biti dostupan preko weba
curl https://vasa-domena.com/.env
# Trebalo bi da vrati 403 Forbidden ili 404
```

### 3. Proveri APP_DEBUG

```bash
# Provokuj grešku i vidi da li pokazuje stack trace
# Ako pokazuje - GREŠKA! APP_DEBUG=true
```

### 4. Test email notifikacije

Napravi testnu rezervaciju i proveri da li stižu emailovi.

---

## 🔄 Update Procedure (kada push-uješ nove izmene)

```bash
# 1. Pull latest code
cd /var/www/ATVSSLibrary/backend
git pull origin main

# 2. Update dependencies
composer install --optimize-autoloader --no-dev
npm install
npm run build

# 3. Run migrations (ako ima novih)
php artisan migrate --force

# 4. Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# 5. Re-cache for performance
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 🐛 Troubleshooting

### Problem: 500 Internal Server Error

**Rešenje**:
```bash
# Proveri Laravel log
tail -f storage/logs/laravel.log

# Proveri Nginx/Apache error log
tail -f /var/log/nginx/error.log
```

### Problem: Permission denied za storage/

**Rešenje**:
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Problem: Database locked

**Rešenje**:
```bash
# SQLite permissions
chmod 664 database/database.sqlite
chmod 775 database/
chown www-data:www-data database/database.sqlite
```

### Problem: CSS/JS ne učitavaju

**Rešenje**:
```bash
# Rebuild assets
npm run build

# Proveri da li su fajlovi u public/build/
ls -la public/build/
```

---

## 📊 Monitoring

### Log fajlovi

- Laravel: `storage/logs/laravel.log`
- Nginx: `/var/log/nginx/access.log` i `error.log`

### Backup Strategy

```bash
# Backup SQLite baze (dnevno)
0 2 * * * cp /var/www/ATVSSLibrary/backend/database/database.sqlite /backups/atvss_$(date +\%Y\%m\%d).sqlite

# Backup slika knjiga
0 3 * * * tar -czf /backups/atvss-images_$(date +\%Y\%m\%d).tar.gz /var/www/ATVSSLibrary/backend/public/images/
```

---

## ✅ Final Checklist

Pre nego što objave aplikaciju korisnicima:

- [ ] HTTPS radi (zeleni katanac u browseru)
- [ ] Sve slike se učitavaju
- [ ] Login/logout radi
- [ ] Rezervacija knjige radi
- [ ] Email notifikacije stižu
- [ ] Admin panel dostupan samo adminu
- [ ] Default admin password promenjen
- [ ] APP_DEBUG=false
- [ ] Backup skripta podešena
- [ ] Server monitoring aktivan

---

**Autor**: Milan & AI Assistant  
**Datum**: 2. mart 2026.  
**Verzija**: 1.0
