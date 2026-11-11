const searchInput = document.getElementById('searchInput');
const searchAutor = document.getElementById('searchAutor');
const filterZanr = document.getElementById('filterZanr');
const sortSelect = document.getElementById('sortSelect');
const grid = document.getElementById('bookGrid');
const loading = document.getElementById('loading');
const content = document.getElementById('content');
const noResults = document.getElementById('no-results');

const booksSection = document.getElementById('booksSection');
const featuredSections = document.getElementById('featuredSections');

const searchResultsSection = document.getElementById('searchResultsSection');
const searchResultsLoading = document.getElementById('searchResultsLoading');
const searchResultsContent = document.getElementById('searchResultsContent');
const searchResultsEmpty = document.getElementById('searchResultsEmpty');
const searchResultsGrid = document.getElementById('searchResultsGrid');
const searchResultsCount = document.getElementById('searchResultsCount');

/* Katalog više nema paginaciju — sve knjige se prikazuju odjednom, a
   skroluje se cela stranica. `SVE_KNJIGE` je gornja granica koju prihvata
   /api/knjige (per_page), pa jedan zahtev donese ceo katalog. */
const SVE_KNJIGE = 5000;

let timeout = null;
let zanroviData = [];

// Asinhrono učitavanje žanrova sa REST API-ja
async function loadZanrovi() {
    try {
        const res = await fetch('/api/zanrovi');
        zanroviData = await res.json();
        
        // Dinamičko kreiranje opcija u padajućoj listi
        filterZanr.innerHTML = '<option value="">Svi žanrovi</option>';
        zanroviData.forEach(zanr => {
            const option = document.createElement('option');
            option.value = zanr.id;
            option.textContent = zanr.naziv;
            filterZanr.appendChild(option);
        });

        // Na kraju loadZanrovi(), pre zatvaranja try bloka:
        if (window._pendingZanr) {
        filterZanr.value = window._pendingZanr;
        window._pendingZanr = null;
}
    } catch (err) {
        console.error('Greška pri učitavanju žanrova:', err);
    }
}

// Zajednički filteri (pretraga, autor, žanr, sortiranje) - koriste ih sve tri sekcije
function buildFilterParams() {
    const search = searchInput.value.trim();
    const autor = searchAutor.value.trim();
    const zanr = filterZanr.value;

    let sort = '';
    let dir = '';

    switch(sortSelect.value) {
        case 'year_asc': sort = 'year'; dir = 'asc'; break;
        case 'year_desc': sort = 'year'; dir = 'desc'; break;
    }

    return `search=${encodeURIComponent(search)}&autor=${encodeURIComponent(autor)}&zanr=${encodeURIComponent(zanr)}&sort=${sort}&dir=${dir}`;
}

// Da li je bilo koja pretraga/filter/sortiranje aktivno
function filtersActive() {
    return !!(
        searchInput.value.trim() ||
        searchAutor.value.trim() ||
        filterZanr.value ||
        window._pendingZanr ||
        sortSelect.value
    );
}

// Prikazuje rezultate pretrage/sortiranja (jedinstvena sekcija) ili normalan prikaz (obične + featured sekcije)
function refreshView() {
    if (filtersActive()) {
        booksSection.style.display = 'none';
        featuredSections.style.display = 'none';
        searchResultsSection.style.display = 'block';
        fetchSearchResults();
    } else {
        searchResultsSection.style.display = 'none';
        booksSection.style.display = '';
        featuredSections.style.display = '';
        fetchBooks();
        loadNajcitanije();
        loadNajnovije();
    }
}

/* Adresa stranice knjige, sa zatečenom pretragom u upitu — stranica knjige
   je koristi za povratak na katalog u istom stanju. Broja stranice više
   nema (katalog je bez paginacije). */
function hrefKnjige(id) {
    const params = new URLSearchParams({
        search: searchInput.value,
        autor: searchAutor.value,
        zanr: filterZanr.value,
        sort: sortSelect.value
    });

    return `/knjige/${id}?${params.toString()}`;
}

/**
 * Kartica jedne knjige — ista i u katalogu, i u rezultatima pretrage, i u
 * sekcijama „Najčitanije" / „Najnovije".
 *
 * CELA kartica je link ka stranici knjige: dugmad „Pogledaj", „Izmeni" i
 * „Obriši" su uklonjena sa kartice (izmena i brisanje su na samoj stranici
 * knjige), pa je klik na bilo koji deo kartice jedina i očekivana radnja.
 * Zato je nosač <a>, a ne <div> sa `onclick`: radi i srednji klik (novi
 * tab), i tastatura, i pregled odredišta u statusnoj traci.
 *
 * Omotač `.book-omot` postoji radi jednakog ponašanja sa izdvojenim
 * sekcijama, koje preko kartice nose i dugme „×".
 */
function renderBookCard(knjiga) {
    const dostupna = knjiga.slobodni_primerci_count > 0 || knjiga.je_eknjiga;
    const rezervisana = knjiga.rezervisani_primerci_count > 0 && !dostupna;

    const godina = knjiga.godina_izdavanja ?? 'n/a';

    const zanroviHTML = knjiga.zanrovi && knjiga.zanrovi.length > 0
        ? knjiga.zanrovi.map(z => `<span class="zanr-tag">${z.naziv}</span>`).join('')
        : '';

    let statusText = 'E-knjiga';
    let statusClass = 'status-available';

    if (!knjiga.je_eknjiga) {
        if (dostupna) {
            statusText = 'Dostupna';
            statusClass = 'status-available';
        } else if (rezervisana) {
            // Član vidi samo "Nedostupna"; admin/bibliotekar vidi tačan status
            statusText = isAdmin ? 'Rezervisana' : 'Nedostupna';
            statusClass = 'status-unavailable';
        } else {
            statusText = isAdmin ? 'Zadužena' : 'Nedostupna';
            statusClass = 'status-unavailable';
        }
    }

    const badgesHTML = `
        ${knjiga.je_najcitanija ? '<span class="featured-badge featured-badge-hot"><i class="fa-solid fa-fire"></i> Najčitanija</span>' : ''}
        ${knjiga.je_najnovija ? '<span class="featured-badge featured-badge-new"><i class="fa-solid fa-star"></i> Najnovija</span>' : ''}
    `;

    return `
        <div class="book-omot">
            <a class="book" href="${hrefKnjige(knjiga.id)}">
                <div class="book-slika">
                    ${(jeUlogovan || knjiga.je_eknjiga) ? `<span class="book-status ${statusClass}">
                        ${statusText}
                    </span>` : ''}
                    ${(knjiga.je_najcitanija || knjiga.je_najnovija) ? `<div class="featured-badges">${badgesHTML}</div>` : ''}
                    <img src="${knjiga.slika ? '/' + knjiga.slika : '/images/default.png'}" alt="${knjiga.naziv_knjige}">
                </div>

                <div class="book-info">
                    <h2>${knjiga.naziv_knjige}</h2>
                    <p>${knjiga.autor} (${godina})</p>
                    ${zanroviHTML ? `<div class="zanrovi-tags">${zanroviHTML}</div>` : ''}
                </div>
            </a>
        </div>
    `;
}

/**
 * Manja kartica za izdvojene sekcije („Najčitanije" i „Najnovije").
 *
 * Nosi samo ono po čemu se knjiga prepoznaje — sliku, naslov, autora i
 * oznaku dostupnosti — jer su to izbori koje korisnik pregleda „na brzinu";
 * žanrovi i puna veličina slike ostaju kartici u „Ostalim knjigama"
 * (renderBookCard). Ponašanje je isto: cela kartica je link ka stranici
 * knjige, a „×" (ukloni iz najnovijih) stoji izvan linka, u omotaču.
 */
function renderFeaturedCard(knjiga, opcije = {}) {
    const godina = knjiga.godina_izdavanja ?? 'n/a';
    const slika = knjiga.slika ? '/' + knjiga.slika : '/images/default.png';

    let statusText = 'E-knjiga';
    let statusClass = 'status-available';

    if (!knjiga.je_eknjiga) {
        const dostupna = knjiga.slobodni_primerci_count > 0;
        const rezervisana = knjiga.rezervisani_primerci_count > 0 && !dostupna;

        if (dostupna) {
            statusText = 'Dostupna';
            statusClass = 'status-available';
        } else if (rezervisana) {
            // Član vidi samo "Nedostupna"; admin/bibliotekar vidi tačan status
            statusText = isAdmin ? 'Rezervisana' : 'Nedostupna';
            statusClass = 'status-unavailable';
        } else {
            statusText = isAdmin ? 'Zadužena' : 'Nedostupna';
            statusClass = 'status-unavailable';
        }
    }

    const ukloniBtn = opcije.ukloniIzNajnovijih
        ? `<button type="button" class="featured-delete-btn" title="Ukloni iz najnovijih"
                   onclick="ukloniIzNajnovije(${knjiga.id})">
               <i class="fa-solid fa-xmark"></i>
           </button>`
        : '';

    return `
        <div class="featured-omot">
            ${ukloniBtn}
            <a class="featured-card" href="${hrefKnjige(knjiga.id)}">
                <div class="featured-card__slika">
                    ${(jeUlogovan || knjiga.je_eknjiga) ? `<span class="book-status book-status--mala ${statusClass}">
                        ${statusText}
                    </span>` : ''}
                    <img src="${slika}" alt="${knjiga.naziv_knjige}">
                </div>
                <div class="featured-card-info">
                    <h3>${knjiga.naziv_knjige}</h3>
                    <p>${knjiga.autor} (${godina})</p>
                </div>
            </a>
        </div>
    `;
}

async function fetchBooks() {
    loading.style.display = 'block';
    content.style.display = 'none';
    noResults.style.display = 'none';
    grid.innerHTML = '';

    try {
        const url = `/api/knjige?${buildFilterParams()}&per_page=${SVE_KNJIGE}`;

        const res = await fetch(url);
        const data = await res.json();

        loading.style.display = 'none';

        if (!data.data || data.data.length === 0) {
            noResults.style.display = 'block';
            return;
        }

        content.style.display = 'block';
        grid.innerHTML = data.data.map(knjiga => renderBookCard(knjiga)).join('');

    } catch(err) {
        console.error('Error:', err);
        loading.style.display = 'none';
        noResults.textContent = 'Greška prilikom učitavanja.';
        noResults.style.display = 'block';
    }
}

// Jedinstveni rezultati pretrage/sortiranja - obuhvataju obične, najčitanije i najnovije knjige
async function fetchSearchResults() {
    searchResultsLoading.style.display = 'block';
    searchResultsContent.style.display = 'none';
    searchResultsEmpty.style.display = 'none';
    searchResultsCount.textContent = '';
    searchResultsGrid.innerHTML = '';

    try {
        const url = `/api/knjige?${buildFilterParams()}&include_featured=1&per_page=${SVE_KNJIGE}`;

        const res = await fetch(url);
        const data = await res.json();

        searchResultsLoading.style.display = 'none';

        if (!data.data || data.data.length === 0) {
            searchResultsEmpty.textContent = 'Nema pronađenih knjiga.';
            searchResultsEmpty.style.display = 'block';
            return;
        }

        searchResultsContent.style.display = 'block';
        searchResultsCount.textContent = `Pronađeno ${data.total} knjiga`;
        searchResultsGrid.innerHTML = data.data.map(knjiga => renderBookCard(knjiga)).join('');

    } catch(err) {
        console.error('Error:', err);
        searchResultsLoading.style.display = 'none';
        searchResultsEmpty.textContent = 'Greška prilikom učitavanja.';
        searchResultsEmpty.style.display = 'block';
    }
}

function debounceFetch() {
    clearTimeout(timeout);
    timeout = setTimeout(refreshView, 300);
}

searchInput.addEventListener('input', debounceFetch);
searchAutor.addEventListener('input', debounceFetch);
filterZanr.addEventListener('change', debounceFetch);
sortSelect.addEventListener('change', debounceFetch);


// =====================
// NAJČITANIJE KNJIGE
// =====================
async function loadNajcitanije() {
    const gridEl = document.getElementById('najcitanijeGrid');
    try {
        const res = await fetch(`/api/najcitanije-knjige?${buildFilterParams()}`);
        const data = await res.json();

        if (!data.length) {
            gridEl.innerHTML = '<p class="featured-empty">Nema podataka u arhivi.</p>';
            return;
        }

        gridEl.innerHTML = data.map(k => renderFeaturedCard(k)).join('');
    } catch(err) {
        gridEl.innerHTML = '<p class="featured-empty">Greška pri učitavanju.</p>';
    }
}

// =====================
// NAJNOVIJE KNJIGE
// =====================
async function loadNajnovije() {
    const gridEl = document.getElementById('najnovijeGrid');
    try {
        const res = await fetch(`/api/najnovije-knjige?${buildFilterParams()}`);
        const data = await res.json();

        if (!data.length) {
            gridEl.innerHTML = '<p class="featured-empty">Nema dodatih knjiga.</p>';
            return;
        }

        gridEl.innerHTML = data.map(k => renderFeaturedCard(k, { ukloniIzNajnovijih: isAdmin })).join('');
    } catch(err) {
        gridEl.innerHTML = '<p class="featured-empty">Greška pri učitavanju.</p>';
    }
}

// =====================
// MODAL ZA UPRAVLJANJE NAJNOVIJIM
// =====================
let sveknjigeCached = [];

async function openNajnovijeModal() {
    document.getElementById('najnovijeModal').classList.add('active');

    if (!sveknjigeCached.length) {
        try {
            const res = await fetch('/api/knjige?per_page=5000');
            const data = await res.json();
            sveknjigeCached = data.data || [];
        } catch(err) {
            console.error('Greška:', err);
        }
    }

    await renderNajnovijeListu(sveknjigeCached);

    document.getElementById('najnovijeSearch').oninput = async function() {
        const q = this.value.toLowerCase();
        const filtered = sveknjigeCached.filter(k =>
            k.naziv_knjige.toLowerCase().includes(q) ||
            k.autor.toLowerCase().includes(q)
        );
        await renderNajnovijeListu(filtered);
    };
}

async function renderNajnovijeListu(knjige) {
    const container = document.getElementById('najnovijeListaKnjiga');

    // Dohvati trenutne ID-ove iz baze
    let currentIds = [];
    try {
        const res = await fetch('/api/najnovije-knjige');
        const data = await res.json();
        currentIds = data.map(k => k.id);
    } catch(err) {}

    const ukupno = currentIds.length;

    if (!knjige.length) {
        container.innerHTML = '<p class="featured-empty">Nema knjiga.</p>';
        return;
    }

    container.innerHTML = `
        <p class="najnovije-brojac">${ukupno}/10 knjiga u sekciji</p>
        ${knjige.map(k => {
            const dodata = currentIds.includes(k.id);
            const slika = k.slika ? '/' + k.slika : '/images/default.png';
            const disabledDodaj = !dodata && ukupno >= 10;

            return `
                <div class="najnovije-row">
                    <img src="${slika}" alt="${k.naziv_knjige}">
                    <div class="najnovije-row-info">
                        <strong>${k.naziv_knjige}</strong>
                        <span>${k.autor}</span>
                    </div>
                    <button class="${dodata ? 'btn-ukloni' : 'btn-dodaj'}"
                            ${disabledDodaj ? 'disabled title="Maksimum 10 knjiga"' : ''}
                            onclick="toggleNajnovije(${k.id}, this)">
                        ${dodata ? 'Ukloni' : 'Dodaj'}
                    </button>
                </div>
            `;
        }).join('')}
    `;
}

async function toggleNajnovije(id, btn) {
    const uklanjanje = btn.classList.contains('btn-ukloni');

    const poruka = uklanjanje
        ? 'Da li ste sigurni da želite da uklonite ovu knjigu iz sekcije Najnovije?'
        : 'Da li ste sigurni da želite da dodate ovu knjigu u sekciju Najnovije?';

    if (!await confirmDialog({ type: 'primary', title: 'Najnovije knjige', message: poruka, confirmText: 'Potvrdi' })) return;

    btn.disabled = true;

    try {
        const res = await fetch(`/api/admin/najnovije-knjige/${id}`, {
            method: uklanjanje ? 'DELETE' : 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            credentials: 'include'
        });

        const data = await res.json();

        if (data.success) {
            // Refresh sve tri sekcije paralelno
            await Promise.all([
                loadNajnovije(),
                loadNajcitanije(),
                fetchBooks()  //← ovo osvežava glavni grid
            ]);
            // Refresh liste u modalu
            const q = document.getElementById('najnovijeSearch').value.toLowerCase();
            const filtered = q
                ? sveknjigeCached.filter(k =>
                    k.naziv_knjige.toLowerCase().includes(q) ||
                    k.autor.toLowerCase().includes(q))
                : sveknjigeCached;
            await renderNajnovijeListu(filtered);
        } else {
            showToast(data.message, 'error');
            btn.disabled = false;
        }
    } catch(err) {
        showToast('Greška pri ažuriranju.', 'error');
        btn.disabled = false;
    }
}

async function ukloniIzNajnovije(id) {

    if (!await confirmDialog({ type: 'warning', title: 'Najnovije knjige', message: 'Da li ste sigurni da želite da uklonite ovu knjigu iz sekcije Najnovije?', confirmText: 'Ukloni' })) return;

    try {
        const res = await fetch(`/api/admin/najnovije-knjige/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            credentials: 'include'
        });

        const data = await res.json();
        if (data.success) {
            // Refresh sve tri sekcije paralelno
            await Promise.all([
                loadNajnovije(),
                fetchBooks()  // ← glavni grid
            ]);
        } else {
            showToast(data.message, 'error');
        }
    } catch(err) {
        showToast('Greška pri uklanjanju.', 'error');
    }
}

function closeNajnovijeModal() {
    document.getElementById('najnovijeModal').classList.remove('active');
}

// Dodaj u CSS
// .najnovije-brojac { font-size: 13px; color: #718096; margin-bottom: 12px; }

/* showToast je globalizovan u notifications.js */

/* Osvežavanje katalog: dodavanje, izmena i brisanje knjige rade se na
   stranici inventarne knjige i na stranici knjige, pa katalog samo ponovo
   povuče podatke kada se korisnik na njega vrati.

   `pageshow` sa `persisted` pokriva povratak dugmetom „nazad": pregledač
   tada vraća stranicu iz keša (bfcache) BEZ ponovnog izvršavanja skripti,
   pa bi se bez ovoga videla obrisana ili neizmenjena knjiga. */
window.addEventListener('pageshow', (e) => {
    if (e.persisted) refreshView();
});

window.addEventListener('load', () => {
    loadZanrovi();

    // Čitaj parametre iz URL-a
    const params = new URLSearchParams(window.location.search);
    
    if (params.get('search')) searchInput.value = params.get('search');
    if (params.get('autor')) searchAutor.value = params.get('autor');
    if (params.get('zanr')) {
        // Zanr se postavlja nakon što se učitaju žanrovi
        window._pendingZanr = params.get('zanr');
    }
    if (params.get('sort')) sortSelect.value = params.get('sort');

    refreshView();
});

