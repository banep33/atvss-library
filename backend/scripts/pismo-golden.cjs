/* =============================================================
   Generator korpusa za test pariteta preslovljavanja.

   Pokreće PRAVI public/js/pismo.js u node-u (uz minimalne stubove za
   window/document/localStorage) i za svaki ulaz snima ćirilični oblik.
   PHP strana (App\Support\Pismo) mora da vrati isto — proverava
   tests/Unit/PismoParitetTest.php.

   Pokretanje iz korena backend/ foldera:
     node scripts/pismo-golden.cjs resources/normalization/pismo-korpus.json \
          resources/normalization/pismo-golden.json
   ============================================================= */
const fs = require('fs');

global.window = {};
global.document = {
    readyState: 'complete',
    querySelectorAll: () => [],
    getElementById: () => null,
    addEventListener: () => {},
    cookie: '',
    body: null,
    /* pismo.js od Faze 3 gleda <html data-pismo>, pa stub mora da ga ima. */
    documentElement: { getAttribute: () => null, setAttribute: () => {} },
};
global.localStorage = { getItem: () => 'lat', setItem: () => {} };
global.MutationObserver = function () {
    this.observe = () => {};
    this.disconnect = () => {};
};

eval(fs.readFileSync('public/js/pismo.js', 'utf8'));

const [, , ulazniFajl, izlazniFajl] = process.argv;

if (!ulazniFajl || !izlazniFajl) {
    console.error('Upotreba: node scripts/pismo-golden.cjs <korpus.json> <golden.json>');
    process.exit(1);
}

const korpus = JSON.parse(fs.readFileSync(ulazniFajl, 'utf8'));
const golden = korpus.map((ulaz) => ({ ulaz, cyr: window.Pismo.toCyr(ulaz) }));

/* POVRATAK U LATINICU mora da vrati polazni tekst.

   Prekidač pisma u zaglavlju radi bez osvežavanja stranice, pa korisnik ume
   da pređe u ćirilicu i nazad — ako se tekst ne vrati u polazni oblik, ostaje
   trajno izobličen do sledećeg učitavanja. Provera stoji ovde, u generatoru,
   jer PHP strana zna samo smer latinica -> ćirilica; obrnuti smer postoji
   isključivo u pismo.js. */
const nepovratni = golden.filter(({ ulaz, cyr }) => window.Pismo.toLat(cyr) !== ulaz);

if (nepovratni.length > 0) {
    console.error('Ovi tekstovi se ne vraćaju u polazni oblik:');
    nepovratni.forEach(({ ulaz, cyr }) => {
        console.error('  polazno : ' + ulaz);
        console.error('  ćirilica: ' + cyr);
        console.error('  povratak: ' + window.Pismo.toLat(cyr));
    });
    process.exit(1);
}

fs.writeFileSync(izlazniFajl, JSON.stringify(golden, null, 1), 'utf8');
console.log('Snimljeno parova:', golden.length);
