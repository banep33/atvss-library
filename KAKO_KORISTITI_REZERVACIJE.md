# 🔧 Kako koristiti nove rezervacijske funkcionalnosti

## Za Admine

### 1. Čišćenje rezervisanih primeraka (jednokratno)

**Problem:** Primerci su ostali označeni kao "rezervisana" iako ne postoji aktivna rezervacija.

**Rešenje:**
1. Uloguj se kao admin
2. Idi na **Admin Panel → Rezervacije**
3. Klikni tab **"Održavanje"** (ikonica šarafa)
4. Klikni dugme **"Očisti rezervisane primerke"**
5. Sistem će automatski pronaći i osloboditi sve primerke bez aktivne rezervacije

**Rezultat:** Videćeš listu očišćenih primeraka i eventualnih preskočenih (koji imaju aktivnu rezervaciju).

---

### 2. Čišćenje isteklih rezervacija (redovno)

**Problem:** Odobrene rezervacije kojima je prošao rok preuzimanja (5 dana) ostaju aktivne i zauzimaju primerke.

**Rešenje:**
1. Uloguj se kao admin
2. Idi na **Admin Panel → Rezervacije**
3. Klikni tab **"Održavanje"**
4. Klikni dugme **"Očisti istekle rezervacije"**
5. Sistem će:
   - Označiti istekle rezervacije kao "istekla"
   - Osloboditi primerke
   - Poslati notifikacije korisnicima

**Preporuka:** Radi ovo **jednom dnevno** ili postavi automatski cron job (vidi dokumentaciju).

---

### 3. Odbijanje rezervacija (ispravljeno)

**Šta je novo:** Kada odbijete odobrenu rezervaciju, primerak se **automatski oslobađa**.

**Kako koristiti:**
1. U tab "Na čekanju" ili "Odobrene"
2. Klikni "Odbij" na rezervaciji
3. ✅ Primerak će automatski biti vraćen u status "slobodna"

**Napomena:** Pre ove popravke, primerak bi ostao zaključan kao "rezervisana".

---

## Za Članove/Korisnike

### Otkazivanje sopstvene rezervacije

**Šta je novo:** Možeš da otkažeš sopstvenu rezervaciju bez potrebe da kontaktiraš bibliotekara.

**Kako koristiti:**
1. Uloguj se na sajt
2. Idi na **"Moje rezervacije"** (ako postoji u meniju)
3. Pronađi aktivnu rezervaciju (status "Na čekanju" ili "Odobrena")
4. Klikni dugme **"Otkaži rezervaciju"**
5. ✅ Rezervacija će biti otkazana i primerak oslobođen

**Napomena:** Ne možeš otkazati rezervacije koje su već:
- Preuzete (zadužene)
- Odbijene od strane admina
- Istekle

---

## Novi statusi rezervacija

| Status | Značenje | Ko postavlja |
|--------|----------|--------------|
| **Na čekanju** | Nova rezervacija čeka odobrenje | Sistem (automatski) |
| **Odobrena** | Admin odobrio, primerak rezervisan | Admin |
| **Odbijena** | Admin odbio rezervaciju | Admin |
| **Preuzeta** | Knjiga je zadužena | Admin (kada zada knjigu) |
| **Otkazana** ✨ NOVO | Korisnik otkazao | Član |
| **Istekla** ✨ NOVO | Prošao rok preuzimanja (5 dana) | Sistem (cleanup) |

---

## FAQ

**Q: Zašto sam dobio/la notifikaciju "Rezervacija je istekla"?**  
A: Imao/la si 5 dana da preuzeš knjigu nakon odobrenja. Ako nisi preuzeo/la, rezervacija automatski ističe i primerak se oslobađa za druge korisnike.

**Q: Mogu li ponovo da rezervišem knjigu ako mi je istekla?**  
A: Da, možeš napraviti novu rezervaciju.

**Q: Šta ako hoću da produžim rok preuzimanja?**  
A: Trenutno to nije moguće automatski. Kontaktiraj bibliotekara.

**Q: Koliko vremena imam da preuzmem knjigu?**  
A: 5 dana od trenutka kada je rezervacija odobrena.

**Q: Mogu li otkazati rezervaciju nakon što je već preuzeta?**  
A: Ne, moraš da vratiš knjigu normalno u biblioteku.

---

## Za Developere

Svi detalji implementacije, endpoint-i, baza podataka, security, i tehnički detalji su u:

📄 **[REZERVACIJE_FIX_DOKUMENTACIJA.md](REZERVACIJE_FIX_DOKUMENTACIJA.md)**

---

**Datum:** 5. februar 2026.  
**Verzija:** 1.0
