// Lesebestätigung auf der Bulletinseite (tw2-11 Nachtrag 10): „Gelesen bestätigen“ je betroffener Maschine über den
// Endpunkt des Banners (POST /bulletin/{nid}/bestaetigen, JSON {maschine}, X-CSRF-Token aus /session/token), Update ohne
// Reload: Status wird „bestätigt am TT.MM.JJJJ“, der Button verschwindet; Fehler als Satz. Konfiguration aus dem Twig:
// endpoint, tokenEndpoint, done [{nid, date: 'TT.MM.JJJJ' | null}] (Liste, weil Twigs merge numerische Schlüssel neu
// nummeriert), labels {pending, confirmed, error}.
import Alpine from 'alpinejs';

Alpine.data('bulletinConfirm', (config) => ({
  done: Object.fromEntries((config.done || []).map((d) => [d.nid, d.date])),
  busy: null,
  error: '',
  status(nid) {
    return this.done[nid] ? config.labels.confirmed.replace('@date', this.done[nid]) : config.labels.pending;
  },
  format(atom) {
    const d = new Date(atom);
    return `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}`;
  },
  async confirm(nid) {
    if (this.busy !== null) return;
    this.busy = nid;
    this.error = '';
    try {
      const token = (await (await fetch(config.tokenEndpoint, { credentials: 'same-origin' })).text()).trim();
      const response = await fetch(config.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ maschine: nid }),
      });
      if (!response.ok) throw new Error(String(response.status));
      const data = await response.json();
      this.done[nid] = this.format(data.bestaetigt_am);
    } catch (e) {
      this.error = config.labels.error;
    } finally {
      this.busy = null;
    }
  },
}));
