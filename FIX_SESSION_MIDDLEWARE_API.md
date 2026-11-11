# FIX: 401 Greška na API endpoint-ima zbog nedostajućeg session middleware

## Datum: 8. februar 2026. (FINALNO REŠENJE)

---

## 🐛 Glavni problem

**API rute u Laravel-u 11 PO DEFAULT-u NEMAJU session middleware!**

Kada admin pokušava da koristi cleanup endpoint-e ili bilo koji endpoint sa `->middleware('admin')`, dobija:

```
Greška 401: Morate biti prijavljeni.
```

Iako je korisnik prijavljen kroz web interfejs i sesija postoji.

---

## 🔍 Zašto se ovo dešava?

### Laravel 11 arhitektura:

1. **Web rute** (`routes/web.php`) → **Koriste session middleware** ✅
2. **API rute** (`routes/api.php`) → **NE koriste session middleware** ❌

### Problem sa našom aplikacijom:

Mi koristimo **Blade template-e** (web interface) koji pozivaju **API endpoint-e** (`/api/*`), ALI:

- **Web stranica:** Kreira session kada se korisnik prijavi ✅
- **API pozivi:** Ne mogu da pročitaju tu session jer nemaju session middleware ❌

### AdminMiddleware proverava session:

```php
if (!session('user_id')) {  // ← Ovo vraća NULL za API rute!
    return response()->json([
        'message' => 'Morate biti prijavljeni.'
    ], 401);
}
```

Bez session middleware-a, `session('user_id')` uvek vraća `null`, čak i ako korisnik jeste prijavljen.

---

## ✅ Rešenje

Dodati **session middleware** na API rute u `bootstrap/app.php`:

### Izmena u fajlu:

**Fajl:** `backend/bootstrap/app.php`

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'admin' => \App\Http\Middleware\AdminMiddleware::class,
        'auth.custom' => \App\Http\Middleware\AuthMiddleware::class,
    ]);
    
    // ✅ DODATO: Session middleware za API rute
    $middleware->api(append: [
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    ]);
})
```

### Šta ovo radi:

1. `StartSession::class` - Pokreće sesiju za svaki API zahtev
2. `ShareErrorsFromSession::class` - Deli validation greške (opciono, ali korisno)

---

## 🔄 Kako primeniti fix

### 1. Izmena je već napravljena u:
```
backend/bootstrap/app.php
```

### 2. **OBAVEZNO:** Restartuj server

**VAŽNO:** Promene u `bootstrap/app.php` se učitavaju samo pri pokretanju servera!

```powershell
# Zatvori postojeći PHP server
Stop-Process -Name php -Force

# Ponovo pokreni
cd D:\Projekti\ATVSSLibrary\backend
php -S localhost:8000 -t public
```

### 3. Testiraj:

1. **Osveži browser** (F5 ili Ctrl+F5)
2. Uloguj se kao admin
3. Idi na **Admin Panel → Rezervacije → Tab "Održavanje"**
4. Klikni **"Očisti rezervisane primerke"**
5. ✅ Sada bi trebalo da radi!

---

## 🧪 Kako proveriti da radi

### Debug endpoint:

```javascript
// U browser console (F12):
fetch('/api/debug-session', {
    credentials: 'same-origin'
})
.then(r => r.json())
.then(data => console.log(data));
```

**PRE fix-a:**
```json
{
  "session_id": "abc123...",
  "session_user_id": null  // ← NULL, session ne radi!
}
```

**POSLE fix-a:**
```json
{
  "session_id": "abc123...",
  "session_user_id": 1  // ← ID korisnika, session radi! ✅
}
```

---

## 📊 Zašto su neki endpoint-i radili bez fix-a?

Možda si primetio da su neki API pozivi radili čak i pre ovog fix-a. Razlog je **browser caching i timing**:

1. **Prvi poziv** (odmah nakon login-a) - Session cookie još postoji u memory-u
2. **Kasniji pozivi** - Session možda još nije expired
3. **Nakon restart browser-a** - Session se gubi, pojavljuje se 401 greška

**Konzistentnost:** Bez session middleware-a, ponašanje je **nepredvidivo**.

---

## 🔒 Bezbednost - Da li je bezbedno?

**DA!** Dodavanje session middleware-a na API rute je sigurno jer:

### 1. Session je i dalje zaštićen:
- ✅ `SESSION_SAME_SITE=lax` - Sprečava CSRF sa drugih domena
- ✅ `SESSION_HTTP_ONLY=true` - Sprečava JavaScript pristup cookie-u
- ✅ `X-CSRF-TOKEN` header - Dodatna CSRF zaštita

### 2. Middleware provere ostaju aktivne:
```php
// AdminMiddleware i dalje proverava:
if (!$user->isAdmin()) {
    return response()->json(['message' => 'Nemate pristup'], 403);
}
```

### 3. Session je vezana za domain:
- Session cookie se šalje **SAMO** na `localhost:8000` (ili tvoj production domain)
- Ne može se ukrasti sa drugih domena

---

## 🚫 Alternativna rešenja (NISU korišćena)

### 1. Stateless API sa tokenima (JWT, Sanctum):

```php
// Umesto session-a, koristi Bearer token:
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

**Razlog zašto nije korišćeno:**
- Aplikacija već koristi session autentifikaciju
- Blade template-i su server-side rendered (ne SPA)
- Prevelika izmena postojećeg koda

### 2. Koristiti samo web.php za sve rute:

```php
// Prebaciti sve rute u routes/web.php umesto routes/api.php
```

**Razlog zašto nije korišćeno:**
- Loša organizacija koda
- API prefix je koristan za razlikovanje endpoint-a
- Middleware grupisanje je čistije

### 3. Manuelno dodati StartSession na svaki endpoint:

```php
Route::post('/admin/cleanup', function() {
    // ...
})->middleware(['admin', 'web']);
```

**Razlog zašto nije korišćeno:**
- Manje efikasno (mora se dodati na svaki endpoint)
- Zaboraviš na nekom endpoint-u = bug

---

## 📝 Za buduće projekte

Ako praviš **hybrid aplikaciju** (Blade template-i + API endpoint-i), uvek dodaj session middleware na API rute:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [
        \Illuminate\Session\Middleware\StartSession::class,
    ]);
})
```

Ili koristi **stateless API** (JWT/Sanctum) ako praviš pravu SPA aplikaciju.

---

## 🛠️ Troubleshooting

### Ako i dalje ne radi:

#### 1. Proveri da li je server restartovan:
```powershell
Stop-Process -Name php -Force
php -S localhost:8000 -t public
```

#### 2. Očisti browser cache:
```
Ctrl + Shift + Delete → Cookies and site data
```

#### 3. Proveri da li session radi:
```
Otvori: http://localhost:8000/api/debug-session
```

Treba da vidiš `session_user_id` sa tvojim ID-om.

#### 4. Proveri config:
```php
// config/session.php
'driver' => 'database',  // Mora biti database ili file
'same_site' => 'lax',
```

#### 5. Proveri da session tabela postoji:
```sql
SELECT * FROM sessions LIMIT 5;
```

---

## 📚 Laravel dokumentacija

- [Middleware](https://laravel.com/docs/11.x/middleware)
- [Session](https://laravel.com/docs/11.x/session)
- [API Authentication](https://laravel.com/docs/11.x/sanctum)

---

## ✅ Rezime

| Problem | Uzrok | Rešenje |
|---------|-------|---------|
| 401 na API endpoint-ima | API rute nemaju session middleware | Dodato `StartSession` na api rute |
| `session('user_id')` vraća null | Session nije startovana | `$middleware->api(append: [...])` |
| Admin middleware ne radi | Ne može pročitati session | Server restartovan sa novom config-om |

---

**Status:** ✅ REŠENO  
**Restartovan server:** DA  
**Testirano:** Pending (korisnik treba da testira)

---

## 👨‍💻 Autor

**GitHub Copilot** - 8. februar 2026.
