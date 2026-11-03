// tw2-13: Die Facetten-Seitenleiste und ihr Sheet (searchFilters) sind weg — Filter sind Chips im Formular.

// Portal-Formulare (1.3, Leitplanken v2 §7 „Feld“): nach einem Fehler bekommt das erste fehlerhafte Feld den
// Fokus, damit der Fehlersatz direkt darunter sichtbar ist (templates/form/form-element.html.twig setzt
// data-field-error am Wrapper). Nur, wenn kein Feld Autofokus trägt.
document.addEventListener('DOMContentLoaded', () => {
  if (document.querySelector('[autofocus]')) return;
  const field = document.querySelector('[data-field-error] input:not([type="hidden"]), [data-field-error] textarea, [data-field-error] select');
  if (field) field.focus({ preventScroll: false });
});
