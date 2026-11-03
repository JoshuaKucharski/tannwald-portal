// Assistent (1.3, SDC tannwald:chat): Darstellung als Panel (Spalte auf dem Desktop) oder Sheet (Phone).
// Der Store „chat“ (A, Hunk 5.3 in app.js) bleibt der Vertrag zu den Endpunkten (Bericht 2010): ask(),
// feedback(), support(), history[], busy, limitNotice. Hier nur Eingabe, Sheet-Verhalten, Zitat-Links,
// Beispielfragen und die Brücke tannwald:ask von /suche.
import Alpine from 'alpinejs';
import { sheetMixin } from './sheet.js';

// Zustand des globalen Panels (Nachtrag 3 tw2-11): 'open' | 'closed' je Browser-Sitzung.
const STATE_KEY = 'tannwald_chat_open';

Alpine.data('chatPanel', (config) => ({
  // sheet (Nachtrag 3 tw2-11): bis md ein Sheet (modal), ab md ein Panel über dem Launcher (nicht modal).
  ...sheetMixin({ sheetQuery: config.mode === 'panel' ? '(max-width: 1279px)' : config.mode === 'embedded' ? '(max-width: 767px)' : '(max-width: 767px)' }),
  input: '',
  mode: config.mode || 'panel',
  labels: config.labels,
  uiLangcode: config.uiLangcode,
  init() {
    this.$store.chat.configure(config);
    this.$watch('$store.chat.history.length', () => this.scrollDown());
    // Verlauf bleibt beim Seitenwechsel (sessionStorage, Nachtrag 3 (c)).
    this.$watch('$store.chat.history', () => this.$store.chat.persist());
    // Zustand des globalen Panels (Nachtrag 3 (c)): beim ersten Besuch in der Sitzung einmal offen, danach gemerkt;
    // nur am Desktop (das Phone-Sheet ist modal und öffnet nie von selbst).
    if (config.mode === 'sheet' && !this.sheetActive()) {
      const stored = window.sessionStorage.getItem(STATE_KEY);
      if (stored === null || stored === 'open') {
        this.open = true;
        window.sessionStorage.setItem(STATE_KEY, 'open');
        this.$nextTick(() => this.scrollDown());
      }
    }
    // Breiter als der Sheet-Bereich: das offene Sheet wird zur Spalte.
    if (config.mode === 'panel' || config.mode === 'embedded') {
      const media = window.matchMedia(config.mode === 'panel' ? '(min-width: 1280px)' : '(min-width: 768px)');
      media.addEventListener('change', (event) => { if (event.matches && this.open) this.closeSheet(); });
    }
  },
  // Als Spalte sichtbar (Panel auf xl+, eingebettet auf md+)? Dann braucht es weder Launcher noch Sheet.
  inline() {
    return (this.mode === 'panel' && window.matchMedia('(min-width: 1280px)').matches)
      || (this.mode === 'embedded' && window.matchMedia('(min-width: 768px)').matches);
  },
  disabled() { return this.$store.chat.busy || !!this.$store.chat.limitNotice; },
  toggle() { this.open ? this.closeSheet() : this.openSheet(); },
  close() { this.closeSheet(); this.$store.chat.abort(); },
  // Nachtrag 4 (a): beim Öffnen per Klick sofort ins Fragefeld (nicht beim automatischen Öffnen, das openSheet() nicht
  // durchläuft); ist das Feld gesperrt (Limit), bleibt der Fokus auf dem Schliessen-Button aus dem Mixin.
  onOpened() {
    this.scrollDown();
    if (this.mode === 'sheet') window.sessionStorage.setItem(STATE_KEY, 'open');
    const input = this.$refs.input;
    if (input && !input.disabled) input.focus({ preventScroll: true });
  },
  onClosed() { if (this.mode === 'sheet') window.sessionStorage.setItem(STATE_KEY, 'closed'); },
  // Brücke von /suche (5.2): $dispatch('tannwald:ask', {frage}) öffnet das Sheet (falls nötig) und fragt.
  onAsk(detail) {
    const frage = detail && detail.frage ? String(detail.frage) : '';
    if (!this.inline()) this.openSheet();
    if (frage) { this.input = frage; this.send(); }
    else this.$nextTick(() => this.$refs.input?.focus());
  },
  ask(frage) { this.onAsk({ frage }); },
  // Nachtrag 2 (a): Feld wächst mit dem Text (bis max-height aus dem CSS, 8 Zeilen), schrumpft nach dem Senden zurück.
  grow() {
    const el = this.$refs.input;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
  },
  onEnter(event) {
    if (event.shiftKey) return;
    event.preventDefault();
    this.send();
  },
  async send() {
    const frage = this.input.trim();
    if (!frage || this.disabled()) return;
    this.input = '';
    this.$nextTick(() => this.grow());
    await this.$store.chat.ask(frage);
    this.scrollDown();
  },
  scrollDown() { this.$nextTick(() => { const h = this.$refs.history; if (h) h.scrollTop = h.scrollHeight; }); },
  chapter(q) {
    const path = String(q.heading_path || '').split(' › ');
    return path[path.length - 1] || '';
  },
  sourceLabel(q) {
    const chapter = this.chapter(q);
    return `[${q.n}] ${q.titel} v${q.version}` + (chapter ? ` · ${this.labels.chapter} ${chapter}` : '');
  },
  languageName(code) { return (config.languageNames && config.languageNames[code]) || String(code).toUpperCase(); },
  supportCreated(entry) { return this.labels.supportCreated.replace('@id', entry.support_id); },
  // [n] im eingesetzten Server-HTML per DOM in Links wandeln (nach x-html, nicht per Regex über rohes HTML).
  linkCitations(root, entry) {
    const sources = new Map((entry.quellen || []).map((q) => [String(q.n), q]));
    if (!sources.size) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: (n) => (n.parentElement.closest('a') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT) });
    const nodes = [];
    while (walker.nextNode()) if (/\[\d+\]/.test(walker.currentNode.nodeValue)) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      const fragment = document.createDocumentFragment();
      let last = 0;
      const text = node.nodeValue;
      text.replace(/\[(\d+)\]/g, (match, n, offset) => {
        fragment.appendChild(document.createTextNode(text.slice(last, offset)));
        const q = sources.get(n);
        if (q) {
          const a = document.createElement('a');
          a.href = q.url; a.className = 'chat-cite'; a.dataset.n = n; a.title = this.sourceLabel(q); a.textContent = match;
          fragment.appendChild(a);
        } else {
          fragment.appendChild(document.createTextNode(match));
        }
        last = offset + match.length;
        return match;
      });
      fragment.appendChild(document.createTextNode(text.slice(last)));
      node.parentNode.replaceChild(fragment, node);
    });
  },
}));
