/**
 * Presentation deck navigation for /preza.
 * Exported helpers are covered by tests/js/preza-deck-verify.mjs
 */

export function clampIndex(index, total) {
  const n = Number(index);
  if (!Number.isFinite(n) || total <= 0) return 0;
  return Math.max(0, Math.min(total - 1, Math.trunc(n)));
}

export function nextIndex(current, total) {
  return clampIndex(current + 1, total);
}

export function prevIndex(current, total) {
  return clampIndex(current - 1, total);
}

export function slideLabel(index, total) {
  return `Слайд ${clampIndex(index, total) + 1} из ${Math.max(total, 0)}`;
}

/** Keys sent by most USB/Bluetooth presentation clickers. */
export function isAdvanceKey(event) {
  const key = event.key;
  const code = event.code;
  return (
    key === "ArrowRight" ||
    key === "PageDown" ||
    key === " " ||
    key === "Enter" ||
    key === "n" ||
    key === "N" ||
    code === "Space" ||
    code === "PageDown" ||
    code === "ArrowRight" ||
    code === "Enter" ||
    code === "NumpadEnter" ||
    code === "MediaTrackNext"
  );
}

export function isRetreatKey(event) {
  const key = event.key;
  const code = event.code;
  return (
    key === "ArrowLeft" ||
    key === "PageUp" ||
    key === "Backspace" ||
    key === "p" ||
    key === "P" ||
    code === "PageUp" ||
    code === "ArrowLeft" ||
    code === "Backspace" ||
    code === "MediaTrackPrevious"
  );
}

function isEditableTarget(target) {
  if (!target || !(target instanceof Element)) return false;
  const tag = target.tagName;
  if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return true;
  return Boolean(target.closest("[contenteditable='true']"));
}

function isInteractiveTarget(target) {
  if (!target || !(target instanceof Element)) return false;
  return Boolean(
    target.closest("a, button, input, textarea, select, summary, label, [data-deck-dot], [data-deck-prev], [data-deck-next], [data-deck-print]")
  );
}

function initDeck(root = document) {
  const slides = Array.from(root.querySelectorAll("[data-slide]"));
  if (slides.length === 0) return null;

  const dotsHost = root.querySelector("[data-deck-dots]");
  const live = root.querySelector("[data-deck-live]");
  const prevBtn = root.querySelector("[data-deck-prev]");
  const nextBtn = root.querySelector("[data-deck-next]");
  const printBtn = root.querySelector("[data-deck-print]");
  const deck = root.querySelector(".deck") || root;
  const body = root.body || (root instanceof Document ? root.body : null);
  let index = 0;
  let touchX = null;

  function render() {
    slides.forEach((slide, i) => {
      const active = i === index;
      slide.classList.toggle("is-active", active);
      slide.setAttribute("aria-hidden", active ? "false" : "true");
      if (active) {
        slide.removeAttribute("hidden");
      } else {
        slide.setAttribute("hidden", "");
      }
    });
    if (dotsHost) {
      dotsHost.querySelectorAll("[data-deck-dot]").forEach((dot, i) => {
        dot.classList.toggle("is-active", i === index);
        dot.setAttribute("aria-current", i === index ? "true" : "false");
      });
    }
    if (live) live.textContent = slideLabel(index, slides.length);
    if (prevBtn) prevBtn.disabled = index <= 0;
    if (nextBtn) nextBtn.disabled = index >= slides.length - 1;
    try {
      const hash = `#slide-${index + 1}`;
      if (location.hash !== hash) {
        history.replaceState(null, "", hash);
      }
    } catch (_) {
      /* ignore */
    }
  }

  function go(to) {
    index = clampIndex(to, slides.length);
    render();
    const active = slides[index];
    if (active) {
      active.scrollTop = 0;
    }
  }

  function goNext() {
    go(nextIndex(index, slides.length));
  }

  function goPrev() {
    go(prevIndex(index, slides.length));
  }

  if (dotsHost && dotsHost.childElementCount === 0) {
    slides.forEach((_, i) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "deck-dot";
      btn.setAttribute("data-deck-dot", String(i));
      btn.setAttribute("aria-label", `Перейти к слайду ${i + 1}`);
      btn.addEventListener("click", () => go(i));
      dotsHost.appendChild(btn);
    });
  }

  prevBtn?.addEventListener("click", () => goPrev());
  nextBtn?.addEventListener("click", () => goNext());
  printBtn?.addEventListener("click", () => window.print());

  // Capture on window so USB/Bluetooth clickers work even when focus is odd.
  const keyTarget = typeof window !== "undefined" ? window : root;
  keyTarget.addEventListener(
    "keydown",
    (event) => {
      if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
      if (isEditableTarget(event.target)) return;

      if (isAdvanceKey(event)) {
        event.preventDefault();
        goNext();
        return;
      }
      if (isRetreatKey(event)) {
        event.preventDefault();
        goPrev();
        return;
      }
      if (event.key === "Home") {
        event.preventDefault();
        go(0);
        return;
      }
      if (event.key === "End") {
        event.preventDefault();
        go(slides.length - 1);
      }
    },
    true
  );

  // Clicker-style mouse: left click advances, right click goes back.
  deck.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.button !== 0) return;
    if (isInteractiveTarget(event.target)) return;
    goNext();
  });
  deck.addEventListener("contextmenu", (event) => {
    if (isInteractiveTarget(event.target)) return;
    event.preventDefault();
    goPrev();
  });

  deck.addEventListener(
    "touchstart",
    (event) => {
      touchX = event.changedTouches[0]?.clientX ?? null;
    },
    { passive: true }
  );
  deck.addEventListener(
    "touchend",
    (event) => {
      if (touchX == null) return;
      const x = event.changedTouches[0]?.clientX ?? touchX;
      const delta = x - touchX;
      touchX = null;
      if (Math.abs(delta) < 50) return;
      if (delta < 0) goNext();
      else goPrev();
    },
    { passive: true }
  );

  const match = String(location.hash || "").match(/slide-(\d+)/i);
  if (match) {
    index = clampIndex(Number(match[1]) - 1, slides.length);
  }
  render();

  if (body && typeof body.focus === "function") {
    if (!body.hasAttribute("tabindex")) body.setAttribute("tabindex", "-1");
    try {
      body.focus({ preventScroll: true });
    } catch (_) {
      body.focus();
    }
  }

  root.querySelectorAll(".screen-frame img").forEach((img) => {
    const frame = img.closest(".screen-frame");
    const markMissing = () => {
      if (!frame || frame.classList.contains("screen-frame--placeholder")) return;
      frame.classList.add("screen-frame--placeholder");
      const label = document.createElement("span");
      label.textContent = "Скрин интерфейса будет здесь";
      img.replaceWith(label);
    };
    img.addEventListener("error", markMissing);
    if (img.complete && img.naturalWidth === 0) markMissing();
  });

  return { go, goNext, goPrev, getIndex: () => index, getTotal: () => slides.length };
}

if (typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => initDeck(document));
  } else {
    initDeck(document);
  }
}
