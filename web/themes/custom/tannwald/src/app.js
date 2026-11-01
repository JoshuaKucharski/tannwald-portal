// Einstieg des Themes myTannwald: Alpine 3 global starten (defer-Skript, siehe tannwald.libraries.yml).
// Komponenten nutzen x-data direkt im Twig; Plugins und Stores kommen hier dazu.
import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Statusmeldungen: automatisch nach 8 Sekunden ausblenden, Schliessen-Button bleibt.
Alpine.data('toast', () => ({
  open: true,
  init() {
    window.setTimeout(() => { this.open = false; }, 8000);
  },
}));

// Live-Suche (5.2, SDC tannwald:search-live): GET liveUrl?q=&modus=&maschine_nid=, Debounce über
// @input.debounce.200ms im Twig, Abbruch laufender Anfragen, Top 4, Tastatur (Pfeile, Enter, Escape).
Alpine.data('searchLive', (config) => ({
  query: '',
  results: [],
  duration: 0,
  open: false,
  index: -1,
  notice: '',
  controller: null,
  init() {
    this.query = this.$root.querySelector('input[type="search"]').value || '';
  },
  modus() {
    const checked = document.querySelector(`input[name="${config.modusInput}"]:checked`);
    return checked ? checked.value : 'hybrid';
  },
  async search() {
    const q = this.query.trim();
    if (this.controller) this.controller.abort();
    if (q.length < 2) { this.close(); this.results = []; return; }
    this.controller = new AbortController();
    const params = new URLSearchParams({ q, modus: this.modus() });
    if (config.maschineNid) params.set('maschine_nid', String(config.maschineNid));
    try {
      const response = await fetch(`${config.liveUrl}?${params}`, { credentials: 'same-origin', signal: this.controller.signal, headers: { Accept: 'application/json' } });
      if (response.status === 429) { this.results = []; this.notice = config.noticeBusy; this.open = true; return; }
      if (!response.ok) { this.close(); return; }
      const data = await response.json();
      this.results = (data.treffer || []).slice(0, 4);
      this.duration = data.dauer_ms || 0;
      this.notice = this.results.length ? '' : config.noticeEmpty || '';
      this.index = -1;
      this.open = this.results.length > 0 || this.notice !== '';
    } catch (error) {
      if (error.name !== 'AbortError') this.close();
    }
  },
  onKey(event) {
    if (!this.open) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); this.index = Math.min(this.index + 1, this.results.length - 1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); this.index = Math.max(this.index - 1, -1); }
    else if (event.key === 'Enter' && this.index >= 0) { event.preventDefault(); window.location.href = this.results[this.index].url; }
    else if (event.key === 'Escape') { this.close(); }
  },
  reopen() { if (this.results.length) this.open = true; },
  close() { this.open = false; this.index = -1; },
  clear() { this.query = ''; this.results = []; this.close(); this.$root.querySelector('input[type="search"]').focus(); },
}));

/* 5.3: hier anhängen */

// Start zuletzt: alle Alpine.data()-Registrierungen (5.1, 5.2 und A's 5.3-Hunk) müssen davor stehen.
Alpine.start();
