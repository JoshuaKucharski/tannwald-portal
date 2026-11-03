// Sheet (1.3, Leitplanken v2 §7 „Sheet“, §10): Panel von unten auf dem Phone bzw. rechts unten auf dem Desktop.
// Ein Helfer für alle Sheets des Themes (Filter, Assistent, Zurückweisen): Fokusfalle, Esc schliesst,
// alles ausserhalb bekommt `inert`, Scroll-Lock des Hintergrunds, Fokus kehrt zum Auslöser zurück.
// Bewegung kommt aus dem CSS (--duration-base); prefers-reduced-motion setzt sie dort auf 0 s.
import Alpine from 'alpinejs';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Sichtbare fokussierbare Elemente innerhalb von root. */
export function focusables(root) {
  return Array.from(root.querySelectorAll(FOCUSABLE)).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

/**
 * Setzt `inert` auf alles ausserhalb von el (Geschwister entlang der Vorfahrenkette bis body).
 * Gibt eine Funktion zurück, die den Zustand wieder aufhebt.
 */
export function inertOutside(el) {
  const touched = [];
  let node = el;
  while (node && node !== document.body) {
    const parent = node.parentElement;
    if (!parent) break;
    for (const sibling of parent.children) {
      if (sibling === node || sibling.hasAttribute('inert') || sibling.tagName === 'SCRIPT') continue;
      sibling.setAttribute('inert', '');
      touched.push(sibling);
    }
    node = parent;
  }
  return () => touched.forEach((sibling) => sibling.removeAttribute('inert'));
}

/** Tab innerhalb von root im Kreis halten (Fokusfalle). */
export function trapTab(event, root) {
  if (event.key !== 'Tab') return;
  const items = focusables(root);
  if (!items.length) { event.preventDefault(); root.focus(); return; }
  const first = items[0];
  const last = items[items.length - 1];
  if (event.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}

/**
 * Mixin für Alpine-Komponenten mit Sheet: open(), close(), onKeydown(). Erwartet x-ref="sheet" (das Panel)
 * und optional x-ref="initial" (erstes Element, das den Fokus bekommt). `sheetActive()` sagt, ob das Panel
 * gerade als Sheet erscheint (auf breiten Bildschirmen steht es als Spalte, dann ohne Falle und ohne Lock).
 */
export function sheetMixin(options = {}) {
  return {
    open: false,
    opener: null,
    releaseInert: null,
    sheetActive() {
      return options.sheetQuery ? window.matchMedia(options.sheetQuery).matches : true;
    },
    openSheet() {
      if (this.open) return;
      this.opener = document.activeElement;
      this.open = true;
      this.$nextTick(() => {
        const root = this.$refs.sheet;
        if (!root) return;
        if (this.sheetActive()) {
          this.releaseInert = inertOutside(root);
          document.body.classList.add('overflow-hidden');
        }
        const target = this.$refs.initial || focusables(root)[0] || root;
        target.focus({ preventScroll: true });
        if (typeof this.onOpened === 'function') this.onOpened();
      });
    },
    closeSheet() {
      if (!this.open) return;
      this.open = false;
      if (this.releaseInert) { this.releaseInert(); this.releaseInert = null; }
      document.body.classList.remove('overflow-hidden');
      if (typeof this.onClosed === 'function') this.onClosed();
      // Der Auslöser (z. B. der Launcher) wird erst im nächsten Frame wieder sichtbar (x-show), dann Fokus zurück.
      const opener = this.opener;
      this.opener = null;
      if (opener && typeof opener.focus === 'function') this.$nextTick(() => window.requestAnimationFrame(() => opener.focus({ preventScroll: true })));
    },
    onSheetKeydown(event) {
      if (!this.open) return;
      // Esc schliesst auch das nicht-modale Desktop-Panel (Nachtrag 3 tw2-11); die Falle gilt nur als Sheet.
      if (event.key === 'Escape') { event.preventDefault(); this.closeSheet(); return; }
      if (this.sheetActive() && this.$refs.sheet) trapTab(event, this.$refs.sheet);
    },
  };
}

// Allgemeines Sheet (z. B. Bestätigung „Zurückweisen“ in der Review-Karte): x-data="sheet()".
Alpine.data('sheet', () => ({
  ...sheetMixin(),
  init() {
    // Ein Sheet, das schon offen gerendert wurde, hält beim Verlassen nichts fest.
    window.addEventListener('pagehide', () => { if (this.releaseInert) this.releaseInert(); });
  },
}));

// Zurückweisen in der Review-Karte (1.3, SDC tannwald:review-actions): fängt den Klick auf D's Submit-Button
// data-transition="zurueckweisen" ab, zeigt die Bestätigung als Sheet und sendet dann D's Formular mit
// demselben Button (formaction bleibt) ab. Die Begründung ist eine Notiz für die Person, kein Datenfeld.
Alpine.data('reviewReject', () => ({
  ...sheetMixin(),
  reason: '',
  button: null,
  intercept(event) {
    // D's Buttons sind input[type=submit] mit data-transition.
    const button = event.target.closest('[data-transition="zurueckweisen"]');
    if (!button || this.open) return;
    event.preventDefault();
    this.button = button;
    this.openSheet();
  },
  confirm() {
    const button = this.button;
    this.closeSheet();
    if (button && button.form) button.form.requestSubmit(button);
  },
}));
