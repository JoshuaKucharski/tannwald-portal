// Such-Overlay aus der Kopfzeile (tw2-13, SDC tannwald:search-overlay): öffnet über das Window-Ereignis
// tannwald:search-open (Lupe), ⌘K/Ctrl+K und „/“ ausserhalb von Feldern; sheetMixin liefert Fokusfalle, inert,
// Scroll-Lock, Esc und Fokus zurück zum Auslöser. Live-Resultate wie searchLive (GET liveUrl?q=&modus=, Debounce
// über @input.debounce im Template, Top 6, Pfeiltasten/Enter); Filter-Chips wirken auf der Ergebnisseite (Enter).
import Alpine from 'alpinejs';
import { sheetMixin } from './sheet.js';

const EDITABLE = 'input, textarea, select, [contenteditable=""], [contenteditable="true"]';

Alpine.data('searchOverlay', (config) => ({
  ...sheetMixin(),
  query: '',
  modus: 'hybrid',
  results: [],
  duration: 0,
  index: -1,
  notice: '',
  controller: null,
  openFrom(event) {
    const opener = event?.detail?.opener || document.querySelector('.site-search');
    this.openSheet();
    if (opener) this.opener = opener;
  },
  onKeydown(event) {
    if (this.open) { this.onSheetKeydown(event); return; }
    const key = event.key.toLowerCase();
    if ((event.metaKey || event.ctrlKey) && key === 'k' && !event.altKey && !event.shiftKey) {
      event.preventDefault();
      this.openFrom(null);
      return;
    }
    if (key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey && !(event.target instanceof Element && event.target.closest(EDITABLE))) {
      event.preventDefault();
      this.openFrom(null);
    }
  },
  labelTop() { return config.labelTop || ''; },
  async search() {
    const q = this.query.trim();
    if (this.controller) this.controller.abort();
    if (q.length < 2) { this.results = []; this.notice = ''; this.index = -1; return; }
    this.controller = new AbortController();
    const params = new URLSearchParams({ q, modus: this.modus });
    try {
      const response = await fetch(`${config.liveUrl}?${params}`, { credentials: 'same-origin', signal: this.controller.signal, headers: { Accept: 'application/json' } });
      if (response.status === 429) { this.results = []; this.notice = config.noticeBusy; return; }
      if (!response.ok) { this.results = []; this.notice = ''; return; }
      const data = await response.json();
      this.results = (data.treffer || []).slice(0, 6);
      this.duration = data.dauer_ms || 0;
      this.notice = this.results.length ? '' : (config.noticeEmpty || '');
      this.index = -1;
    } catch (error) {
      if (error.name !== 'AbortError') { this.results = []; this.notice = ''; }
    }
  },
  onInputKey(event) {
    if (!this.results.length) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); this.index = Math.min(this.index + 1, this.results.length - 1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); this.index = Math.max(this.index - 1, -1); }
    else if (event.key === 'Enter' && this.index >= 0) { event.preventDefault(); window.location.href = this.results[this.index].url; }
  },
  setModus(modus) {
    this.modus = modus;
    this.$nextTick(() => { const active = this.$root.querySelector(`[data-modus="${modus}"]`); if (active) active.focus(); });
    if (this.query.trim().length >= 2) this.search();
  },
  onSegmentKey(event) {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
    event.preventDefault();
    this.setModus(this.modus === 'hybrid' ? 'volltext' : 'hybrid');
  },
  clear() { this.query = ''; this.results = []; this.notice = ''; this.index = -1; this.$refs.initial.focus(); },
  onSubmit(event) { if (this.query.trim() === '') { event.preventDefault(); this.$refs.initial.focus(); } },
  // Leerzustand: schliesst das Overlay und öffnet A's Launcher (mit Frage: sendet sie).
  ask(frage) {
    this.closeSheet();
    this.$nextTick(() => window.dispatchEvent(new CustomEvent(config.chatEvent, { detail: frage ? { frage } : {} })));
  },
}));
