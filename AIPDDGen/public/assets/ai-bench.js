/* --------------------------------------------------------------------------
   AI Engine test bench: "Run test" progress + staged status.

   The bench run is a synchronous POST (the server redirects to the page with the
   result once the provider answers), so this is a client-side progress indicator
   for the in-flight request. It is progressive enhancement only: without
   JavaScript the form submits exactly as before.
   -------------------------------------------------------------------------- */

(() => {
  "use strict";

  const form = document.querySelector("[data-ai-bench-form]");
  const region = document.getElementById("bench-progress");
  if (!form || !region) {
    return;
  }

  // The page is only styled for the progress UI when this enhancement runs, so
  // the stylesheet is attached from the server-provided URL (same-origin, CSP
  // style-src 'self'). Without JavaScript the region stays hidden.
  const stylesheetUrl = region.dataset.stylesheet || "";
  if (stylesheetUrl !== "" && !document.querySelector('link[data-ai-bench-style]')) {
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = stylesheetUrl;
    link.setAttribute("data-ai-bench-style", "1");
    document.head.appendChild(link);
  }

  const bar = region.querySelector("[data-bench-bar]");
  const label = region.querySelector("[data-bench-label]");
  const elapsedLabel = region.querySelector("[data-bench-elapsed]");
  const submitButton = form.querySelector("[data-bench-submit]");
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const dataset = region.dataset;

  // Staged status messages come from the server (EN/FR), with safe fallbacks.
  const stages = [
    { at: 0, text: dataset.stageSending || "Sending the sample to the AI provider…" },
    { at: 1500, text: dataset.stageWaiting || "Waiting for the provider to draft the sample…" },
    { at: 5000, text: dataset.stageSlow || "Still waiting — a real draft can take several seconds…" },
    { at: 12000, text: dataset.stageLong || "Taking longer than usual; the request is still running…" },
  ];

  let progressTimer = null;
  let elapsedTimer = null;
  let startedAt = 0;
  let submitting = false;

  const setBar = (percent) => {
    if (bar) {
      bar.style.width = Math.max(0, Math.min(100, percent)) + "%";
    }
  };

  const setLabel = (text) => {
    if (label) {
      label.textContent = text;
    }
  };

  const stopTimers = () => {
    if (progressTimer !== null) {
      window.clearInterval(progressTimer);
      progressTimer = null;
    }
    if (elapsedTimer !== null) {
      window.clearInterval(elapsedTimer);
      elapsedTimer = null;
    }
  };

  const reset = () => {
    stopTimers();
    submitting = false;
    form.removeAttribute("data-bench-submitting");
    form.removeAttribute("aria-busy");
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.removeAttribute("aria-disabled");
    }
    region.hidden = true;
    setBar(0);
    setLabel(dataset.stageIdle || "");
    if (elapsedLabel) {
      elapsedLabel.textContent = "";
    }
  };

  const start = () => {
    submitting = true;
    form.dataset.benchSubmitting = "1";
    region.hidden = false;
    form.setAttribute("aria-busy", "true");
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.setAttribute("aria-disabled", "true");
    }

    startedAt = Date.now();
    setBar(reduceMotion ? 100 : 6);
    setLabel(stages[0].text);

    const updateElapsed = () => {
      if (elapsedLabel) {
        elapsedLabel.textContent = ((Date.now() - startedAt) / 1000).toFixed(1) + "s";
      }
    };
    updateElapsed();
    elapsedTimer = window.setInterval(updateElapsed, 100);

    // Advance toward 90% while the synchronous request is in flight; the page
    // navigation to the result then replaces this indicator.
    progressTimer = window.setInterval(() => {
      const elapsed = Date.now() - startedAt;
      for (let index = stages.length - 1; index >= 0; index -= 1) {
        if (elapsed >= stages[index].at) {
          setLabel(stages[index].text);
          break;
        }
      }
      const nextPercent = Math.min(90, 6 + (elapsed / 120) * 8);
      setBar(nextPercent);
    }, 250);
  };

  form.addEventListener("submit", (event) => {
    // The submit event does not fire for form.submit(), so this cannot recurse.
    if (submitting) {
      return;
    }
    event.preventDefault();
    start();
    // Paint the indicator before the native submission begins.
    window.requestAnimationFrame(() => {
      window.setTimeout(() => {
        form.submit();
      }, 30);
    });
  });

  // If the page is restored from the bfcache (browser back), clear the state.
  window.addEventListener("pageshow", (event) => {
    if (event.persisted) {
      reset();
    }
  });
})();
