// Änderungshistorie (1.2): Textbutton „Änderungshistorie“ öffnet die Zeitleiste und springt dorthin;
// der Zustand bleibt in der Seite (kein Laden, keine Bewegung ausser dem 180-ms-Einblenden).
export default function history() {
  return {
    open: false,
    toggle() {
      this.open = !this.open;
      if (this.open) this.$nextTick(() => this.$refs.timeline?.scrollIntoView({ block: 'start' }));
    },
  };
}
