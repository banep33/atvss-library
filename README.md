# 📚 ATVSSLibrary - Sistem za Upravljanje Bibliotekom

Aplikacija za upravljanje bibliotekom Akademije tehničko-vaspitačkih strukovnih studija.

## 🚀 Brzi Start (Windows)

### 📥 Prvi put - Setup projekta

1. **Kloniraj repozitorijum:**
   ```bash
   git clone https://github.com/MilanRichie/ATVSSLibrary.git
   cd ATVSSLibrary
   ```

2. **Pokreni setup skriptu:**
   ```bash
   setup.bat
   ```
   
   Skripta automatski:
   - ✅ Instalira PHP zavisnosti (composer install)
   - ✅ Instalira Node.js zavisnosti (npm install)
   - ✅ Kreira .env fajl
   - ✅ Generiše aplikacioni ključ
   - ✅ Kreira bazu i pokreće migracije
   - ✅ Seeduje test podatke
   - ✅ Builduje frontend

3. **Pokreni server:**
   ```bash
   start-dev.bat
   ```

4. **Otvori aplikaciju:**
   - URL: http://localhost:8000

---

### 🔄 Ažuriranje projekta (nakon git pull)

```bash
git pull origin main
update.bat
start-dev.bat
```

**update.bat automatski:**
- ✅ Ažurira composer zavisnosti
- ✅ Ažurira npm zavisnosti
- ✅ Pokreće nove migracije (uključujući data migrations!)
- ✅ Builduje frontend
- ✅ Čisti cache

---

## 🐧 Brzi Start (Linux/Mac)

### 📥 Prvi put - Setup projekta

1. **Kloniraj repozitorijum:**
   ```bash
   git clone https://github.com/MilanRichie/ATVSSLibrary.git
   cd ATVSSLibrary
   ```

2. **Učini skripte izvršnim:**
   ```bash
   chmod +x setup.sh update.sh start-dev.sh
   ```

3. **Pokreni setup skriptu:**
   ```bash
   ./setup.sh
   ```

4. **Pokreni server:**
   ```bash
   ./start-dev.sh
   ```

5. **Otvori aplikaciju:**
   - URL: http://localhost:8000
   - Za gasenje: Ctrl+C

### 🔄 Ažuriranje projekta (nakon git pull)

```bash
git pull origin main
./update.sh
./start-dev.sh
```

---

### ⚙️ Dostupne Skripte

| Windows | Linux/Mac | Kada koristiti | Šta radi |
|---------|-----------|---------------|----------|
| `setup.bat` | `./setup.sh` | **Prvi put** nakon clone | Kompletan setup od nule |
| `update.bat` | `./update.sh` | **Nakon git pull** | Ažurira zavisnosti i migracije |
| `start-dev.bat` | `./start-dev.sh` | **Pokretanje servera** | Startuje backend na port 8000 |
| `stop-dev.bat` | Ctrl+C | **Gašenje servera** | Zaustavlja server |

---

## 👥 Default Korisnici

Nakon `setup.bat`, imamo:

| Email | Password | Uloga |
|-------|----------|-------|
| admin@biblioteka.rs | admin123 | Administrator |
| jovana@example.com | clan123 | Član |
| stefan@example.com | clan123 | Član |

---

## 🛠️ Manuelni Setup (opciono)

Ako ne možeš koristiti .bat skripte:

```bash
cd backend

# 1. Instalacija zavisnosti
composer install
npm install

# 2. Setup okruženja
copy .env.example .env
php artisan key:generate

# 3. Baza podataka
php artisan migrate:fresh --seed

# 4. Build
npm run build

# 5. Pokretanje
php -S localhost:8000 -t public
```

---

## 📦 Tehnologije

### Backend:
- **Laravel 11** - PHP Framework
- **SQLite** - Baza podataka
- **PHP 8.2+** - Server-side jezik

### Frontend:
- **Blade Templates** - Templating engine
- **Vanilla JavaScript** - Client-side logika
- **CSS3** - Stilizovanje
- **Vite** - Build tool

---

## 📁 Struktura Projekta

```
ATVSSLibrary/
├── backend/                  # Laravel aplikacija
│   ├── app/                 # Models, Controllers, Middleware
│   ├── database/
│   │   ├── migrations/      # Schema i data migrations
│   │   └── seeders/         # Test podaci
│   ├── public/              # Public assets (slike, CSS, JS)
│   ├── resources/
│   │   └── views/           # Blade template fajlovi
│   └── routes/              # Web i API rute
├── setup.bat                # 🆕 Prvi setup
├── update.bat               # 🆕 Ažuriranje nakon pull
├── start-dev.bat            # Pokretanje servera
└── stop-dev.bat             # Gašenje servera
```

---

## 🔧 Česte Komande

### Laravel:

```bash
# Migracije
php artisan migrate              # Pokreni nove migracije
php artisan migrate:fresh --seed # Reset baze + seedovanje

# Cache
php artisan config:clear
php artisan cache:clear
php artisan view:clear

# Pravljenje migracija
php artisan make:migration add_column_to_table
php artisan make:migration fix_data_issue  # Za data migrations
```

### Git Workflow:

```bash
# Preuzimanje novih izmena
git pull origin main
update.bat                       # VAŽNO: Uvek posle pull!

# Commit i push
git add .
git commit -m "feat: opis izmene"
git push origin feature/ime-grane
```

---

## 🐛 Troubleshooting

### Problem: Slike se ne prikazuju

**Rešenje:**
```bash
update.bat  # Ovo pokreće data migration koja popravlja putanje
```

### Problem: Port 8000 je zauzet

**Rešenje:**
```bash
stop-dev.bat           # Zaustavi server
netstat -ano | findstr :8000  # Proveri proces
```

### Problem: "Class not found" greška

**Rešenje:**
```bash
composer dump-autoload
php artisan config:clear
```

---

## 📝 Konvencije

### Commit Messages:
- `feat:` - Nova funkcionalnost
- `fix:` - Popravka bug-a
- `docs:` - Dokumentacija
- `style:` - Formatiranje
- `refactor:` - Refaktorisanje koda
- `test:` - Testovi

### Primer:
```
feat: Dodavanje sistema rezervacija knjiga
fix: Data migration za putanje slika (books/ → images/)
```

---

## 👨‍💻 Autori

- **Milan** - Sistem zaduženja, kašnjenja, frontend
- **Bane** - Sistem rezervacija, Excel import, autorizacija, UI poboljšanja

---

## 📄 Licenca

Akademija tehničko-vaspitačkih strukovnih studija © 2025-2026
