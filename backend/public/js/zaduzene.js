function prikaziPreostaloVreme(el) {
    const rok = new Date(el.getAttribute('data-rok'));
    const sad = new Date();
    const razlikaMs = rok - sad;

    const sati = Math.floor(Math.abs(razlikaMs) / (1000 * 60 * 60));
    const minuti = Math.floor((Math.abs(razlikaMs) % (1000 * 60 * 60)) / (1000 * 60));

    if (razlikaMs < 0) {
        el.setAttribute('title', `Kasnite: ${sati}h ${minuti}m`);
    } else {
        el.setAttribute('title', `Preostalo: ${sati}h ${minuti}m`);
    }
}