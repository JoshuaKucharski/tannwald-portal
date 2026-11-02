// Einstieg des Themes myTannwald: Alpine 3 global starten (defer-Skript, siehe tannwald.libraries.yml).
// Komponenten nutzen x-data direkt im Twig; Plugins und Stores kommen hier dazu.
import Alpine from 'alpinejs';
import toc from './modules/toc.js';
import docHistory from './modules/history.js';

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

// Assistent (5.3, SDC tannwald:chat). Store „chat“: Sitzung (UUID in sessionStorage), Verlauf, CSRF-Token,
// ask()/feedback()/support() gegen /api/assistant*; Komponente „chat“: Eingabe, Sheet, Zitat-Links, Brücke tannwald:ask.
const chatSession = () => {
  const key = 'tannwald_chat_session';
  let id = window.sessionStorage.getItem(key);
  if (!id) { id = window.crypto.randomUUID(); window.sessionStorage.setItem(key, id); }
  return id;
};

Alpine.store('chat', {
  config: null,
  session: chatSession(),
  history: [],
  busy: false,
  elapsed: 0,
  limitNotice: null,
  token: null,
  controller: null,
  timer: null,
  configure(config) {
    this.config = config;
    if (config.limitNotice) this.limitNotice = config.limitNotice;
  },
  async csrfToken(renew = false) {
    if (!this.token || renew) {
      const response = await fetch(this.config.tokenEndpoint, { credentials: 'same-origin' });
      this.token = (await response.text()).trim();
    }
    return this.token;
  },
  // POST als JSON mit Session-Cookie und X-CSRF-Token; bei 403 Token einmal erneuern (masquerade erneuert den Seed).
  async post(url, payload, signal) {
    const attempt = async (renew) => fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      signal,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': await this.csrfToken(renew) },
      body: JSON.stringify(payload),
    });
    let response = await attempt(false);
    if (response.status === 403) response = await attempt(true);
    return response;
  },
  async ask(frage) {
    const question = (frage || '').trim();
    if (!question || this.busy || this.limitNotice) return null;
    const labels = this.config.labels;
    const entry = { id: Date.now(), frage: question, status: 'pending', antwort_html: '', text: '', quellen: [], log_id: null, bewertung: null, support_id: null, supporting: false, support_error: '', dauer_ms: null };
    this.history.push(entry);
    this.busy = true;
    this.elapsed = 0;
    const started = Date.now();
    this.timer = window.setInterval(() => { this.elapsed = Math.round((Date.now() - started) / 1000); }, 1000);
    this.controller = new AbortController();
    const stored = this.history[this.history.length - 1];
    try {
      const payload = { frage: question, session: this.session };
      if (this.config.maschineNid) payload.maschine_nid = this.config.maschineNid;
      const response = await this.post(this.config.endpoint, payload, this.controller.signal);
      const data = await response.json().catch(() => ({}));
      stored.dauer_ms = Date.now() - started;
      if (response.status === 429) {
        stored.status = 'error'; stored.text = labels.busy;
      } else if (response.status === 503 || data.status === 'limit') {
        stored.status = 'limit'; stored.text = data.hinweis || labels.unreachable; this.limitNotice = data.hinweis || labels.unreachable;
      } else if (!response.ok) {
        stored.status = 'error'; stored.text = data.fehler || labels.unreachable;
      } else {
        stored.status = data.status; stored.antwort_html = data.antwort_html || ''; stored.quellen = data.quellen || []; stored.log_id = data.log_id ?? null;
      }
    } catch (error) {
      stored.status = 'error';
      stored.text = error.name === 'AbortError' ? '' : labels.unreachable;
      if (error.name === 'AbortError') this.history.splice(this.history.indexOf(stored), 1);
    } finally {
      this.busy = false;
      window.clearInterval(this.timer);
      this.controller = null;
    }
    return stored;
  },
  async feedback(entry, bewertung) {
    if (entry.bewertung !== null || !entry.log_id) return;
    entry.bewertung = bewertung;
    try { await this.post(this.config.feedbackEndpoint, { log_id: entry.log_id, bewertung }); } catch { /* Rückmeldung ist freiwillig */ }
  },
  async support(entry) {
    if (entry.support_id || entry.supporting || !entry.log_id) return;
    entry.supporting = true;
    entry.support_error = '';
    try {
      const payload = { log_id: entry.log_id };
      if (this.config.maschineNid) payload.maschine_nid = this.config.maschineNid;
      const response = await this.post(this.config.supportEndpoint, payload);
      const data = await response.json().catch(() => ({}));
      if (response.ok && data.support_anfrage_id) entry.support_id = data.support_anfrage_id;
      else entry.support_error = data.fehler || this.config.labels.unreachable;
    } catch { entry.support_error = this.config.labels.unreachable; }
    finally { entry.supporting = false; }
  },
  abort() { if (this.controller) this.controller.abort(); },
});

Alpine.data('chat', (config) => ({
  open: false,
  input: '',
  mode: config.mode || 'embedded',
  labels: config.labels,
  uiLangcode: config.uiLangcode,
  init() {
    this.$store.chat.configure(config);
    // Sheet: Scroll-Lock des Hintergrunds, solange es offen ist (Phone bzw. Sheet-Modus).
    this.$watch('open', (open) => {
      const sheet = this.mode === 'sheet' || window.matchMedia('(max-width: 767px)').matches;
      document.body.classList.toggle('overflow-hidden', open && sheet);
      if (open) this.$nextTick(() => { this.$refs.input?.focus(); this.scrollDown(); });
    });
    this.$watch('$store.chat.history.length', () => this.scrollDown());
  },
  disabled() { return this.$store.chat.busy || !!this.$store.chat.limitNotice; },
  toggle() { this.open = !this.open; },
  close() { this.open = false; this.$store.chat.abort(); },
  // Brücke von /suche (F, 5.2): $dispatch('tannwald:ask', {frage}) öffnet das Sheet und fragt.
  onAsk(detail) {
    const frage = detail && detail.frage ? String(detail.frage) : '';
    this.open = true;
    if (frage) { this.input = frage; this.send(); } else { this.$nextTick(() => this.$refs.input?.focus()); }
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
    await this.$store.chat.ask(frage);
    this.scrollDown();
  },
  scrollDown() { this.$nextTick(() => { const h = this.$refs.history; if (h) h.scrollTop = h.scrollHeight; }); },
  sourceLabel(q) {
    const path = String(q.heading_path || '').split(' › ');
    const chapter = path[path.length - 1] || '';
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

// Review-Karte (5.3, SDC tannwald:review-actions): Wortzähler über D's Textarea [data-review-body].
Alpine.data('reviewActions', (config) => ({
  words: config.initial || 0,
  init() {
    const area = this.$root.querySelector('[data-review-body]');
    if (!area) return;
    const count = () => { const text = area.value.trim(); this.words = text ? text.split(/\s+/).length : 0; };
    count();
    area.addEventListener('input', count);
    // Vor dem Senden Text → HTML (Absätze <p>, „– “-Zeilen <ul><li>), passend zu D's Filter p ul li strong em.
    area.form?.addEventListener('submit', () => { area.value = this.toHtml(area.value); });
  },
  toHtml(text) {
    if (/<(p|ul|ol|li)\b/i.test(text)) return text;
    const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return text.trim().split(/\n{2,}/).map((block) => {
      const lines = block.split('\n').map((l) => l.trim()).filter(Boolean);
      if (lines.length && lines.every((l) => /^[–-]\s+/.test(l))) {
        return `<ul>${lines.map((l) => `<li>${escape(l.replace(/^[–-]\s+/, ''))}</li>`).join('')}</ul>`;
      }
      return `<p>${escape(lines.join(' '))}</p>`;
    }).join('');
  },
  label() { return `${this.words} ${config.labels.words} · ${config.labels.target}`; },
}));

// 1.2 (A): Inhaltsverzeichnis mit Scrollspy und Änderungshistorie (src/modules/).
Alpine.data('toc', toc);
Alpine.data('docHistory', docHistory);

/* 1.3: hier anhängen */

// Start zuletzt: alle Alpine.data()-Registrierungen (5.1, 5.2 und A's 5.3-Hunk) müssen davor stehen.
Alpine.start();
