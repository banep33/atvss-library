# Srpski Hunspell rečnik za jezički guard

Ovde treba da stoje dva fajla, tačno ovih naziva:

```
sr-Latn.dic
sr-Latn.aff
```

To je standardni Hunspell srpski rečnik (LATINICA). Guard ga učitava preko
`spylls` i njime proverava generisane odgovore — reč koja nije u rečniku, a nije
vlastita imenica iz kataloga (whitelist), broji se kao „ne-srpska".

## Odakle rečnik

Srpski Hunspell rečnik dolazi iz LibreOffice/Aspell projekata (`sr` rečnik).
Preuzmi `.dic` + `.aff` (latinična varijanta), preimenuj ih u `sr-Latn.dic` /
`sr-Latn.aff` i stavi ovde. (Ako imaš samo ćirilični rečnik, guard ipak radi jer
se izlaz transliteruje — ali latinični daje najbolje poklapanje.)

## Bez ovih fajlova

Guard je **onemogućen** — endpoint `/proveri-tekst` vraća `{"ok": true,
"disabled": true}`, pa se ništa ne blokira (fail-open). Isto važi ako `spylls`
nije instaliran (`pip install spylls`). Znači sistem radi normalno i bez rečnika;
guard se „upali" tek kad su prisutni i rečnik i `spylls`, i kad je flag
`AI_JEZICKI_GUARD=true`.

## Štelovanje

- `JEZICKI_PRAG` (env mikroservisa, podrazumevano 2) — koliko sumnjivih reči
  obara odgovor. Manje = strože (više lažnih odbijanja), veće = blaže.
- Whitelist vlastitih imenica se gradi iz kataloga i osvežava na `/sync`
  (`php artisan ai:reindex`).
