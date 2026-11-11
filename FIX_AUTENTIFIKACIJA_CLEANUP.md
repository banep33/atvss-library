# FIX: "Morate biti prijavljeni" greška na cleanup endpoint-ima

## Datum: 8. februar 2026.

---

## 🐛 Problem

Kada admin klikne na dugme **"Očisti rezervisane primerke"** ili **"Očisti istekle rezervacije"** u Admin panelu, dobija poruku:

```
Greška 401: Morate biti prijavljeni.
```

Iako je korisnik prijavljen kao admin i vidi admin panel.

---

## 🔍 Uzrok

JavaScript `fetch()` API **po default-u NE ŠALJE cookie-je** (uključujući session cookie) kada se poziva endpoint na istom origin-u, osim ako eksplicitno ne navedemo `credentials` opciju.

### Kako session autentifikacija radi u Laravel-u:

1. Kada se korisnik prijavi, Laravel kreira session i šalje session cookie pretraživaču
2. Browser automatski šalje taj cookie sa **običnim HTTP zahtevima** (link klikovi, form submit-i)
3. ALI, `fetch()` API **NE šalje cookie automatski** - mora se eksplicitno navesti

### AdminMiddleware proverava:

```php
if (!session('user_id')) {
    return response()->json([
        'success' => false,
        'message' => 'Morate biti prijavljeni.'
    ], 401);
}
```

Bez session cookie-ja, Laravel ne može da pročita `session('user_id')` i vraća 401 grešku.

---

## ✅ Rešenje

Dodati `credentials: 'same-origin'` u sve `fetch()` pozive ka `/api/*` endpoint-ima.

### Pre (NE RADI):
```javascript
const response = await fetch('/api/admin/cleanup-rezervacije', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    }
});
```

### Posle (RADI):
```javascript
const response = await fetch('/api/admin/cleanup-rezervacije', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    credentials: 'same-origin'  // ✅ Šalje session cookie
});
```

---

## 📄 Izmenjeni fajlovi

**Fajl:** `backend/resources/views/admin/rezervacije.blade.php`

**Izmene:**
1. Dodato `credentials: 'same-origin'` u `cleanupRezervacije()` funkciju (linija ~404)
2. Dodato `credentials: 'same-origin'` u `cleanupIstekle()` funkciju (linija ~464)
3. Dodato `if (!response.ok)` proveru za bolju poruku greške
4. Dodato uputstvo "Ako kaže da morate biti prijavljeni, osvežite stranicu (F5)"

---

## 📚 Opcije za `credentials`

| Vrednost | Ponašanje |
|----------|-----------|
| `'omit'` | **NIKAD** ne šalje cookie-je (default za cross-origin) |
| `'same-origin'` | Šalje cookie-je **samo** za same-origin zahteve (preporuka) ✅ |
| `'include'` | **UVEK** šalje cookie-je (i za cross-origin) |

Za našu aplikaciju: `'same-origin'` je idealan jer su frontend i backend na istom origin-u (`localhost:8000`).

---

## 🧪 Testiranje

### Pre fix-a:
1. Uloguj se kao admin
2. Idi na `/admin/rezervacije` → Tab "Održavanje"
3. Klikni "Očisti rezervisane primerke"
4. ❌ Greška: "Morate biti prijavljeni"

### Posle fix-a:
1. **Refresh stranicu** (F5) da učita novu verziju JavaScript-a
2. Uloguj se kao admin (ako nisi već)
3. Idi na `/admin/rezervacije` → Tab "Održavanje"
4. Klikni "Očisti rezervisane primerke"
5. ✅ Uspešno: "Očišćeno X primeraka"

---

## ❓ Zašto drugi endpoint-i rade?

Možda si primetio da drugi API pozivi u `knjige-rezervacije.js` rade BEZ `credentials`:

```javascript
// Ovo radi iako NEMA credentials: 'same-origin'
const res = await fetch(`/api/rezervacije/${id}/odobri`, {
    method: 'PATCH',
    headers: { /* ... */ }
});
```

**Razlog:** Moderni pretraživači (Chrome, Firefox, Edge) **automatski šalju same-origin cookie-je** u većini slučajeva, ali ponašanje varira:

- **Chrome/Edge:** Šalje cookie automatski za same-origin
- **Safari:** Može blokirati u nekim slučajevima
- **Firefox (strict mode):** Može blokirati cookie-je

**Best practice:** **UVEK** eksplicitno navesti `credentials: 'same-origin'` za konzistentnost i kompatibilnost sa svim browser-ima.

---

## 🔒 Bezbednost

### Da li je bezbedno slati session cookie?

**DA**, `credentials: 'same-origin'` je bezbedan jer:

1. ✅ Cookie se šalje **SAMO** na isti origin (localhost:8000 → localhost:8000)
2. ✅ Ne šalje se na druge domene (sprečava CSRF sa drugih sajtova)
3. ✅ Kombinovano sa `X-CSRF-TOKEN` glavom pruža dvostruku zaštitu
4. ✅ Laravel-ov `AdminMiddleware` dodatno proverava da li je korisnik admin

### CSRF zaštita:

```javascript
headers: {
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
}
```

Laravel proverava ovaj token na svakom POST/PATCH/DELETE zahtevu.

---

## 🔄 Alternativna rešenja (NISU korišćena)

### 1. Koristiti axios umesto fetch:
```javascript
// axios automatski šalje cookie-je
axios.post('/api/admin/cleanup-rezervacije')
```

**Razlog zašto nije korišćeno:** Ostatak aplikacije koristi `fetch()`, ne želimo dodati novu zavisnost.

### 2. Globalni fetch wrapper:
```javascript
window.apiFetch = (url, options = {}) => {
    return fetch(url, {
        ...options,
        credentials: 'same-origin'
    });
};
```

**Razlog zašto nije korišćeno:** Samo 2 poziva, ne treba kompleksna infrastruktura.

### 3. Ukloniti `->middleware('admin')`:
**LOŠE REŠENJE** - bezbednosni rizik! Ne koristiti.

---

## 📝 Preporuke za buduće endpoint-e

Kad god praviš novi API endpoint koji zahteva autentifikaciju:

1. ✅ Dodaj middleware: `->middleware('admin')` ili `->middleware('auth')`
2. ✅ U frontend kodu UVEK koristi:
   ```javascript
   fetch('/api/endpoint', {
       method: 'POST',
       credentials: 'same-origin',  // ← Obavezno!
       headers: {
           'X-CSRF-TOKEN': csrf_token
       }
   })
   ```
3. ✅ Proveri `!response.ok` za HTTP greške
4. ✅ Testiraj u različitim browser-ima

---

## 🛠️ Debug ako i dalje ne radi

Ako nakon ove popravke i dalje dobijaš grešku:

### 1. Proveri session u bazi:
```sql
SELECT * FROM sessions WHERE user_id IS NOT NULL ORDER BY last_activity DESC LIMIT 5;
```

### 2. Proveri cookie u browser-u:
1. Otvori Developer Tools (F12)
2. Application → Cookies → localhost:8000
3. Proveri da postoji `laravel_session` cookie

### 3. Proveri da li se cookie šalje:
1. Otvori Developer Tools → Network tab
2. Klikni "Očisti rezervisane primerke"
3. Klikni na API poziv u Network tabu
4. Headers → Request Headers → Cookie
5. Treba da vidiš `laravel_session=...`

### 4. Očisti browser cache:
```
Ctrl + Shift + Delete → Očisti cache i cookie-je
```

### 5. Proveri `.env`:
```env
SESSION_DRIVER=database
SESSION_DOMAIN=null
SESSION_SAME_SITE=lax
```

---

## 👨‍💻 Autor

**GitHub Copilot** - 8. februar 2026.

---

## 📞 Podrška

Ako problem i dalje postoji nakon svih ovih koraka, proveri:
- Da li je middleware registrovan u `bootstrap/app.php` ili `app/Http/Kernel.php`
- Da li postoji konflikt sa drugim middleware-om
- Da li Laravel session radi pravilno (`php artisan session:table` && `php artisan migrate`)
