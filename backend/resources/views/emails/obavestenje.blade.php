{{--
    Telo mejla sa obaveštenjem.

    Raspored je preuzet sa uzora: logotip i naziv biblioteke u zaglavlju, pa
    naslov poruke, pa sama poruka u uokvirenoj kartici. Boje su iz palete
    aplikacije (core.css), ali kao DOSLOVNE vrednosti — mejl nema pristup CSS
    promenljivama.

    Dve stvari izgledaju staromodno, a nisu previd:
      • Stilovi su INLINE, na svakom elementu. Gmail i Outlook izbacuju <style>
        blok iz <head>-a, pa bi poruka stigla kao go tekst.
      • Raspored drže <table> elementi. Outlook na Windowsu crta HTML Word-ovim
        mehanizmom, koji flex i grid ne poznaje.

    Logotip ide kao PRILOG u samoj poruci ($message->embed), a ne kao link na
    sajt. Slika sa udaljene adrese se ne bi videla dok aplikacija radi na
    localhost-u, a i tada je većina klijenata blokira dok korisnik ne klikne
    „prikaži slike". Ovako je uvek tu.
--}}
@php
    /* Traka u boji na vrhu — ista podela koju nose brojač u zaglavlju
       aplikacije i spisak obaveštenja (App\Enums\TipObavestenja::boja). */
    $bojaTrake = match ($boja) {
        'uspeh'      => '#48bb78',
        'upozorenje' => '#ed8936',
        default      => '#f56565',
    };
@endphp
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $naslov }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f7fa; font-family:'Segoe UI', Arial, Helvetica, sans-serif; color:#2d3748;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f7fa; padding:24px 12px;">
        <tr>
            <td align="center">

                {{-- Outlook na Windowsu crta poruku Word-ovim mehanizmom, koji
                     NE poznaje `max-width` — bez ovoga bi se kartica razvukla
                     preko celog prozora, dok je svuda drugde široka 600px.
                     Uslovni komentar vidi samo Outlook („mso"): on dobija
                     tabelu zadate širine, ostali klijenti je preskaču kao
                     običan HTML komentar. --}}
                <!--[if mso]>
                <table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td>
                <![endif]-->

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">

                    {{-- Traka u boji obaveštenja — jedini nagoveštaj vesti pre
                         nego što se poruka pročita. --}}
                    <tr>
                        <td style="height:5px; line-height:5px; font-size:0; background-color:{{ $bojaTrake }};">&nbsp;</td>
                    </tr>

                    {{-- ZAGLAVLJE: logotip akademije, ispod njega biblioteka --}}
                    <tr>
                        <td align="center" style="background-color:#2d3748; padding:28px 32px 22px 32px;">
                            <img src="{{ $message->embed(public_path('images/akademija-logo-mejl.png')) }}"
                                 alt="Akademija tehničko-vaspitačkih strukovnih studija"
                                 width="280"
                                 style="display:block; width:280px; max-width:100%; height:auto; margin:0 auto;">

                            <p style="margin:16px 0 0 0; font-size:12px; line-height:1.5; letter-spacing:0.6px; text-transform:uppercase; color:#a0aec0;">
                                {{ $nazivBiblioteke }}
                            </p>
                        </td>
                    </tr>

                    {{-- NASLOV — isti tekst koji stoji i u naslovu mejla --}}
                    <tr>
                        <td align="center" style="padding:32px 32px 0 32px;">
                            <h1 style="margin:0; font-size:22px; line-height:1.35; font-weight:600; color:#1a202c;">
                                {{ $naslov }}
                            </h1>

                            {{-- Kratka crta ispod naslova; <table> jer <hr>
                                 izgleda različito u svakom klijentu. --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px auto 0 auto;">
                                <tr>
                                    <td style="width:56px; height:3px; line-height:3px; font-size:0; background-color:{{ $bojaTrake }}; border-radius:2px;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- PORUKA --}}
                    <tr>
                        <td style="padding:28px 32px 32px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e2e8f0; border-radius:10px;">
                                <tr>
                                    <td style="padding:24px 26px;">
                                        <p style="margin:0 0 14px 0; font-size:15px; line-height:1.6; color:#2d3748;">
                                            Poštovani/a {{ $ime }},
                                        </p>

                                        <p style="margin:0 0 16px 0; font-size:15px; line-height:1.7; color:#2d3748;">
                                            {{ $poruka }}
                                        </p>

                                        <p style="margin:0; font-size:15px; line-height:1.6; color:#2d3748;">
                                            Srdačan pozdrav,
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- PODNOŽJE --}}
                    <tr>
                        <td align="center" style="padding:18px 32px; background-color:#f7fafc; border-top:1px solid #e2e8f0;">
                            <p style="margin:0; font-size:12px; line-height:1.5; color:#718096;">
                                Ovo je automatska poruka, na nju se ne odgovara.
                            </p>
                        </td>
                    </tr>

                </table>

                <!--[if mso]>
                </td></tr></table>
                <![endif]-->

            </td>
        </tr>
    </table>

</body>
</html>
