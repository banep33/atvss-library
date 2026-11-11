<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Helpers\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /**
     * Naziv „traga o prijavi" — kolačića koji NADŽIVLJAVA sesiju.
     *
     * Bez njega server ne može da razlikuje dva potpuno različita slučaja
     * koji na zaštićenoj ruti izgledaju isto (nema prijavljenog korisnika):
     * gost koji je otvorio link, i korisnik kome je sesija upravo istekla.
     * Prvi treba da vidi „Morate biti prijavljeni", drugi „Sesija je istekla".
     *
     * Kolačić ne nosi nikakav podatak o korisniku — samo zastavicu „1".
     * Postavlja se pri prijavi, briše se pri odjavi (da uredna odjava ne bi
     * ličila na istek) i pri prvoj poruci o isteku (da se ne ponavlja).
     */
    public const TRAG_PRIJAVE = 'bio_prijavljen';

    /** Koliko dana trag o prijavi živi (dovoljno duže od sesije). */
    public const TRAG_PRIJAVE_DANA = 30;
    /** Broj dozvoljenih pokušaja po email+IP kombinaciji */
    private const MAX_POKUSAJA_EMAIL = 5;

    /** Broj dozvoljenih pokušaja sa jedne IP adrese (preko svih email-ova) */
    private const MAX_POKUSAJA_IP = 15;

    /** Koliko sekundi se pamte neuspeli pokušaji */
    private const DECAY_SEKUNDI = 300;

    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect('/');
        }

        // Blokada mora da preživi refresh stranice: istek se čuva u sesiji
        // (ne u flash podacima koji nestaju posle prvog prikaza)
        $throttleSeconds = max(0, (int) session('login_blocked_until', 0) - time());
        if ($throttleSeconds === 0) {
            session()->forget('login_blocked_until');
        }

        return view('auth.login', ['throttleSeconds' => $throttleSeconds]);
    }

    public function login(Request $request)
    {
        // Validacija ulaznih podataka. Poruke su na srpskom i vezuju se za
        // POLJE (email/password), pa ih prijava ispisuje ispod samog polja,
        // a ne kao opšti okvir iznad forme.
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ], [
            'email.required'    => 'Polje E-pošta je obavezno.',
            'email.email'       => 'E-pošta nije u ispravnom formatu.',
            'password.required' => 'Polje Lozinka je obavezno.',
        ]);

        // Dva sloja zaštite od brute force napada:
        //  - email+IP ključ: pogađanje lozinke JEDNOG naloga
        //  - IP ključ: pogađanje kroz VIŠE naloga sa iste adrese (promena
        //    email-a ne sme da resetuje brojač)
        $emailKey = 'login.' . Str::lower($credentials['email']) . '|' . $request->ip();
        $ipKey    = 'login-ip.' . $request->ip();

        $blokiranEmail = RateLimiter::tooManyAttempts($emailKey, self::MAX_POKUSAJA_EMAIL);
        $blokiranIp    = RateLimiter::tooManyAttempts($ipKey, self::MAX_POKUSAJA_IP);

        if ($blokiranEmail || $blokiranIp) {
            $seconds = max(
                $blokiranEmail ? RateLimiter::availableIn($emailKey) : 0,
                $blokiranIp    ? RateLimiter::availableIn($ipKey)    : 0
            );
            return $this->blokirajOdgovor($seconds);
        }

        $user = User::where('email', $credentials['email'])->first();

        // Provera lozinke uz pomoć heša (bcrypt), bez poređenja u čistom obliku.
        // Kada nalog ne postoji, heš se ipak računa nad lažnom vrednošću da
        // vreme odgovora ne bi otkrivalo koji email-ovi postoje u bazi
        $passwordValid = $user
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], '$2y$12$R1ZSniFHK1JDvxFiqsuU2.Iwf./aH8oUDhGkSqDPy94bvM3PsRZe6') && false;

        if ($user && $passwordValid) {
            RateLimiter::clear($emailKey);
            session()->forget('login_blocked_until');

            // Ako se bcrypt cost u međuvremenu povećao, heš se nadograđuje
            // sada — jedini trenutak kada je lozinka dostupna u čistom obliku.
            // Mora PRE Auth::login, da guard u sesiju upiše novi heš
            if (Hash::needsRehash($user->password)) {
                $user->update(['password' => Hash::make($credentials['password'])]);
            }

            // Regeneracija ID-a sesije — sprečava session fixation napad
            // (napadač ne može unapred da podmetne poznat ID sesije žrtvi)
            $request->session()->regenerate();

            // Prijava kroz Laravelov session guard — korisnik je od sada
            // dostupan svuda preko auth()->user() / $request->user()
            Auth::login($user);

            // Logovanje aktivnosti prijave
            ActivityLogger::log('login', 'Korisnik se ulogovao');
            // intended() vraća korisnika na stranicu sa koje je poslat na login
            return redirect()->intended('/')
                ->with('success', 'Uspešno ste se prijavili!')
                ->withCookie(Cookie::make(
                    self::TRAG_PRIJAVE, '1', self::TRAG_PRIJAVE_DANA * 24 * 60
                ));
        }

        RateLimiter::hit($emailKey, self::DECAY_SEKUNDI);
        RateLimiter::hit($ipKey, self::DECAY_SEKUNDI);

        // Neuspeli pokušaji se beleže (user_id je null — napadač nije
        // prijavljen): niz ovakvih zapisa sa iste IP adrese je signal napada
        ActivityLogger::log('login_failed', 'Neuspešna prijava za email: ' . $credentials['email']);

        // Ako je OVAJ pokušaj potrošio poslednji dozvoljeni, odmah prikaži
        // blokadu (da korisnik ne sazna za nju tek iz sledećeg pokušaja)
        if (RateLimiter::tooManyAttempts($emailKey, self::MAX_POKUSAJA_EMAIL) ||
            RateLimiter::tooManyAttempts($ipKey, self::MAX_POKUSAJA_IP)) {
            return $this->blokirajOdgovor(max(
                RateLimiter::availableIn($emailKey),
                RateLimiter::availableIn($ipKey)
            ));
        }

        // Greška ide na polje „email" (ispisuje se ispod njega), a namerno
        // NE otkriva da li nalog postoji — ista poruka za pogrešan email i
        // za pogrešnu lozinku. Email se vraća u formu da se ne kuca ponovo;
        // lozinka se namerno ne vraća.
        return back()
            ->withErrors(['email' => 'Podaci ne odgovaraju ni jednom nalogu.'])
            ->withInput($request->only('email'));
    }

    /**
     * Upisuje istek blokade u sesiju (preživljava refresh) i vraća
     * odgovor sa porukom i odbrojavanjem.
     */
    private function blokirajOdgovor(int $seconds)
    {
        session(['login_blocked_until' => time() + $seconds]);

        $minutes = ceil($seconds / 60);

        return back()->withErrors([
            'throttle' => "Previše neuspelih pokušaja. Pokušajte ponovo za {$minutes} " .
                         ($minutes == 1 ? 'minut' : 'minuta') . '.',
        ])->with('throttle_seconds', $seconds);
    }

    public function logout(Request $request)
    {
        // Logovanje pre poništavanja sesije (dok još znamo ko je korisnik)
        ActivityLogger::log('logout', 'Korisnik se odjavio');

        Auth::logout();

        // Potpuno poništavanje sesije: briše sve podatke I menja ID sesije,
        // pa ukradeni/stari cookie posle odjave ne vredi ništa
        $request->session()->invalidate();
        // Novi CSRF token — stari ne sme da važi posle odjave
        $request->session()->regenerateToken();

        // Uredna odjava NIJE istek sesije — trag se briše da sledeći pokušaj
        // otvaranja zaštićene stranice ne bi rekao „Sesija je istekla".
        return redirect('/login')
            ->with('success', 'Uspešno ste se odjavili.')
            ->withCookie(Cookie::forget(self::TRAG_PRIJAVE));
    }
}
