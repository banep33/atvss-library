{{-- ═══════════════════════════════════════════════════════════════════
     MODAL ZA DODAVANJE / IZMENU KNJIGE

     Jedan obrazac za sve tri stranice koje menjaju knjigu — katalog
     (knjige/index), stranicu knjige (knjige/show) i inventarnu knjigu
     (admin/inventarna-knjiga). Ranije je isti markup stajao prepisan u
     dva view-a, pa je izmena polja morala da se radi dva puta.

     Ponašanje (otvaranje, popunjavanje, čuvanje, brisanje) je u
     public/js/knjiga-forma.js; stranica samo kaže ŠTA da se osveži posle
     uspešne radnje (window.KnjigaForma.posleCuvanja / posleBrisanja).

     Stilovi dolaze iz css/knjige-modal.css (show, inventarna) odnosno iz
     css/knjige-index.css (katalog) — obe datoteke nose iste klase.

     novalidate: proveru obaveznih polja radi proveriObavezna() iz
     notifications.js (crvena ivica + poruka ispod polja + dijalog).
     ═══════════════════════════════════════════════════════════════════ --}}
<div id="bookModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Dodaj Knjigu</h2>
            <button type="button" class="close-btn" onclick="closeModal()">&times;</button>
        </div>

        <form id="bookForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" id="bookId">

            <div class="form-grid">
                <div class="form-group">
                    <label>Inventarski broj *</label>
                    <input type="text" id="in_broj" required>
                </div>

                <div class="form-group">
                    <label>Signatura *</label>
                    <input type="text" id="signatura" required>
                </div>

                <div class="form-group">
                    <label>Datum *</label>
                    <input type="date" id="datum" required>
                </div>

                <div class="form-group">
                    <label>Autor *</label>
                    <input type="text" id="autor" required>
                </div>

                <div class="form-group full-width">
                    <label>Naziv knjige *</label>
                    <input type="text" id="naziv_knjige" required>
                </div>

                <div class="form-group">
                    <label>Mesto izdavanja *</label>
                    <input type="text" id="mesto_izdavanja" placeholder="npr. Beograd" required>
                </div>

                <div class="form-group">
                    <label>Godina izdavanja *</label>
                    <input type="number" id="godina_izdavanja" placeholder="npr. 2023" min="1800" max="2100" required>
                </div>

                <div class="form-group">
                    <label>Izdavač *</label>
                    <input type="text" id="izdavac" required>
                </div>

                <div class="form-group">
                    <label>Način nabavke *</label>
                    <input type="text" id="nacin_nabavke" required>
                </div>

                <div class="form-group">
                    <label>ISBN</label>
                    <input type="text" id="isbn">
                </div>

                <div class="form-group full-width">
                    <label>Slika knjige</label>
                    <div id="currentSlikaPreview" class="slika-preview" style="display: none;">
                        <p class="slika-preview__naslov">Postojeća slika:</p>
                        <img id="currentSlikaImg" src="" alt="Postojeća slika knjige">
                        <p id="currentSlikaName" class="slika-preview__ime"></p>
                    </div>
                    <input type="file" id="slika" accept="image/*">
                    <small>Odaberite novu sliku sa vašeg računara (opciono)</small>
                </div>

                <div class="form-group full-width">
                    <label>Žanrovi</label>
                    <div id="zanroviCheckboxes" class="checkbox-group"></div>
                </div>

                <div class="form-group full-width">
                    {{-- Ista klasa koju nose i kvadratići za žanrove: bez nje
                         važi .form-group input { width: 100% } pa se kvadratić
                         razvlači preko celog reda i pada iznad teksta. --}}
                    <label class="checkbox-label">
                        <input type="checkbox" id="je_eknjiga"> Ovo je e-knjiga
                    </label>
                </div>

                <div class="form-group full-width" id="eknjigaLinkGroup" style="display:none;">
                    <label>Link ka e-knjizi</label>
                    <input type="url" id="eknjiga_link" placeholder="https://...">
                </div>

                {{-- Broj primeraka — samo pri DODAVANJU fizičke knjige. Pri
                     izmeni se sakriva: primerci se dodaju i brišu na stranici
                     knjige, a ne kroz ovaj obrazac. --}}
                <div class="form-group" id="brojPrimerakaGroup">
                    <label>Broj primeraka *</label>
                    <input type="number" id="broj_primeraka" min="1" max="50" value="1" required>
                    <small>Možete dodati primerke kasnije na stranici knjige</small>
                </div>

                <div class="form-group full-width">
                    <label>Opis</label>
                    <textarea id="opis" rows="4"></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeModal()">Otkaži</button>
                <button type="submit" class="btn-save">Sačuvaj</button>
            </div>
        </form>
    </div>
</div>
