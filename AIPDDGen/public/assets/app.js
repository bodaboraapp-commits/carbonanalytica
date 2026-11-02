(() => {
  "use strict";

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  document.documentElement.classList.add("js");

  // Count-up for numeric dashboard stats (no layout/markup dependency).
  const counters = Array.from(
    document.querySelectorAll(".kpi-card__value, .pipeline-card__value, .attention-tile__value"),
  ).filter((element) => {
    const text = (element.textContent || "").trim();
    return /^\d{1,6}$/.test(text) && Number(text) > 0;
  });

  const animateCount = (element) => {
    if (reduceMotion) return;
    const target = Number((element.textContent || "").trim());
    const duration = 620 + Math.min(target, 400);
    const start = performance.now();
    const tick = (now) => {
      const progress = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      element.textContent = String(Math.round(target * eased));
      if (progress < 1) window.requestAnimationFrame(tick);
    };
    window.requestAnimationFrame(tick);
  };

  if (counters.length > 0) {
    if (!reduceMotion && "IntersectionObserver" in window) {
      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              animateCount(entry.target);
              observer.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.4 },
      );
      counters.forEach((element) => observer.observe(element));
    } else {
      counters.forEach(animateCount);
    }
  }

  // Scroll-in reveal for panels below the fold.
  if (!reduceMotion && "IntersectionObserver" in window) {
    const candidates = Array.from(
      document.querySelectorAll(
        ".panel-card, .kpi-grid, .console-columns, .project-list, .pipeline-cards, .attention-grid",
      ),
    ).filter((element) => element.getBoundingClientRect().top > window.innerHeight * 0.85);

    if (candidates.length > 0) {
      document.documentElement.classList.add("has-reveal");
      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              entry.target.classList.add("is-visible");
              observer.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.08, rootMargin: "0px 0px -8% 0px" },
      );
      candidates.forEach((element) => {
        element.classList.add("reveal");
        observer.observe(element);
      });
    }
  }
})();
