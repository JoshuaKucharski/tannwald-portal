// Facetten der Suche (1.3, Wireframe 02): auf lg+ eine ruhige Seitenleiste, darunter ein Sheet mit Zähler
// „Filter (n)“. Die Checkboxen hängen per form= am Suchformular (SDC facet-group); eine Änderung sendet ab.
import Alpine from 'alpinejs';
import { sheetMixin } from './sheet.js';

Alpine.data('searchFilters', (config) => ({
  ...sheetMixin({ sheetQuery: '(max-width: 1023px)' }),
  active: config.active || 0,
  init() {
    // Breiter als lg: ein offenes Sheet wird zur Spalte, Falle und Lock lösen sich.
    const media = window.matchMedia('(min-width: 1024px)');
    media.addEventListener('change', (event) => { if (event.matches && this.open) this.closeSheet(); });
    // Zähler der aktiven Filter folgt den Checkboxen, bis das Formular neu lädt.
    this.$root.addEventListener('change', (event) => {
      if (event.target.matches('input[type="checkbox"][form]')) {
        this.active = this.$root.querySelectorAll('input[type="checkbox"][form]:checked:not([name="aktuell"])').length;
      }
    });
  },
  label() {
    return this.active ? config.labelActive.replace('@count', String(this.active)) : config.label;
  },
}));

// Portal-Formulare (1.3, Leitplanken v2 §7 „Feld“): nach einem Fehler bekommt das erste fehlerhafte Feld den
// Fokus, damit der Fehlersatz direkt darunter sichtbar ist (templates/form/form-element.html.twig setzt
// data-field-error am Wrapper). Nur, wenn kein Feld Autofokus trägt.
document.addEventListener('DOMContentLoaded', () => {
  if (document.querySelector('[autofocus]')) return;
  const field = document.querySelector('[data-field-error] input:not([type="hidden"]), [data-field-error] textarea, [data-field-error] select');
  if (field) field.focus({ preventScroll: false });
});
