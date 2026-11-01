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

Alpine.start();

/* 5.3: hier anhängen */
