// Inhaltsverzeichnis (1.2, SDC tannwald:toc): Scrollspy über IntersectionObserver — das Kapitel, dessen
// Überschrift zuletzt den oberen Viewport-Bereich passiert hat, ist aktiv. Keine Bewegung, nur Zustand.
export default function toc() {
  return {
    active: '',
    init() {
      const anchors = [...this.$root.querySelectorAll('a[href^="#"]')].map((a) => a.getAttribute('href').slice(1));
      const headings = anchors.map((id) => document.getElementById(id)).filter(Boolean);
      if (!headings.length || !('IntersectionObserver' in window)) return;
      const positions = new Map();
      const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) positions.set(entry.target.id, entry.boundingClientRect.top);
        const passed = headings.filter((h) => (positions.get(h.id) ?? Infinity) <= 120);
        this.active = passed.length ? passed[passed.length - 1].id : headings[0].id;
      }, { rootMargin: '-120px 0px -70% 0px', threshold: [0, 1] });
      headings.forEach((h) => observer.observe(h));
      this.active = headings[0].id;
    },
  };
}
