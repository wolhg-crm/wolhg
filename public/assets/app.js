function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const rawData = window.atob(base64);
  return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

const ajaxHeaders = {
  "X-Requested-With": "fetch",
  "Accept": "text/html, application/json"
};

let navigationController = null;
let currentDocumentBase = `${window.location.pathname}${window.location.search}`;

let deferredInstallPrompt = null;
let serviceWorkerRegistration = null;
const PWA_INSTALL_DISMISSED_KEY = "wolhg:pwa-install-dismissed";

function isPwaInstallDismissed() {
  try {
    return window.localStorage?.getItem(PWA_INSTALL_DISMISSED_KEY) === "1";
  } catch (_) {
    return false;
  }
}

function dismissPwaInstallPanel() {
  try {
    window.localStorage?.setItem(PWA_INSTALL_DISMISSED_KEY, "1");
  } catch (_) {}
  updatePwaInstallUi();
}

function registerPwaServiceWorker() {
  if (!("serviceWorker" in navigator)) return;
  navigator.serviceWorker.register("/service-worker.js")
    .then((registration) => {
      serviceWorkerRegistration = registration;
      updatePwaInstallUi();
    })
    .catch(() => {});
}

function isPwaStandalone() {
  return window.matchMedia?.("(display-mode: standalone)")?.matches || window.navigator.standalone === true;
}

function isIosBrowser() {
  return /iphone|ipad|ipod/i.test(window.navigator.userAgent || "") && !("BeforeInstallPromptEvent" in window);
}

function updatePwaInstallUi() {
  document.querySelectorAll("[data-pwa-install-panel]").forEach((panel) => {
    const installed = isPwaStandalone();
    panel.hidden = installed || isPwaInstallDismissed();
    panel.classList.toggle("is-installable", Boolean(deferredInstallPrompt));
    const button = panel.querySelector("[data-install-pwa]");
    if (button) {
      button.textContent = deferredInstallPrompt ? "Установить приложение" : "Как установить";
      button.setAttribute("aria-label", deferredInstallPrompt ? "Установить приложение" : "Показать инструкцию по установке приложения");
    }
    const iosHelp = panel.querySelector("[data-pwa-ios-help]");
    if (iosHelp) iosHelp.hidden = !isIosBrowser() || installed;
    const readyNote = panel.querySelector("[data-pwa-ready-note]");
    if (readyNote) readyNote.hidden = !deferredInstallPrompt || installed;
  });
}

function pwaInstallInstructions() {
  if (isIosBrowser()) {
    return "На iPhone или iPad нажмите Поделиться, затем выберите На экран Домой.";
  }
  if (/android/i.test(window.navigator.userAgent || "")) {
    return "В Chrome на Android откройте меню ⋮ и выберите Установить приложение или Добавить на главный экран.";
  }
  return "В Chrome или Edge используйте значок установки в адресной строке либо меню браузера и пункт Установить приложение.";
}

function showPwaInstallHelp() {
  showToast(pwaInstallInstructions());
}

async function installPwa() {
  if (isPwaStandalone()) {
    showToast("Приложение уже открыто как PWA.");
    updatePwaInstallUi();
    return;
  }

  if (!deferredInstallPrompt) {
    registerPwaServiceWorker();
    showPwaInstallHelp();
    updatePwaInstallUi();
    return;
  }

  const promptEvent = deferredInstallPrompt;
  deferredInstallPrompt = null;
  promptEvent.prompt();
  const choice = await promptEvent.userChoice.catch(() => null);
  if (choice?.outcome === "accepted") {
    showToast("Приложение добавляется на домашний экран.");
  } else {
    showPwaInstallHelp();
  }
  updatePwaInstallUi();
}

window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  deferredInstallPrompt = event;
  updatePwaInstallUi();
});

window.addEventListener("appinstalled", () => {
  deferredInstallPrompt = null;
  showToast("Приложение установлено.");
  updatePwaInstallUi();
});

registerPwaServiceWorker();


function csrfToken() {
  return document.querySelector("input[name=\"_csrf\"]")?.value || "";
}

function setLoading(isLoading) {
  document.documentElement.classList.toggle("ajax-loading", isLoading);
}

function showToast(message, type = "info") {
  if (!message) return;
  let toast = document.querySelector("[data-ajax-toast]");
  if (!toast) {
    toast = document.createElement("div");
    toast.dataset.ajaxToast = "";
    toast.className = "ajax-toast";
    document.body.appendChild(toast);
  }
  toast.textContent = message;
  toast.dataset.type = type;
  toast.hidden = false;
  window.clearTimeout(showToast.timeoutId);
  showToast.timeoutId = window.setTimeout(() => {
    toast.hidden = true;
  }, 3200);
}

function copyBodyState(nextBody) {
  document.body.className = nextBody.className;
  Object.keys(document.body.dataset).forEach((key) => {
    if (!(key in nextBody.dataset)) delete document.body.dataset[key];
  });
  Object.entries(nextBody.dataset).forEach(([key, value]) => {
    document.body.dataset[key] = value;
  });
}

function replaceChildrenFrom(target, source) {
  const nodes = Array.from(source.childNodes).map((node) => document.importNode(node, true));
  target.replaceChildren(...nodes);
}

function documentBaseFor(url) {
  const target = new URL(url, window.location.href);
  return `${target.pathname}${target.search}`;
}

function isSameDocumentUrl(url) {
  const target = new URL(url, window.location.href);
  return target.origin === window.location.origin && documentBaseFor(target) === currentDocumentBase;
}

function scrollToHash(hash, options = {}) {
  if (!hash || hash === "#") return false;
  if (hash === "#top") {
    window.scrollTo({ top: 0, behavior: options.behavior || "auto" });
    return true;
  }

  const rawId = hash.slice(1);
  let decodedId = rawId;
  try {
    decodedId = decodeURIComponent(rawId);
  } catch (_) {}

  const target = document.getElementById(decodedId) || document.getElementById(rawId);
  if (!target) return false;
  target.scrollIntoView({ block: "start", behavior: options.behavior || "auto" });
  return true;
}

function navigateSameDocumentHash(url) {
  const target = new URL(url, window.location.href);
  if (!target.hash || !isSameDocumentUrl(target)) return false;

  window.history.pushState(window.history.state, "", target.toString());
  if (!scrollToHash(target.hash)) {
    window.scrollTo({ top: 0, behavior: "instant" });
  }
  return true;
}

function swapDocument(html, url, options = {}) {
  const doc = new DOMParser().parseFromString(html, "text/html");
  const nextTitle = doc.querySelector("title")?.textContent;
  const nextBody = doc.body;
  const currentShell = document.querySelector("[data-ajax-shell]");
  const nextShell = doc.querySelector("[data-ajax-shell]");
  const currentContent = document.querySelector("[data-ajax-content], main.content");
  const nextContent = doc.querySelector("[data-ajax-content], main.content");

  if (nextTitle) document.title = nextTitle;

  // Multi-church: sidebar brand/nav are tenant-specific. Always swap the whole shell
  // when both sides have it, so /church-3 never keeps /pushkino links from a prior page.
  if (currentShell && nextShell) {
    currentShell.replaceWith(document.importNode(nextShell, true));
    if (nextBody) copyBodyState(nextBody);
  } else if (currentContent && nextContent && currentShell && !nextShell) {
    // Leaving the CRM shell (e.g. to church picker / public login card).
    window.location.assign(url);
    return;
  } else if (!currentShell && nextShell) {
    // Entering the CRM shell from a public page.
    window.location.assign(url);
    return;
  } else if (currentContent && nextContent) {
    currentContent.replaceWith(document.importNode(nextContent, true));
    if (nextBody) copyBodyState(nextBody);
  } else if (nextBody) {
    copyBodyState(nextBody);
    replaceChildrenFrom(document.body, nextBody);
  } else {
    window.location.assign(url);
    return;
  }

  if (!options.preserveScroll) {
    const targetHash = new URL(url, window.location.href).hash;
    if (!targetHash || !scrollToHash(targetHash)) {
      window.scrollTo({ top: 0, behavior: "instant" });
    }
  }
  updatePwaInstallUi();
  initContactMasks();
  initAddressGeolocation();
  initYandexAddressPickers();
  initHomegroupFilters();
  initHomegroupTabs();
  initHomegroupMap();
  initDistrictsMap();
  initChurchLandingEditors();
  churchLandingBindMap();
}

function shouldAjaxLink(link, event) {
  if (!link || link.dataset.noAjax !== undefined) return false;
  if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
  if (link.target && link.target !== "_self") return false;
  if (link.hasAttribute("download")) return false;

  const url = new URL(link.href, window.location.href);
  if (url.origin !== window.location.origin) return false;
  if (!["http:", "https:"].includes(url.protocol)) return false;
  if (url.pathname === "/export-person") return false;
  // OAuth: full navigation required — fetch cannot follow cross-origin authorize redirects (CORS).
  if (url.pathname === "/social/start" || url.pathname === "/social/callback") return false;
  if (url.hash && isSameDocumentUrl(url)) return false;
  if (requiresFullNavigation(url)) return false;

  return true;
}

function crmTenantSlugFromPath(pathname) {
  try {
    const parts = String(pathname || "").split("/").filter(Boolean);
    if (parts.length === 0) return "";
    const first = parts[0].toLowerCase();
    const reserved = new Set([
      "login", "logout", "forgot-password", "reset-password", "account-invite", "role-invite",
      "homegroup", "join", "prayer-request", "miracle-share", "api", "social", "bot", "bots",
      "mcp", "geocode", "assets", "uploads", "admin", "push", "preza"
    ]);
    if (reserved.has(first)) return "";
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(first)) return "";
    return first;
  } catch (_) {
    return "";
  }
}

function requiresFullNavigation(url) {
  try {
    const target = new URL(url, window.location.href);
    if (target.origin !== window.location.origin) return true;
    if (target.pathname === "/social/start" || target.pathname === "/social/callback") return true;
    const fromSlug = crmTenantSlugFromPath(window.location.pathname);
    const toSlug = crmTenantSlugFromPath(target.pathname);
    // Switching churches (or between platform root and a church) must remount the shell.
    if (fromSlug !== toSlug && (fromSlug !== "" || toSlug !== "")) {
      return true;
    }
    return false;
  } catch (_) {
    return true;
  }
}

async function loadPage(url, options = {}) {
  if (requiresFullNavigation(url)) {
    window.location.assign(url);
    return;
  }

  if (navigationController) navigationController.abort();
  navigationController = new AbortController();
  setLoading(true);

  try {
    const response = await fetch(url, {
      method: "GET",
      credentials: "same-origin",
      headers: ajaxHeaders,
      signal: navigationController.signal
    });

    if (!response.ok) throw new Error("HTTP " + response.status);
    if (requiresFullNavigation(response.url || url)) {
      window.location.assign(response.url || url);
      return;
    }
    const html = await response.text();
    const requestedUrl = new URL(url, window.location.href);
    const responseUrl = new URL(response.url || url, window.location.href);
    if (requestedUrl.hash && !responseUrl.hash) responseUrl.hash = requestedUrl.hash;
    const finalUrl = responseUrl.toString();
    swapDocument(html, finalUrl, options);
    currentDocumentBase = documentBaseFor(finalUrl);

    if (options.history !== false) {
      const mode = options.replace ? "replaceState" : "pushState";
      window.history[mode]({ ajax: true }, "", finalUrl);
    }
  } catch (error) {
    if (error.name !== "AbortError") {
      showToast("Не удалось загрузить страницу. Попробуйте еще раз.", "error");
      throw error;
    }
  } finally {
    setLoading(false);
  }
}

async function handleAjaxResponse(response, fallbackUrl) {
  const contentType = response.headers.get("Content-Type") || "";

  if (contentType.includes("application/json")) {
    const payload = await response.json();
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Не удалось выполнить действие.");
    }

    if (payload.remove) {
      document.querySelectorAll(payload.remove).forEach((el) => el.remove());
      showToast(payload.message || "Готово");
      if (!payload.redirect) return;
    }

    if (payload.redirect) {
      const targetUrl = new URL(payload.redirect, window.location.href).toString();
      if (payload.navigate || requiresFullNavigation(targetUrl)) {
        window.location.assign(targetUrl);
        return;
      }
      const currentUrl = window.location.href.split("#")[0];
      await loadPage(targetUrl, { replace: targetUrl === currentUrl });
      return;
    }

    showToast(payload.message || "Готово");
    return;
  }

  if (!response.ok) throw new Error("HTTP " + response.status);
  const html = await response.text();
  const finalUrl = response.url || fallbackUrl;
  swapDocument(html, finalUrl);
  currentDocumentBase = documentBaseFor(finalUrl);
  window.history.pushState({ ajax: true }, "", finalUrl);
}

function setFormBusy(form, busy) {
  form.classList.toggle("is-submitting", busy);
  form.setAttribute("aria-busy", busy ? "true" : "false");
  form.querySelectorAll("button, input[type=submit]").forEach((control) => {
    control.disabled = busy;
  });
}

async function submitAjaxForm(form, submitter) {
  const method = (form.method || "GET").toUpperCase();
  const action = new URL(form.action || window.location.href, window.location.href);
  const data = new FormData(form);

  if (submitter?.name && !data.has(submitter.name)) {
    data.append(submitter.name, submitter.value);
  }

  if (method === "GET") {
    action.search = new URLSearchParams(data).toString();
    await loadPage(action.toString());
    return;
  }

  setFormBusy(form, true);
  setLoading(true);

  try {
    const headers = { ...ajaxHeaders };
    const token = csrfToken();
    if (token) headers["X-CSRF-Token"] = token;

    const response = await fetch(action.toString(), {
      method,
      credentials: "same-origin",
      headers,
      body: data
    });

    await handleAjaxResponse(response, action.toString());
  } catch (error) {
    showToast(error.message || "Не удалось сохранить изменения.", "error");
  } finally {
    setFormBusy(form, false);
    setLoading(false);
  }
}

function isGeocoderReady() {
  return document.body?.dataset.geocoderReady === "1";
}

function setAddressGeoStatus(wrapper, message, isError = false) {
  const status = wrapper?.querySelector(".address-geolocation__status, .yandex-address-picker__status");
  if (!status) return;
  status.textContent = message || "";
  status.hidden = message === "";
  status.classList.toggle("is-error", Boolean(isError && message));
}

function resetAddressGeoButton(button) {
  if (!button) return;
  button.disabled = false;
  button.classList.remove("is-loading");
  button.textContent = button.dataset.defaultText || "Получить адрес по геопозиции";
}

function findAddressInputTarget(wrapper) {
  const field = wrapper.closest(".address-field");
  if (field) {
    return field.querySelector('input[name="address"], input[name="value"]');
  }
  return wrapper.closest("form, .detail-field, label, .stack, section, .panel")?.querySelector('input[name="address"], input[name="value"]');
}

async function reverseGeocodePosition(position, wrapper, button) {
  const formData = new FormData();
  formData.set("lat", String(position.coords.latitude));
  formData.set("lon", String(position.coords.longitude));
  formData.set("_csrf", csrfToken());

  try {
    const response = await fetch("/geocode/reverse", {
      method: "POST",
      credentials: "same-origin",
      headers: { ...ajaxHeaders, "X-CSRF-Token": csrfToken() },
      body: formData
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Не удалось определить адрес.");
    }
    const input = findAddressInputTarget(wrapper);
    if (input) {
      input.value = payload.address || "";
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }
    setAddressGeoStatus(wrapper, "Адрес подставлен. Проверьте и сохраните.", false);
  } catch (error) {
    setAddressGeoStatus(wrapper, error.message || "Не удалось определить адрес.", true);
  } finally {
    resetAddressGeoButton(button);
  }
}

function handleAddressGeolocationClick(wrapper, button) {
  if (!navigator.geolocation) {
    setAddressGeoStatus(wrapper, "Браузер не поддерживает геопозицию.", true);
    return;
  }

  button.disabled = true;
  button.classList.add("is-loading");
  button.dataset.defaultText = button.dataset.defaultText || button.textContent;
  button.textContent = "Определяем адрес...";
  setAddressGeoStatus(wrapper, "", false);

  navigator.geolocation.getCurrentPosition(
    (position) => reverseGeocodePosition(position, wrapper, button),
    (error) => {
      const message = error?.code === 1
        ? "Разрешите доступ к геопозиции и попробуйте ещё раз."
        : "Не удалось получить геопозицию. Попробуйте ещё раз.";
      setAddressGeoStatus(wrapper, message, true);
      resetAddressGeoButton(button);
    },
    { enableHighAccuracy: true, maximumAge: 300000, timeout: 12000 }
  );
}

function bindAddressGeolocation(wrapper) {
  const button = wrapper.querySelector("[data-address-geolocation-button]");
  if (!button || button.dataset.addressGeoBound === "1") return;
  button.dataset.addressGeoBound = "1";
  button.addEventListener("click", () => handleAddressGeolocationClick(wrapper, button));
}

function ensurePersonAddressGeolocation(fieldEl, form) {
  if (!isGeocoderReady() || fieldEl.dataset.field !== "address") return;
  if (form.querySelector("[data-address-geolocation]")) return;

  const wrapper = document.createElement("div");
  wrapper.className = "address-geolocation";
  wrapper.dataset.addressGeolocation = "";
  wrapper.innerHTML = '<button type="button" class="ghost address-geolocation__button" data-address-geolocation-button>Получить адрес по геопозиции</button><span class="address-geolocation__status" role="status" aria-live="polite"></span>';
  form.append(wrapper);
  bindAddressGeolocation(wrapper);
}

function initAddressGeolocation(root = document) {
  if (!isGeocoderReady()) return;
  root.querySelectorAll("[data-address-geolocation]").forEach(bindAddressGeolocation);
}

function parseYandexAddresses(container) {
  const jsonNode = container.querySelector("[data-yandex-addresses-json]");
  const raw = jsonNode?.textContent || container.dataset.yandexAddresses || "[]";
  try {
    const addresses = JSON.parse(raw);
    return Array.isArray(addresses) ? addresses : [];
  } catch (_) {
    return [];
  }
}

function selectedYandexAddressIndex(container) {
  const select = container.querySelector(".yandex-address-picker__select");
  return select ? Number(select.value || 0) : 0;
}

function yandexAddressLine(address) {
  return String(address?.formatted || address?.label || "").trim();
}

function fillYandexAddress(address, container) {
  const line = yandexAddressLine(address);
  if (!line) return { ok: false, error: "В профиле Яндекса нет строки адреса." };

  const context = container.dataset.yandexAddressContext || "person";
  if (context === "profile") {
    const personHref = container.dataset.personHref || "";
    if (personHref) {
      return {
        ok: false,
        error: "Подставьте адрес в карточке человека.",
        href: personHref
      };
    }
    return { ok: false, error: "На этой странице нет поля адреса. Откройте карточку человека." };
  }

  if (context === "person") {
    const field = document.querySelector('.detail-field[data-field="address"]');
    if (!field) return { ok: false, error: "На странице нет поля адреса." };
    if (!field.classList.contains("is-editing")) {
      startPersonFieldEdit(field);
    }
    const input = field.querySelector('.detail-edit input[name="value"], .detail-edit input');
    if (!(input instanceof HTMLInputElement)) {
      return { ok: false, error: "Не удалось открыть редактирование адреса." };
    }
    input.value = line;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    input.focus();
    return { ok: true };
  }

  const input = container.closest("section, form, .panel")?.querySelector('input[name="address"], input[name="value"]');
  if (!(input instanceof HTMLInputElement)) {
    return { ok: false, error: "Не найдено поле для адреса." };
  }
  input.value = line;
  input.dispatchEvent(new Event("input", { bubbles: true }));
  input.dispatchEvent(new Event("change", { bubbles: true }));
  return { ok: true };
}

function bindYandexAddressPicker(container) {
  const addresses = parseYandexAddresses(container);
  const button = container.querySelector(".yandex-address-picker__fill");
  if (!addresses.length || !button || button.dataset.yandexAddressBound === "1") return;
  button.dataset.yandexAddressBound = "1";
  button.addEventListener("click", () => {
    const address = addresses[selectedYandexAddressIndex(container)] || addresses[0];
    const result = fillYandexAddress(address, container);
    if (result.ok) {
      setAddressGeoStatus(container, "Адрес подставлен. Проверьте и сохраните.", false);
      return;
    }
    if (result.href) {
      setAddressGeoStatus(container, result.error || "Откройте карточку человека.", true);
      window.setTimeout(() => {
        window.location.assign(result.href);
      }, 600);
      return;
    }
    setAddressGeoStatus(container, result.error || "Не удалось подставить адрес.", true);
  });
}

function initYandexAddressPickers(root = document) {
  root.querySelectorAll("[data-yandex-address-picker]").forEach(bindYandexAddressPicker);
}

function ensureYmaps(apiKey) {
  if (window.ymaps?.Map) {
    return new Promise((resolve) => window.ymaps.ready(() => resolve(window.ymaps)));
  }
  return new Promise((resolve, reject) => {
    const existing = document.querySelector("script[data-yandex-maps-loader]");
    if (existing) {
      existing.addEventListener("load", () => window.ymaps.ready(() => resolve(window.ymaps)));
      existing.addEventListener("error", reject);
      return;
    }
    const script = document.createElement("script");
    script.src = `https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=${encodeURIComponent(apiKey)}`;
    script.dataset.yandexMapsLoader = "1";
    script.onload = () => window.ymaps.ready(() => resolve(window.ymaps));
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

function homegroupBalloonLayout(ymaps) {
  return ymaps.templateLayoutFactory.createClass(
    '<div class="homegroup-balloon">' +
    '{% if properties.churchLabel %}<p class="eyebrow homegroup-balloon__church">{{ properties.churchLabel }}</p>{% endif %}' +
    '<strong>{{ properties.leaderName }}</strong>' +
    '<p>{{ properties.typesLabel }}</p>' +
    '<p>{{ properties.scheduleLabel }}</p>' +
    '<p class="muted">{{ properties.shortAddress }}</p>' +
    '<a class="primary" href="{{ properties.joinUrl }}">Присоединиться</a>' +
    '</div>'
  );
}

function homegroupFilterSummary(selectedLabels) {
  const labels = selectedLabels.filter(Boolean);
  const count = labels.length;
  if (count === 0) return { text: "Любой", placeholder: true };
  if (count === 1) return { text: labels[0], placeholder: false };
  if (count === 2) return { text: `${labels[0]}, ${labels[1]}`, placeholder: false };
  return { text: `${count} выбрано`, placeholder: false };
}

function updateFilterDropdownSummary(dropdown) {
  const summaryEl = dropdown.querySelector("[data-filter-summary]");
  const labels = [];
  dropdown.querySelectorAll('.filter-dropdown__option input[type="checkbox"]:checked').forEach((input) => {
    labels.push(input.closest("label")?.querySelector("span")?.textContent?.trim() || "");
  });
  const { text, placeholder } = homegroupFilterSummary(labels);
  if (!summaryEl) return;
  summaryEl.textContent = text;
  summaryEl.classList.toggle("is-placeholder", placeholder);
}

function closeFilterDropdown(dropdown) {
  dropdown.classList.remove("is-open");
  dropdown.querySelector(".filter-dropdown__trigger")?.setAttribute("aria-expanded", "false");
  dropdown.querySelector(".filter-dropdown__panel")?.setAttribute("hidden", "");
}

function openFilterDropdown(dropdown) {
  document.querySelectorAll("[data-filter-dropdown].is-open").forEach((other) => {
    if (other !== dropdown) closeFilterDropdown(other);
  });
  dropdown.classList.add("is-open");
  dropdown.querySelector(".filter-dropdown__trigger")?.setAttribute("aria-expanded", "true");
  dropdown.querySelector(".filter-dropdown__panel")?.removeAttribute("hidden");
}

let homegroupFiltersBound = false;

function initHomegroupFilters(root = document) {
  root.querySelectorAll("[data-filter-dropdown]").forEach((dropdown) => {
    if (dropdown.dataset.filterBound === "1") return;
    dropdown.dataset.filterBound = "1";
    updateFilterDropdownSummary(dropdown);

    dropdown.querySelector(".filter-dropdown__trigger")?.addEventListener("click", () => {
      if (dropdown.classList.contains("is-open")) closeFilterDropdown(dropdown);
      else openFilterDropdown(dropdown);
    });

    dropdown.querySelector("[data-filter-clear]")?.addEventListener("click", () => {
      dropdown.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.checked = false;
      });
      updateFilterDropdownSummary(dropdown);
    });

    dropdown.querySelector("[data-filter-apply]")?.addEventListener("click", () => {
      closeFilterDropdown(dropdown);
    });

    dropdown.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      input.addEventListener("change", () => updateFilterDropdownSummary(dropdown));
    });
  });

  if (homegroupFiltersBound) return;
  homegroupFiltersBound = true;

  document.addEventListener("click", (event) => {
    if (event.target.closest("[data-filter-dropdown]")) return;
    document.querySelectorAll("[data-filter-dropdown].is-open").forEach(closeFilterDropdown);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    document.querySelectorAll("[data-filter-dropdown].is-open").forEach(closeFilterDropdown);
  });
}

let homegroupMapState = null;

function bindHomegroupListHover(map, mappable) {
  document.querySelectorAll("[data-group-id], [data-homegroup-card]").forEach((card) => {
    if (card.dataset.homegroupHoverBound === "1") return;
    card.dataset.homegroupHoverBound = "1";
    const focusGroup = () => {
      const id = Number(card.dataset.groupId || card.dataset.homegroupCard);
      const group = mappable.find((item) => Number(item.id) === id);
      if (!group) return;
      map.setCenter([group.latitude, group.longitude], 13, { duration: 300 });
    };
    card.addEventListener("mouseenter", focusGroup);
    card.addEventListener("focusin", focusGroup);
  });
}

function ensureHomegroupMap() {
  const panel = document.querySelector("[data-homegroup-map]");
  const mapEl = document.getElementById("homegroup-map");
  if (!panel || !mapEl) return Promise.resolve();

  if (homegroupMapState?.map) {
    homegroupMapState.map.container.fitToViewport();
    return Promise.resolve();
  }

  let groups = [];
  try {
    groups = JSON.parse(panel.dataset.groups || "[]");
  } catch (_) {
    groups = [];
  }

  const apiKey = panel.dataset.mapKey || "";
  const mappable = groups.filter((group) => group.latitude && group.longitude);
  if (!apiKey || !mappable.length) return Promise.resolve();

  return ensureYmaps(apiKey).then((ymaps) => {
    if (homegroupMapState?.map) {
      homegroupMapState.map.container.fitToViewport();
      return;
    }

    const first = mappable[0];
    const map = new ymaps.Map(mapEl, {
      center: [first.latitude, first.longitude],
      zoom: 10,
      controls: ["zoomControl", "geolocationControl"]
    });

    const balloonLayout = homegroupBalloonLayout(ymaps);
    const showChurch = panel.getAttribute("data-show-church") === "1";
    const clusterer = new ymaps.Clusterer({
      preset: "islands#invertedOrangeClusterIcons",
      groupByCoordinates: false,
      clusterDisableClickZoom: false,
      clusterOpenBalloonOnClick: true,
      clusterBalloonContentLayout: "cluster#balloonCarousel",
      clusterBalloonItemContentLayout: balloonLayout,
      clusterBalloonPanelMaxMapArea: 0
    });

    const placemarks = mappable.map((group) => {
      const churchLabel = showChurch ? (group.church_label || group.church_city || group.church_name || "") : "";
      const leaderName = group.leader_name || group.name || "Группа";
      return new ymaps.Placemark(
        [group.latitude, group.longitude],
        {
          churchLabel,
          leaderName,
          typesLabel: group.types_label || "",
          scheduleLabel: group.schedule_label || "",
          shortAddress: group.short_address || "",
          joinUrl: group.join_url || `/homegroup/join?group=${group.id}`,
          hintContent: churchLabel ? `${churchLabel}: ${leaderName}` : leaderName
        },
        {
          preset: "islands#orangeDotIcon",
          balloonContentLayout: balloonLayout
        }
      );
    });

    clusterer.add(placemarks);
    map.geoObjects.add(clusterer);
    homegroupMapState = { map, mappable };
    bindHomegroupListHover(map, mappable);
    map.container.fitToViewport();
  }).catch(() => {});
}

function activateHomegroupTab(view, tabName, options = {}) {
  view.querySelectorAll("[data-homegroup-tab]").forEach((tab) => {
    const active = tab.dataset.homegroupTab === tabName;
    tab.classList.toggle("is-active", active);
    tab.setAttribute("aria-selected", active ? "true" : "false");
  });
  view.querySelectorAll("[data-homegroup-panel]").forEach((panel) => {
    const active = panel.dataset.homegroupPanel === tabName;
    panel.hidden = !active;
  });

  if (tabName === "map") {
    ensureHomegroupMap();
  }

  if (options.updateHash !== false) {
    const nextHash = tabName === "map" ? "#map" : "";
    const baseUrl = `${window.location.pathname}${window.location.search}`;
    history.replaceState(history.state, "", nextHash ? `${baseUrl}${nextHash}` : baseUrl);
  }
}

function initHomegroupTabs(root = document) {
  const view = root.querySelector("[data-homegroup-view]");
  if (!view || view.dataset.homegroupTabsBound === "1") return;
  view.dataset.homegroupTabsBound = "1";

  const initialTab = window.location.hash === "#map" ? "map" : "list";
  activateHomegroupTab(view, initialTab, { updateHash: false });

  view.querySelectorAll("[data-homegroup-tab]").forEach((tab) => {
    tab.addEventListener("click", () => {
      activateHomegroupTab(view, tab.dataset.homegroupTab || "list");
    });
  });
}

function initHomegroupMap() {
  const view = document.querySelector("[data-homegroup-view]");
  if (view) return;
  ensureHomegroupMap();
}

document.addEventListener("DOMContentLoaded", () => {
  updatePwaInstallUi();
  initContactMasks();
  initAddressGeolocation();
  initYandexAddressPickers();
  initHomegroupFilters();
  initHomegroupTabs();
  initHomegroupMap();
  initDistrictsMap();
  initChurchLandingEditors();
  churchLandingBindMap();

  document.addEventListener("change", (event) => {
    const statusSelect = event.target.closest("[data-prayer-status]");
    if (statusSelect instanceof HTMLSelectElement) {
      const form = statusSelect.closest("form");
      const fields = form?.querySelector("[data-prayer-testimony-fields]");
      if (fields instanceof HTMLElement) {
        fields.hidden = statusSelect.value !== "testimony";
      }
    }

    const anonymousToggle = event.target.closest("[data-prayer-anonymous]");
    if (anonymousToggle instanceof HTMLInputElement) {
      const form = anonymousToggle.closest("form");
      const nameBlock = form?.querySelector("[data-prayer-guest-name]");
      const nameInput = form?.querySelector("[data-prayer-guest-name-input]");
      if (nameBlock instanceof HTMLElement) {
        nameBlock.hidden = anonymousToggle.checked;
      }
      if (nameInput instanceof HTMLInputElement) {
        nameInput.required = !anonymousToggle.checked;
        if (anonymousToggle.checked) {
          nameInput.value = "";
        }
      }
    }
  });

  document.addEventListener("click", async (event) => {
    const confirmButton = event.target.closest("[data-confirm]");
    if (confirmButton) {
      if (!confirm(confirmButton.dataset.confirm)) {
        event.preventDefault();
        return;
      }
      const expected = (confirmButton.dataset.confirmValue || "").trim();
      if (expected !== "") {
        const promptText = confirmButton.dataset.confirmPrompt
          || ("Для подтверждения введите: " + expected);
        const typed = prompt(promptText);
        if (typed === null || typed.trim() !== expected) {
          event.preventDefault();
          return;
        }
        const form = confirmButton.closest("form");
        const fieldName = confirmButton.dataset.confirmField || "confirm_slug";
        if (form) {
          let input = form.querySelector(`[name="${fieldName}"]`);
          if (!(input instanceof HTMLInputElement)) {
            input = document.createElement("input");
            input.type = "hidden";
            input.name = fieldName;
            form.appendChild(input);
          }
          input.value = typed.trim();
        }
      }
    }

    const toggleButton = event.target.closest("[data-toggle-all]");
    if (toggleButton) {
      const target = document.querySelector(toggleButton.dataset.toggleAll);
      if (!target) return;
      target.querySelectorAll("input[type=\"checkbox\"]").forEach((input) => {
        input.checked = true;
      });
      return;
    }

    const copyTemplateButton = event.target.closest("[data-copy-template]");
    if (copyTemplateButton) {
      const text = copyTemplateButton.closest(".template-card")?.querySelector("[data-template-body]")?.innerText || "";
      try {
        await navigator.clipboard.writeText(text);
        copyTemplateButton.textContent = "Скопировано";
        setTimeout(() => { copyTemplateButton.textContent = "Копировать"; }, 1600);
      } catch (_) {
        window.prompt("Скопируйте текст", text);
      }
      return;
    }

    const insertImageButton = event.target.closest("[data-knowledge-insert-image]");
    if (insertImageButton) {
      const tools = insertImageButton.closest("[data-knowledge-inline-tools]");
      const fileInput = tools?.querySelector("[data-knowledge-insert-file]");
      if (fileInput instanceof HTMLInputElement) {
        fileInput.value = "";
        fileInput.click();
      }
      return;
    }

    const copyButton = event.target.closest("[data-copy]");
    if (copyButton) {
      const source = copyButton.closest(".copy-field, label, .stack")?.querySelector("[data-copy-source]");
      const text = (source?.value || source?.innerText || "").trim();
      if (!text) return;
      try {
        await navigator.clipboard.writeText(text);
        copyButton.textContent = "Скопировано";
        setTimeout(() => { copyButton.textContent = "Копировать"; }, 1600);
      } catch (_) {
        if (source && typeof source.select === "function") {
          source.focus();
          source.select();
        }
        window.prompt("Скопируйте ссылку", text);
      }
      return;
    }

    const installButton = event.target.closest("[data-install-pwa]");
    if (installButton) {
      event.preventDefault();
      await installPwa();
      return;
    }

    const dismissPwaButton = event.target.closest("[data-dismiss-pwa-install]");
    if (dismissPwaButton) {
      event.preventDefault();
      dismissPwaInstallPanel();
      return;
    }

    const editButton = event.target.closest(".detail-edit-btn");
    if (editButton) {
      event.preventDefault();
      const field = editButton.closest(".detail-field");
      if (field) startPersonFieldEdit(field);
      return;
    }

    const cancelButton = event.target.closest(".detail-cancel");
    if (cancelButton) {
      event.preventDefault();
      const field = cancelButton.closest(".detail-field");
      if (field) cancelPersonFieldEdit(field);
      return;
    }

    const saveButton = event.target.closest(".detail-save");
    if (saveButton) {
      event.preventDefault();
      const field = saveButton.closest(".detail-field");
      if (field) await savePersonField(field);
      return;
    }

    const link = event.target.closest("a[href]");
    if (link && navigateSameDocumentHash(link.href)) {
      event.preventDefault();
      return;
    }
    if (!shouldAjaxLink(link, event)) return;

    event.preventDefault();
    await loadPage(link.href);
  });

  document.addEventListener("keydown", async (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.closest(".detail-edit")) return;
    const field = input.closest(".detail-field");
    if (!field) return;
    if (event.key === "Escape") {
      event.preventDefault();
      cancelPersonFieldEdit(field);
      return;
    }
    if (event.key === "Enter") {
      event.preventDefault();
      await savePersonField(field);
    }
  });

  document.addEventListener("submit", async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.classList.contains("detail-edit")) {
      event.preventDefault();
      const field = form.closest(".detail-field");
      if (field) await savePersonField(field);
      return;
    }
    if (form.dataset.noAjax !== undefined || form.target) return;

    event.preventDefault();
    await submitAjaxForm(form, event.submitter);
  });

  document.addEventListener("change", async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || target.type !== "file") return;

    if (target.matches("[data-knowledge-image-input]")) {
      previewKnowledgeCoverImage(target);
      return;
    }

    if (target.matches("[data-knowledge-insert-file]")) {
      await uploadKnowledgeInlineImage(target);
    }
  });

  window.addEventListener("popstate", () => {
    if (isSameDocumentUrl(window.location.href)) {
      if (!window.location.hash || !scrollToHash(window.location.hash)) {
        window.scrollTo({ top: 0, behavior: "instant" });
      }
      return;
    }

    loadPage(window.location.href, { history: false, replace: true }).catch(() => {
      window.location.reload();
    });
  });

  document.addEventListener("click", async (event) => {
    const pushButton = event.target.closest("#enable-push");
    if (!pushButton) return;

    const publicKey = document.body.dataset.vapidPublicKey;
    if (!publicKey) {
      alert("WebPush подготовлен, но публичный VAPID-ключ не задан. Сгенерируйте ключи в разделе «Безопасность».");
      return;
    }
    if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
      alert("Этот браузер не поддерживает WebPush.");
      return;
    }
    const permission = await Notification.requestPermission();
    if (permission !== "granted") return;
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey)
    });
    await fetch("/push/subscribe", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "fetch", "X-CSRF-Token": csrfToken() },
      body: JSON.stringify(subscription)
    });
    pushButton.textContent = "WebPush включен";
  });
});

const PHONE_PREFIX = "+7 ";
const TELEGRAM_PREFIX = "@";

function onlyDigits(value) {
  return String(value || "").replace(/\D+/g, "");
}

function nationalPhoneDigits(value) {
  let digits = onlyDigits(value);
  if (digits.startsWith("8")) digits = "7" + digits.slice(1);
  if (digits.startsWith("7")) digits = digits.slice(1);
  return digits.slice(0, 10);
}

function formatPhoneMask(value) {
  const national = nationalPhoneDigits(value);
  let result = PHONE_PREFIX.trimEnd();
  if (national.length > 0) result += " " + national.slice(0, 3);
  if (national.length > 3) result += " " + national.slice(3, 6);
  if (national.length > 6) result += "-" + national.slice(6, 8);
  if (national.length > 8) result += "-" + national.slice(8, 10);
  return result === "+7" ? PHONE_PREFIX : result;
}

function telegramBody(value) {
  let raw = String(value || "").trim();
  raw = raw.replace(/^https?:\/\/(t\.me|telegram\.me)\//i, "");
  raw = raw.replace(/^t\.me\//i, "");
  raw = raw.replace(/^@+/, "");
  raw = raw.split(/[/?#]/)[0] || "";
  return raw.replace(/[^a-zA-Z0-9_]/g, "").slice(0, 32);
}

function formatTelegramMask(value) {
  return TELEGRAM_PREFIX + telegramBody(value);
}

function countMeaningfulBefore(value, caret, kind) {
  const before = value.slice(0, Math.max(0, caret));
  if (kind === "phone") {
    // National digits only: ignore country-code 7/8 from the "+7" / "8" prefix.
    let digits = onlyDigits(before);
    if (digits.startsWith("8")) digits = "7" + digits.slice(1);
    if (digits.startsWith("7")) digits = digits.slice(1);
    return digits.length;
  }
  return telegramBody(before).length;
}

function caretForMeaningfulCount(value, count, kind) {
  if (count <= 0) {
    return kind === "phone" ? Math.min(PHONE_PREFIX.length, value.length) : TELEGRAM_PREFIX.length;
  }

  if (kind === "phone") {
    // Skip the country-code digit so it never consumes a caret slot.
    let skippedCountry = false;
    let seen = 0;
    for (let i = 0; i < value.length; i += 1) {
      if (!/\d/.test(value[i])) continue;
      if (!skippedCountry) {
        skippedCountry = true;
        continue;
      }
      seen += 1;
      if (seen >= count) return i + 1;
    }
    return value.length;
  }

  let seen = 0;
  for (let i = 0; i < value.length; i += 1) {
    if (!/[a-zA-Z0-9_]/.test(value[i])) continue;
    seen += 1;
    if (seen >= count) return i + 1;
  }
  return value.length;
}

function setMaskedValue(input, nextValue, meaningfulCount) {
  const kind = input.dataset.mask;
  input.value = nextValue;
  const caret = caretForMeaningfulCount(nextValue, meaningfulCount, kind);
  try {
    input.setSelectionRange(caret, caret);
  } catch (_) {}
}

function applyContactMask(input, rawValue, caret) {
  const kind = input.dataset.mask;
  if (kind === "phone") {
    const meaningful = countMeaningfulBefore(String(rawValue || ""), caret ?? String(rawValue || "").length, "phone");
    setMaskedValue(input, formatPhoneMask(rawValue), meaningful);
    return;
  }

  if (kind === "telegram") {
    const bodyBefore = countMeaningfulBefore(String(rawValue || ""), caret ?? String(rawValue || "").length, "telegram");
    setMaskedValue(input, formatTelegramMask(rawValue), bodyBefore);
  }
}

function ensureContactMask(input) {
  if (!(input instanceof HTMLInputElement) || !input.dataset.mask) return;
  if (input.dataset.mask === "phone") {
    input.value = formatPhoneMask(input.value || PHONE_PREFIX);
    return;
  }
  if (input.dataset.mask === "telegram") {
    input.value = formatTelegramMask(input.value || TELEGRAM_PREFIX);
  }
}

function initContactMasks(root = document) {
  root.querySelectorAll("input[data-mask]").forEach((input) => ensureContactMask(input));
}

function contactMaskPrefix(input) {
  return input.dataset.prefix || (input.dataset.mask === "phone" ? PHONE_PREFIX : TELEGRAM_PREFIX);
}

document.addEventListener("focusin", (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.dataset.mask) return;
  ensureContactMask(input);
  const prefix = contactMaskPrefix(input);
  if ((input.selectionStart ?? 0) < prefix.length) {
    const pos = Math.max(prefix.length, input.value.length);
    try {
      input.setSelectionRange(pos, pos);
    } catch (_) {}
  }
});

document.addEventListener("input", (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.dataset.mask) return;
  applyContactMask(input, input.value, input.selectionStart ?? input.value.length);
});

document.addEventListener("keydown", (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.dataset.mask) return;

  const prefix = contactMaskPrefix(input);
  const start = input.selectionStart ?? 0;
  const end = input.selectionEnd ?? 0;

  if ((event.key === "Backspace" || event.key === "Delete") && start < prefix.length && end <= prefix.length) {
    event.preventDefault();
    try {
      input.setSelectionRange(prefix.length, prefix.length);
    } catch (_) {}
    return;
  }

  if (event.key === "Backspace" && start === end && start <= prefix.length) {
    event.preventDefault();
    try {
      input.setSelectionRange(prefix.length, prefix.length);
    } catch (_) {}
    return;
  }

  if (event.key === "ArrowLeft" && start <= prefix.length && end <= prefix.length && !event.shiftKey) {
    event.preventDefault();
    try {
      input.setSelectionRange(prefix.length, prefix.length);
    } catch (_) {}
  }

  if (event.key === "Home" && !event.shiftKey) {
    event.preventDefault();
    try {
      input.setSelectionRange(prefix.length, prefix.length);
    } catch (_) {}
  }
});

document.addEventListener("paste", (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.dataset.mask) return;
  event.preventDefault();
  const pasted = event.clipboardData?.getData("text") || "";
  const start = input.selectionStart ?? input.value.length;
  const end = input.selectionEnd ?? input.value.length;
  const prefix = contactMaskPrefix(input);
  const safeStart = Math.max(prefix.length, start);
  const merged = input.value.slice(0, safeStart) + pasted + input.value.slice(Math.max(safeStart, end));
  applyContactMask(input, merged, safeStart + pasted.length);
});

document.addEventListener("blur", (event) => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || !input.dataset.mask) return;
  ensureContactMask(input);
}, true);

function validatePersonFieldClient(field, value) {
  const trimmed = String(value || "").trim();
  if (field === "phone") {
    if (trimmed === "" || trimmed === "+7" || trimmed === "+7 ") return null;
    if (nationalPhoneDigits(trimmed).length !== 10) return "Введите корректный телефон";
    return null;
  }
  if (field === "telegram") {
    if (trimmed === "" || trimmed === "@") return null;
    const body = telegramBody(trimmed);
    if (!/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/.test(body)) return "Введите корректный Telegram";
    return null;
  }
  if (field === "email") {
    if (trimmed === "") return null;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) return "Введите корректный email";
    return null;
  }
  if (["birth_date", "first_visit_date", "repentance_date", "baptism_date", "membership_date"].includes(field)) {
    if (trimmed === "") return null;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) return "Введите корректную дату";
    return null;
  }
  return null;
}

function setPersonFieldError(fieldEl, message) {
  const error = fieldEl.querySelector(".detail-error");
  if (!error) return;
  if (message) {
    error.textContent = message;
    error.hidden = false;
    fieldEl.classList.add("has-error");
  } else {
    error.textContent = "";
    error.hidden = true;
    fieldEl.classList.remove("has-error");
  }
}

function renderPersonFieldValue(fieldEl, display, href) {
  const valueEl = fieldEl.querySelector(".detail-value");
  if (!valueEl) return;
  valueEl.replaceChildren();
  if (href && display && display !== "—") {
    const link = document.createElement("a");
    link.href = href;
    link.textContent = display;
    if (href.startsWith("http")) {
      link.target = "_blank";
      link.rel = "noopener noreferrer";
    }
    valueEl.appendChild(link);
  } else {
    valueEl.textContent = display || "—";
  }
}

function startPersonFieldEdit(fieldEl) {
  if (fieldEl.classList.contains("is-editing")) return;
  document.querySelectorAll(".detail-field.is-editing").forEach((other) => {
    if (other !== fieldEl) cancelPersonFieldEdit(other);
  });

  setPersonFieldError(fieldEl, null);
  let form = fieldEl.querySelector(".detail-edit");
  if (!form) {
    form = document.createElement("form");
    form.className = "detail-edit";
    form.setAttribute("data-no-ajax", "");

    let control;
    if (fieldEl.dataset.input === "select" || fieldEl.dataset.field === "marital_status") {
      control = document.createElement("select");
      control.name = "value";
      let options = [];
      try {
        options = JSON.parse(fieldEl.dataset.options || "[]");
      } catch (_) {
        options = [];
      }
      if (!Array.isArray(options) || options.length === 0) {
        options = ["женат", "замужем", "не женат", "не замужем"];
      }
      const current = fieldEl.dataset.value || "";
      if (current && !options.includes(current)) {
        options = [...options, current];
      }
      const empty = document.createElement("option");
      empty.value = "";
      empty.textContent = "—";
      control.appendChild(empty);
      options.forEach((opt) => {
        const option = document.createElement("option");
        option.value = String(opt);
        option.textContent = String(opt);
        control.appendChild(option);
      });
    } else {
      control = document.createElement("input");
      control.name = "value";
      control.type = fieldEl.dataset.input || "text";
      if (fieldEl.dataset.field === "phone") {
        control.type = "tel";
        control.dataset.mask = "phone";
        control.dataset.prefix = "+7 ";
        control.inputMode = "tel";
        control.autocomplete = "tel";
        control.placeholder = "+7 977 000-00-00";
        control.spellcheck = false;
      } else if (fieldEl.dataset.field === "telegram") {
        control.dataset.mask = "telegram";
        control.dataset.prefix = "@";
        control.autocomplete = "username";
        control.placeholder = "@username";
        control.spellcheck = false;
        control.autocapitalize = "off";
      } else if (fieldEl.dataset.field === "email") {
        control.type = "email";
        control.autocomplete = "email";
        control.placeholder = "name@example.com";
      } else if (["birth_date", "first_visit_date", "repentance_date", "baptism_date", "membership_date"].includes(fieldEl.dataset.field)) {
        control.type = "date";
      } else if (fieldEl.dataset.field === "address") {
        control.placeholder = "Адрес";
        control.maxLength = 255;
      } else if (fieldEl.dataset.field === "ministry") {
        control.placeholder = "Служение";
        control.maxLength = 160;
      }
    }

    const save = document.createElement("button");
    save.type = "submit";
    save.className = "detail-save";
    save.title = "Сохранить";
    save.setAttribute("aria-label", "Сохранить");
    save.textContent = "✓";

    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "detail-cancel";
    cancel.title = "Отмена";
    cancel.setAttribute("aria-label", "Отмена");
    cancel.textContent = "×";

    form.append(control, save, cancel);
    fieldEl.appendChild(form);
  }

  ensurePersonAddressGeolocation(fieldEl, form);

  const input = form.querySelector("input, select");
  input.value = fieldEl.dataset.value || "";
  fieldEl.classList.add("is-editing");
  ensureContactMask(input);
  input.focus();
  if (input.tagName === "INPUT" && input.dataset.mask) {
    const prefix = contactMaskPrefix(input);
    const pos = Math.max(prefix.length, input.value.length);
    try { input.setSelectionRange(pos, pos); } catch (_) {}
  } else if (input.tagName === "INPUT") {
    try { input.select(); } catch (_) {}
  }
}

function cancelPersonFieldEdit(fieldEl) {
  const form = fieldEl.querySelector(".detail-edit");
  const input = form?.querySelector("input, select");
  if (input) input.value = fieldEl.dataset.value || "";
  fieldEl.classList.remove("is-editing", "is-saving");
  setPersonFieldError(fieldEl, null);
}

async function savePersonField(fieldEl) {
  if (fieldEl.classList.contains("is-saving")) return;
  const form = fieldEl.querySelector(".detail-edit");
  const input = form?.querySelector("input, select");
  if (!input) return;

  const field = fieldEl.dataset.field || "";
  const personId = fieldEl.dataset.personId || "";
  const value = input.value;
  const clientError = validatePersonFieldClient(field, value);
  if (clientError) {
    setPersonFieldError(fieldEl, clientError);
    input.focus();
    return;
  }

  fieldEl.classList.add("is-saving");
  setPersonFieldError(fieldEl, null);

  try {
    const data = new FormData();
    data.set("id", personId);
    data.set("field", field);
    data.set("value", value);
    data.set("_csrf", csrfToken());

    const response = await fetch("/person-field-update", {
      method: "POST",
      credentials: "same-origin",
      headers: { ...ajaxHeaders, "X-CSRF-Token": csrfToken() },
      body: data
    });

    const contentType = response.headers.get("Content-Type") || "";
    if (!contentType.includes("application/json")) {
      throw new Error("Не удалось сохранить поле.");
    }

    const payload = await response.json();
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Не удалось сохранить поле.");
    }

    fieldEl.dataset.value = payload.edit_value || "";
    renderPersonFieldValue(fieldEl, payload.display || "—", payload.href || "");
    fieldEl.classList.remove("is-editing");
    showToast(payload.message || "Сохранено");
  } catch (error) {
    setPersonFieldError(fieldEl, error.message || "Не удалось сохранить поле.");
    input.focus();
  } finally {
    fieldEl.classList.remove("is-saving");
  }
}

function previewKnowledgeCoverImage(input) {
  const field = input.closest(".knowledge-image-field");
  const preview = field?.querySelector("[data-knowledge-image-preview]");
  if (!(preview instanceof HTMLElement)) return;

  preview.replaceChildren();
  preview.hidden = true;

  const file = input.files?.[0];
  if (!file || !file.type.startsWith("image/")) return;

  const img = document.createElement("img");
  img.alt = "Предпросмотр";
  img.src = URL.createObjectURL(file);
  img.addEventListener("load", () => URL.revokeObjectURL(img.src), { once: true });
  preview.appendChild(img);
  preview.hidden = false;
}

function insertTextIntoTextarea(textarea, text) {
  const start = textarea.selectionStart ?? textarea.value.length;
  const end = textarea.selectionEnd ?? textarea.value.length;
  const before = textarea.value.slice(0, start);
  const after = textarea.value.slice(end);
  const spacer = before !== "" && !before.endsWith("\n") ? "\n" : "";
  const trailing = after !== "" && !after.startsWith("\n") ? "\n" : "";
  const chunk = spacer + text + trailing;
  textarea.value = before + chunk + after;
  const cursor = before.length + chunk.length;
  textarea.focus();
  textarea.setSelectionRange(cursor, cursor);
  textarea.dispatchEvent(new Event("input", { bubbles: true }));
}

async function uploadKnowledgeInlineImage(fileInput) {
  const file = fileInput.files?.[0];
  if (!file) return;

  const tools = fileInput.closest("[data-knowledge-inline-tools]");
  const form = fileInput.closest("form");
  const textarea = form?.querySelector("[data-knowledge-body]");
  const button = tools?.querySelector("[data-knowledge-insert-image]");
  if (!(textarea instanceof HTMLTextAreaElement)) {
    showToast("Не найдено поле текста для вставки картинки.", "error");
    return;
  }

  const data = new FormData();
  data.append("image", file);

  if (button instanceof HTMLButtonElement) {
    button.disabled = true;
    button.textContent = "Загрузка…";
  }
  setLoading(true);

  try {
    const headers = { ...ajaxHeaders };
    const token = csrfToken();
    if (token) headers["X-CSRF-Token"] = token;

    const response = await fetch("/knowledge-image-upload", {
      method: "POST",
      credentials: "same-origin",
      headers,
      body: data
    });

    const contentType = response.headers.get("Content-Type") || "";
    if (!contentType.includes("application/json")) {
      throw new Error("Не удалось загрузить картинку.");
    }

    const payload = await response.json();
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Не удалось загрузить картинку.");
    }

    insertTextIntoTextarea(textarea, payload.markdown || `![картинка](${payload.path})`);
    showToast(payload.message || "Картинка вставлена");
  } catch (error) {
    showToast(error.message || "Не удалось загрузить картинку.", "error");
  } finally {
    setLoading(false);
    fileInput.value = "";
    if (button instanceof HTMLButtonElement) {
      button.disabled = false;
      button.textContent = "Вставить картинку в текст";
    }
  }
}

function geojsonToYmapsCoords(geojson) {
  if (!geojson || !geojson.type || !geojson.coordinates) return null;
  const ringToLatLon = (ring) => ring.map((pt) => [pt[1], pt[0]]);
  if (geojson.type === "Polygon") {
    return geojson.coordinates.map(ringToLatLon);
  }
  if (geojson.type === "MultiPolygon") {
    // Flatten first polygon for ymaps.Polygon; MultiPolygon as separate objects preferred by caller.
    return geojson.coordinates[0] ? geojson.coordinates[0].map(ringToLatLon) : null;
  }
  return null;
}

function geojsonToYmapsPolygons(ymaps, geojson, options, properties) {
  const objects = [];
  if (!geojson) return objects;
  if (geojson.type === "Polygon") {
    const coords = geojsonToYmapsCoords(geojson);
    if (coords) objects.push(new ymaps.Polygon(coords, properties || {}, options || {}));
  } else if (geojson.type === "MultiPolygon") {
    geojson.coordinates.forEach((poly) => {
      const coords = poly.map((ring) => ring.map((pt) => [pt[1], pt[0]]));
      objects.push(new ymaps.Polygon(coords, properties || {}, options || {}));
    });
  }
  return objects;
}

function initDistrictsMap(root = document) {
  const panel = root.querySelector("[data-districts-map]");
  if (!panel || panel.dataset.districtsMapBound === "1") return;
  panel.dataset.districtsMapBound = "1";

  const apiKey = panel.dataset.mapKey || "";
  const mapEl = panel.querySelector("#districts-map");
  if (!apiKey || !mapEl) return;

  let districts = [];
  let groups = [];
  try {
    districts = JSON.parse(panel.dataset.districts || "[]");
    groups = JSON.parse(panel.dataset.groups || "[]");
  } catch (e) {
    districts = [];
    groups = [];
  }

  const canEdit = panel.dataset.canEdit === "1";
  let map = null;
  let previewCollection = null;
  let drawPolygon = null;

  ensureYmaps(apiKey).then((ymaps) => {
    const center = groups[0]
      ? [groups[0].latitude, groups[0].longitude]
      : [55.99, 37.85];
    map = new ymaps.Map(mapEl, {
      center,
      zoom: 12,
      controls: ["zoomControl", "geolocationControl", "typeSelector"]
    });
    previewCollection = new ymaps.GeoObjectCollection();
    map.geoObjects.add(previewCollection);

    const bounds = [];
    districts.forEach((district) => {
      if (!district.geojson) return;
      const style = {
        fillColor: (district.color || "#E87722") + "55",
        strokeColor: district.color || "#E87722",
        strokeWidth: 2,
        opacity: 0.85
      };
      geojsonToYmapsPolygons(ymaps, district.geojson, style, {
        hintContent: district.name,
        balloonContent: district.name + (district.leaders?.length ? `<br>${district.leaders.join(", ")}` : "")
      }).forEach((poly) => {
        map.geoObjects.add(poly);
        const b = poly.geometry.getBounds();
        if (b) bounds.push(b[0], b[1]);
      });
    });

    groups.forEach((group) => {
      const pm = new ymaps.Placemark(
        [group.latitude, group.longitude],
        { balloonContent: group.name, hintContent: group.name },
        { preset: "islands#orangeDotIcon" }
      );
      map.geoObjects.add(pm);
      bounds.push([group.latitude, group.longitude]);
    });

    if (bounds.length >= 2) {
      try {
        map.setBounds(ymaps.util.bounds.fromPoints(bounds), { checkZoomRange: true, zoomMargin: 24 });
      } catch (e) { /* keep default */ }
    }

    const syncCatalogPreview = () => {
      if (!previewCollection) return;
      previewCollection.removeAll();
      document.querySelectorAll("[data-districts-catalog] input[type=checkbox][data-catalog-geojson-b64]").forEach((box) => {
        if (!(box instanceof HTMLInputElement) || !box.checked) return;
        try {
          const json = atob(box.dataset.catalogGeojsonB64 || "");
          const geojson = JSON.parse(json);
          geojsonToYmapsPolygons(ymaps, geojson, {
            fillColor: "#2F6FED55",
            strokeColor: "#2F6FED",
            strokeWidth: 2,
            strokeStyle: "shortdash"
          }, { hintContent: box.dataset.catalogName || "" }).forEach((poly) => previewCollection.add(poly));
        } catch (e) { /* ignore bad candidate */ }
      });
    };

    document.addEventListener("change", (event) => {
      if (event.target instanceof HTMLInputElement && event.target.matches("[data-catalog-geojson-b64]")) {
        syncCatalogPreview();
      }
    });
    syncCatalogPreview();

    const drawStart = panel.querySelector("[data-district-draw-start]");
    const drawFinish = panel.querySelector("[data-district-draw-finish]");
    const drawField = document.querySelector("[data-district-draw-geojson]");

    if (canEdit && drawStart && drawFinish) {
      drawStart.addEventListener("click", () => {
        if (drawPolygon) {
          map.geoObjects.remove(drawPolygon);
          drawPolygon = null;
        }
        drawPolygon = new ymaps.Polygon([], {}, {
          editorDrawingCursor: "crosshair",
          fillColor: "#1A9B6C55",
          strokeColor: "#1A9B6C",
          strokeWidth: 2
        });
        map.geoObjects.add(drawPolygon);
        drawPolygon.editor.startDrawing();
        drawFinish.hidden = false;
      });

      drawFinish.addEventListener("click", () => {
        if (!drawPolygon) return;
        drawPolygon.editor.stopEditing();
        drawFinish.hidden = true;
        const coords = drawPolygon.geometry.getCoordinates();
        if (!coords || !coords[0] || coords[0].length < 4) {
          showToast("Нужен замкнутый полигон из нескольких точек.", "error");
          return;
        }
        const lonLat = coords.map((ring) => ring.map((pt) => [pt[1], pt[0]]));
        const geojson = { type: "Polygon", coordinates: lonLat };
        if (drawField instanceof HTMLTextAreaElement) {
          drawField.value = JSON.stringify(geojson);
          showToast("Полигон записан в форму «Нарисовать свой округ»");
        }
      });
    }
  }).catch(() => {});
}

function parseJsonAttr(el, name, fallback) {
  try {
    const raw = el?.getAttribute(name);
    if (!raw) return fallback;
    return JSON.parse(raw);
  } catch (_) {
    return fallback;
  }
}

function moveArrayItem(list, from, to) {
  if (from < 0 || to < 0 || from >= list.length || to >= list.length) return list;
  const copy = list.slice();
  const [item] = copy.splice(from, 1);
  copy.splice(to, 0, item);
  return copy;
}

function churchLandingBindMap() {
  const source = document.querySelector("[data-church-landing-map]");
  const host = document.querySelector("[data-church-landing-map-host]");
  if (!source || !host) return;
  if (host.dataset.mapBound === "1") return;
  host.dataset.mapBound = "1";
  const key = source.getAttribute("data-map-key") || "";
  const point = parseJsonAttr(source, "data-point", null);
  if (!key || !point?.lat || !point?.lng) return;
  ensureYmaps(key).then((ymaps) => {
    const map = new ymaps.Map(host, {
      center: [point.lat, point.lng],
      zoom: 16,
      controls: ["zoomControl"]
    });
    map.geoObjects.add(new ymaps.Placemark([point.lat, point.lng], {
      balloonContent: point.title || ""
    }));
  }).catch(() => {});
}

function initChurchLandingEditors(scope = document) {
  scope.querySelectorAll("[data-church-landing-editor]").forEach((root) => {
    if (!(root instanceof HTMLElement)) return;
    if (root.dataset.landingEditorBound === "1") return;
    root.dataset.landingEditorBound = "1";
    bindChurchLandingEditor(root);
  });
}

function bindChurchLandingEditor(root) {
  const form = root.querySelector("[data-church-landing-form]");
  const boot = root.querySelector("[data-landing-bootstrap]");
  if (!form || !boot) return;

  const uploadUrl = boot.getAttribute("data-upload-url") || "";
  const social = parseJsonAttr(boot, "data-social", {});
  const builtin = parseJsonAttr(boot, "data-builtin", {});
  const weekdays = parseJsonAttr(boot, "data-weekdays", {});
  const sectionLabels = parseJsonAttr(boot, "data-section-labels", {});
  const sectionHints = parseJsonAttr(boot, "data-section-hints", {});
  const payloadNode = root.querySelector("[data-landing-payload]");
  let landing = {};
  try {
    landing = payloadNode ? JSON.parse(payloadNode.textContent || "{}") : parseJsonAttr(boot, "data-landing", {});
  } catch (_) {
    landing = parseJsonAttr(boot, "data-landing", {});
  }

  const state = {
    sections: Array.isArray(landing.sections) ? landing.sections.slice() : [],
    gallery: Array.isArray(landing.gallery) ? landing.gallery.slice() : [],
    pastors: Array.isArray(landing.pastors) ? landing.pastors.slice() : [],
    schedule: Array.isArray(landing.schedule) ? landing.schedule.slice() : [],
    events: Array.isArray(landing.events) ? landing.events.slice() : [],
    ministries: Array.isArray(landing.ministries) ? landing.ministries.slice() : [],
    faq: Array.isArray(landing.faq) ? landing.faq.slice() : [],
    buttons: Array.isArray(landing.buttons) ? landing.buttons.slice() : []
  };

  const fileInput = root.querySelector("[data-landing-file-input]") || document.querySelector("[data-landing-file-input]");
  let uploadTarget = null;

  function setHidden(name, value) {
    const input = form.querySelector(`[data-landing-${name}-json]`);
    if (input) input.value = JSON.stringify(value);
  }

  function syncHidden() {
    setHidden("sections", state.sections);
    setHidden("gallery", state.gallery);
    setHidden("pastors", state.pastors);
    setHidden("schedule", state.schedule);
    setHidden("events", state.events);
    setHidden("ministries", state.ministries);
    setHidden("faq", state.faq);
    setHidden("buttons", state.buttons);
  }

  function fieldLabel(text, control) {
    const label = document.createElement("label");
    label.className = "landing-editor-field";
    const caption = document.createElement("span");
    caption.className = "landing-editor-field__label";
    caption.textContent = text;
    label.append(caption, control);
    return label;
  }

  function emptyState(text) {
    const p = document.createElement("p");
    p.className = "muted landing-editor-empty";
    p.textContent = text;
    return p;
  }

  function dragHandle() {
    const handle = document.createElement("button");
    handle.type = "button";
    handle.className = "church-landing-editor__handle";
    handle.setAttribute("data-landing-drag-handle", "1");
    handle.setAttribute("aria-label", "Переместить");
    handle.title = "Перетащите, чтобы изменить порядок";
    handle.textContent = "⋮⋮";
    return handle;
  }

  function rowControls(onUp, onDown, onRemove, options = {}) {
    const wrap = document.createElement("div");
    wrap.className = "landing-editor-row-actions quick-actions";
    const up = document.createElement("button");
    up.type = "button";
    up.className = "ghost";
    up.textContent = "↑";
    up.title = "Выше";
    up.setAttribute("aria-label", "Переместить выше");
    up.addEventListener("click", onUp);
    const down = document.createElement("button");
    down.type = "button";
    down.className = "ghost";
    down.textContent = "↓";
    down.title = "Ниже";
    down.setAttribute("aria-label", "Переместить ниже");
    down.addEventListener("click", onDown);
    wrap.append(up, down);
    if (options.allowRemove !== false) {
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "ghost danger";
      remove.textContent = "Удалить";
      remove.addEventListener("click", () => {
        if (options.confirmRemove && !window.confirm(options.confirmRemove)) return;
        onRemove();
      });
      wrap.append(remove);
    }
    return wrap;
  }

  function bindDrag(container, listKey, renderFn, options = {}) {
    let dragFrom = null;
    const items = options.directChildren
      ? Array.from(container.children).filter((el) => el instanceof HTMLElement && el.hasAttribute("data-landing-drag-index"))
      : Array.from(container.querySelectorAll("[data-landing-drag-index]"));
    items.forEach((el) => {
      const handle = el.querySelector(":scope > .landing-editor-block__head [data-landing-drag-handle], :scope > .church-landing-editor__row-head [data-landing-drag-handle], :scope > [data-landing-drag-handle]");
      const handleEl = handle instanceof HTMLElement
        ? handle
        : el.querySelector("[data-landing-drag-handle]");
      if (!(handleEl instanceof HTMLElement)) return;
      // Prefer the card/row's own handle, not a nested list handle.
      if (!el.contains(handleEl)) return;
      if (handleEl.closest("[data-landing-drag-index]") !== el) return;
      handleEl.draggable = true;
      handleEl.addEventListener("dragstart", (event) => {
        dragFrom = Number(el.getAttribute("data-landing-drag-index"));
        el.classList.add("is-dragging");
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = "move";
          event.dataTransfer.setData("text/plain", String(dragFrom));
        }
      });
      handleEl.addEventListener("dragend", () => {
        el.classList.remove("is-dragging");
        container.querySelectorAll(".is-drop-target").forEach((node) => node.classList.remove("is-drop-target"));
      });
      el.addEventListener("dragover", (event) => {
        event.preventDefault();
        el.classList.add("is-drop-target");
      });
      el.addEventListener("dragleave", () => el.classList.remove("is-drop-target"));
      el.addEventListener("drop", (event) => {
        event.preventDefault();
        el.classList.remove("is-drop-target");
        const to = Number(el.getAttribute("data-landing-drag-index"));
        if (Number.isNaN(dragFrom) || Number.isNaN(to) || dragFrom === to) return;
        state[listKey] = moveArrayItem(state[listKey], dragFrom, to);
        renderFn();
      });
    });
  }

  function renderSections() {
    const workspace = form.querySelector("[data-landing-section-workspace]");
    if (!workspace) return;
    if (!state.sections.length) {
      syncHidden();
      return;
    }

    state.sections.forEach((section, index) => {
      const card = workspace.querySelector(`[data-landing-section-id="${section.id}"]`)
        || form.querySelector(`[data-landing-section-id="${section.id}"]`);
      if (!(card instanceof HTMLElement)) return;

      card.setAttribute("data-landing-drag-index", String(index));
      card.classList.toggle("is-hidden-on-landing", !section.visible);

      const checkbox = card.querySelector("[data-landing-section-visible]");
      if (checkbox instanceof HTMLInputElement) {
        checkbox.checked = !!section.visible;
      }

      const badge = card.querySelector("[data-landing-section-badge]");
      if (badge instanceof HTMLElement) {
        badge.textContent = section.visible ? "На лендинге" : "Скрыта";
        badge.className = section.visible
          ? "landing-editor-badge landing-editor-badge--on"
          : "landing-editor-badge landing-editor-badge--off";
      }

      workspace.append(card);
    });

    bindDrag(workspace, "sections", renderSections, { directChildren: true });
    syncHidden();
  }

  function moveSection(sectionId, delta) {
    const index = state.sections.findIndex((section) => section.id === sectionId);
    if (index < 0) return;
    state.sections = moveArrayItem(state.sections, index, index + delta);
    renderSections();
  }

  if (form.dataset.landingSectionControlsBound !== "1") {
    form.dataset.landingSectionControlsBound = "1";
    form.addEventListener("change", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement) || !target.matches("[data-landing-section-visible]")) return;
      const card = target.closest("[data-landing-section-id]");
      if (!(card instanceof HTMLElement)) return;
      const sectionId = card.getAttribute("data-landing-section-id") || "";
      const section = state.sections.find((item) => item.id === sectionId);
      if (!section) return;
      section.visible = target.checked;
      card.classList.toggle("is-hidden-on-landing", !target.checked);
      const badge = card.querySelector("[data-landing-section-badge]");
      if (badge instanceof HTMLElement) {
        badge.textContent = target.checked ? "На лендинге" : "Скрыта";
        badge.className = target.checked
          ? "landing-editor-badge landing-editor-badge--on"
          : "landing-editor-badge landing-editor-badge--off";
      }
      syncHidden();
    });
    form.addEventListener("click", (event) => {
      const up = event.target instanceof Element ? event.target.closest("[data-landing-section-up]") : null;
      const down = event.target instanceof Element ? event.target.closest("[data-landing-section-down]") : null;
      if (!up && !down) return;
      const card = (up || down)?.closest("[data-landing-section-id]");
      if (!(card instanceof HTMLElement)) return;
      event.preventDefault();
      moveSection(card.getAttribute("data-landing-section-id") || "", up ? -1 : 1);
    });
  }

  function appendListRow(host, index, fields, controls) {
    const row = document.createElement("div");
    row.className = "church-landing-editor__row";
    row.setAttribute("data-landing-drag-index", String(index));
    const toolbar = document.createElement("div");
    toolbar.className = "church-landing-editor__row-head";
    toolbar.append(dragHandle(), controls);
    const body = document.createElement("div");
    body.className = "landing-editor-row-body";
    fields.forEach((field) => body.append(field));
    row.append(toolbar, body);
    host.append(row);
  }

  function renderGallery() {
    const host = form.querySelector("[data-landing-gallery]");
    if (!host) return;
    host.innerHTML = "";
    if (!state.gallery.length) {
      host.append(emptyState("Пока нет фото. Добавьте первое — URL или загрузка файла."));
      syncHidden();
      return;
    }
    state.gallery.forEach((item, index) => {
      const src = document.createElement("input");
      src.value = item.src || "";
      src.placeholder = "https://…";
      src.setAttribute("inputmode", "url");
      src.autocomplete = "off";
      src.spellcheck = false;
      src.addEventListener("input", () => { state.gallery[index].src = src.value; syncHidden(); });
      const alt = document.createElement("input");
      alt.value = item.alt || "";
      alt.placeholder = "Кратко, что на фото";
      alt.addEventListener("input", () => { state.gallery[index].alt = alt.value; syncHidden(); });
      const upload = document.createElement("button");
      upload.type = "button";
      upload.className = "ghost";
      upload.textContent = "Загрузить";
      upload.addEventListener("click", () => {
        uploadTarget = { kind: "gallery", index };
        fileInput?.click();
      });
      appendListRow(host, index, [
        fieldLabel("URL", src),
        fieldLabel("Подпись", alt),
        upload
      ], rowControls(
        () => { state.gallery = moveArrayItem(state.gallery, index, index - 1); renderGallery(); },
        () => { state.gallery = moveArrayItem(state.gallery, index, index + 1); renderGallery(); },
        () => { state.gallery.splice(index, 1); renderGallery(); },
        { confirmRemove: "Удалить это фото из галереи?" }
      ));
    });
    bindDrag(host, "gallery", renderGallery);
    syncHidden();
  }

  function renderButtons() {
    const host = form.querySelector("[data-landing-buttons]");
    if (!host) return;
    host.innerHTML = "";
    if (!state.buttons.length) {
      host.append(emptyState("Кнопок пока нет. Добавьте системную, соцсеть или свою ссылку."));
      syncHidden();
      return;
    }
    state.buttons.forEach((item, index) => {
      const kind = document.createElement("select");
      [["builtin", "Системная"], ["social", "Соцсеть"], ["custom", "Своя"]].forEach(([value, labelText]) => {
        const opt = document.createElement("option");
        opt.value = value;
        opt.textContent = labelText;
        if (item.kind === value) opt.selected = true;
        kind.append(opt);
      });
      kind.addEventListener("change", () => {
        state.buttons[index].kind = kind.value;
        if (kind.value === "social") {
          const first = Object.keys(social)[0] || "vk";
          state.buttons[index].network = first;
          state.buttons[index].label = social[first]?.label || first;
        } else if (kind.value === "builtin") {
          const first = Object.keys(builtin)[0] || "homegroups";
          state.buttons[index].network = first;
          state.buttons[index].label = builtin[first] || first;
          state.buttons[index].url = "";
        } else {
          state.buttons[index].network = "";
        }
        renderButtons();
      });

      const label = document.createElement("input");
      label.value = item.label || "";
      label.placeholder = "Текст на кнопке";
      label.addEventListener("input", () => { state.buttons[index].label = label.value; syncHidden(); });

      const network = document.createElement("select");
      if (item.kind === "social") {
        Object.entries(social).forEach(([key, meta]) => {
          const opt = document.createElement("option");
          opt.value = key;
          opt.textContent = meta.label;
          if (item.network === key) opt.selected = true;
          network.append(opt);
        });
        network.addEventListener("change", () => {
          state.buttons[index].network = network.value;
          if (!state.buttons[index].label) {
            state.buttons[index].label = social[network.value]?.label || network.value;
          }
          syncHidden();
        });
      } else if (item.kind === "builtin") {
        Object.entries(builtin).forEach(([key, title]) => {
          const opt = document.createElement("option");
          opt.value = key;
          opt.textContent = title;
          if (item.network === key) opt.selected = true;
          network.append(opt);
        });
        network.addEventListener("change", () => {
          state.buttons[index].network = network.value;
          state.buttons[index].label = builtin[network.value] || network.value;
          label.value = state.buttons[index].label;
          syncHidden();
        });
      } else {
        network.hidden = true;
      }

      const url = document.createElement("input");
      url.value = item.url || "";
      url.placeholder = "@username или https://…";
      url.hidden = item.kind === "builtin";
      url.addEventListener("input", () => { state.buttons[index].url = url.value; syncHidden(); });

      const icon = document.createElement("input");
      icon.value = item.icon || "";
      icon.placeholder = "https://…";
      icon.setAttribute("inputmode", "url");
      icon.autocomplete = "off";
      icon.spellcheck = false;
      icon.hidden = item.kind !== "custom";
      icon.addEventListener("input", () => { state.buttons[index].icon = icon.value; syncHidden(); });
      const iconUpload = document.createElement("button");
      iconUpload.type = "button";
      iconUpload.className = "ghost";
      iconUpload.textContent = "Загрузить иконку";
      iconUpload.hidden = item.kind !== "custom";
      iconUpload.addEventListener("click", () => {
        uploadTarget = { kind: "button-icon", index };
        fileInput?.click();
      });

      const fields = [
        fieldLabel("Тип", kind),
        item.kind === "custom" ? null : fieldLabel(item.kind === "builtin" ? "Действие" : "Сеть", network),
        fieldLabel("Название", label),
        item.kind === "builtin" ? null : fieldLabel("Ссылка", url),
        item.kind === "custom" ? fieldLabel("Иконка URL", icon) : null,
        item.kind === "custom" ? iconUpload : null
      ].filter(Boolean);

      appendListRow(host, index, fields, rowControls(
        () => { state.buttons = moveArrayItem(state.buttons, index, index - 1); renderButtons(); },
        () => { state.buttons = moveArrayItem(state.buttons, index, index + 1); renderButtons(); },
        () => { state.buttons.splice(index, 1); renderButtons(); },
        { confirmRemove: "Удалить эту кнопку?" }
      ));
    });
    bindDrag(host, "buttons", renderButtons);
    syncHidden();
  }

  function renderPastors() {
    const host = form.querySelector("[data-landing-pastors]");
    if (!host) return;
    host.innerHTML = "";
    if (!state.pastors.length) {
      host.append(emptyState("Пасторы ещё не добавлены."));
      syncHidden();
      return;
    }
    state.pastors.forEach((item, index) => {
      const name = document.createElement("input");
      name.value = item.name || "";
      name.placeholder = "Имя";
      name.addEventListener("input", () => { state.pastors[index].name = name.value; syncHidden(); });
      const photo = document.createElement("input");
      photo.value = item.photo || "";
      photo.placeholder = "https://…";
      photo.setAttribute("inputmode", "url");
      photo.autocomplete = "off";
      photo.spellcheck = false;
      photo.addEventListener("input", () => { state.pastors[index].photo = photo.value; syncHidden(); });
      const description = document.createElement("textarea");
      description.rows = 3;
      description.value = item.description || "";
      description.placeholder = "Краткое описание";
      description.addEventListener("input", () => { state.pastors[index].description = description.value; syncHidden(); });
      const upload = document.createElement("button");
      upload.type = "button";
      upload.className = "ghost";
      upload.textContent = "Загрузить";
      upload.addEventListener("click", () => {
        uploadTarget = { kind: "pastor", index };
        fileInput?.click();
      });
      appendListRow(host, index, [
        fieldLabel("Имя", name),
        fieldLabel("Фото URL", photo),
        upload,
        fieldLabel("Описание", description)
      ], rowControls(
        () => { state.pastors = moveArrayItem(state.pastors, index, index - 1); renderPastors(); },
        () => { state.pastors = moveArrayItem(state.pastors, index, index + 1); renderPastors(); },
        () => { state.pastors.splice(index, 1); renderPastors(); },
        { confirmRemove: "Удалить этого пастора из списка?" }
      ));
    });
    bindDrag(host, "pastors", renderPastors);
    syncHidden();
  }

  function renderSchedule() {
    const host = form.querySelector("[data-landing-schedule]");
    if (!host) return;
    host.innerHTML = "";
    if (!state.schedule.length) {
      host.append(emptyState("Расписание пустое. Добавьте первый пункт."));
      syncHidden();
      return;
    }
    state.schedule.forEach((item, index) => {
      const weekday = document.createElement("select");
      Object.entries(weekdays).forEach(([value, labelText]) => {
        const opt = document.createElement("option");
        opt.value = value;
        opt.textContent = labelText;
        if (String(item.weekday) === String(value)) opt.selected = true;
        weekday.append(opt);
      });
      weekday.addEventListener("change", () => { state.schedule[index].weekday = Number(weekday.value); syncHidden(); });
      const from = document.createElement("input");
      from.type = "time";
      from.value = item.time_from || "";
      from.addEventListener("input", () => { state.schedule[index].time_from = from.value; syncHidden(); });
      const to = document.createElement("input");
      to.type = "time";
      to.value = item.time_to || "";
      to.addEventListener("input", () => { state.schedule[index].time_to = to.value; syncHidden(); });
      const title = document.createElement("input");
      title.value = item.title || "";
      title.placeholder = "Например: Богослужение";
      title.addEventListener("input", () => { state.schedule[index].title = title.value; syncHidden(); });
      const note = document.createElement("input");
      note.value = item.note || "";
      note.placeholder = "Уточнение (необязательно)";
      note.addEventListener("input", () => { state.schedule[index].note = note.value; syncHidden(); });
      appendListRow(host, index, [
        fieldLabel("День", weekday),
        fieldLabel("С", from),
        fieldLabel("До", to),
        fieldLabel("Название", title),
        fieldLabel("Уточнение", note)
      ], rowControls(
        () => { state.schedule = moveArrayItem(state.schedule, index, index - 1); renderSchedule(); },
        () => { state.schedule = moveArrayItem(state.schedule, index, index + 1); renderSchedule(); },
        () => { state.schedule.splice(index, 1); renderSchedule(); },
        { confirmRemove: "Удалить пункт расписания?" }
      ));
    });
    bindDrag(host, "schedule", renderSchedule);
    syncHidden();
  }

  function renderEvents() {
    const host = form.querySelector("[data-landing-events]");
    if (!host) return;
    host.innerHTML = "";
    if (!state.events.length) {
      host.append(emptyState("Событий пока нет."));
      syncHidden();
      return;
    }
    state.events.forEach((item, index) => {
      const date = document.createElement("input");
      date.type = "date";
      date.value = item.date || "";
      date.addEventListener("input", () => { state.events[index].date = date.value; syncHidden(); });
      const from = document.createElement("input");
      from.type = "time";
      from.value = item.time_from || "";
      from.addEventListener("input", () => { state.events[index].time_from = from.value; syncHidden(); });
      const to = document.createElement("input");
      to.type = "time";
      to.value = item.time_to || "";
      to.addEventListener("input", () => { state.events[index].time_to = to.value; syncHidden(); });
      const title = document.createElement("input");
      title.value = item.title || "";
      title.placeholder = "Название события";
      title.addEventListener("input", () => { state.events[index].title = title.value; syncHidden(); });
      const note = document.createElement("input");
      note.value = item.note || "";
      note.placeholder = "Уточнение";
      note.addEventListener("input", () => { state.events[index].note = note.value; syncHidden(); });
      appendListRow(host, index, [
        fieldLabel("Дата", date),
        fieldLabel("С", from),
        fieldLabel("До", to),
        fieldLabel("Название", title),
        fieldLabel("Уточнение", note)
      ], rowControls(
        () => { state.events = moveArrayItem(state.events, index, index - 1); renderEvents(); },
        () => { state.events = moveArrayItem(state.events, index, index + 1); renderEvents(); },
        () => { state.events.splice(index, 1); renderEvents(); },
        { confirmRemove: "Удалить событие?" }
      ));
    });
    bindDrag(host, "events", renderEvents);
    syncHidden();
  }

  function renderMinistries() {
    const host = form.querySelector("[data-landing-ministries]");
    if (!host) return;
    host.innerHTML = "";
    if (!state.ministries.length) {
      host.append(emptyState("Направления ещё не добавлены."));
      syncHidden();
      return;
    }
    state.ministries.forEach((item, index) => {
      const title = document.createElement("input");
      title.value = item.title || "";
      title.placeholder = "Название направления";
      title.addEventListener("input", () => { state.ministries[index].title = title.value; syncHidden(); });
      const description = document.createElement("textarea");
      description.rows = 3;
      description.value = item.description || "";
      description.placeholder = "Описание";
      description.addEventListener("input", () => { state.ministries[index].description = description.value; syncHidden(); });
      const url = document.createElement("input");
      url.value = item.url || "";
      url.placeholder = "https://… или @telegram";
      url.autocomplete = "off";
      url.spellcheck = false;
      url.addEventListener("input", () => { state.ministries[index].url = url.value; syncHidden(); });
      appendListRow(host, index, [
        fieldLabel("Название", title),
        fieldLabel("Описание", description),
        fieldLabel("Ссылка", url)
      ], rowControls(
        () => { state.ministries = moveArrayItem(state.ministries, index, index - 1); renderMinistries(); },
        () => { state.ministries = moveArrayItem(state.ministries, index, index + 1); renderMinistries(); },
        () => { state.ministries.splice(index, 1); renderMinistries(); },
        { confirmRemove: "Удалить направление?" }
      ));
    });
    bindDrag(host, "ministries", renderMinistries);
    syncHidden();
  }

  function renderFaq() {
    const host = form.querySelector("[data-landing-faq]");
    if (!host) return;
    host.innerHTML = "";
    if (!state.faq.length) {
      host.append(emptyState("Вопросов пока нет."));
      syncHidden();
      return;
    }
    state.faq.forEach((item, index) => {
      const question = document.createElement("input");
      question.value = item.question || "";
      question.placeholder = "Вопрос";
      question.addEventListener("input", () => { state.faq[index].question = question.value; syncHidden(); });
      const answer = document.createElement("textarea");
      answer.rows = 3;
      answer.value = item.answer || "";
      answer.placeholder = "Ответ";
      answer.addEventListener("input", () => { state.faq[index].answer = answer.value; syncHidden(); });
      appendListRow(host, index, [
        fieldLabel("Вопрос", question),
        fieldLabel("Ответ", answer)
      ], rowControls(
        () => { state.faq = moveArrayItem(state.faq, index, index - 1); renderFaq(); },
        () => { state.faq = moveArrayItem(state.faq, index, index + 1); renderFaq(); },
        () => { state.faq.splice(index, 1); renderFaq(); },
        { confirmRemove: "Удалить этот вопрос?" }
      ));
    });
    bindDrag(host, "faq", renderFaq);
    syncHidden();
  }

  async function uploadImage(file) {
    if (!uploadUrl || !file) return null;
    const data = new FormData();
    data.append("image", file);
    const headers = { ...ajaxHeaders };
    const token = csrfToken();
    if (token) headers["X-CSRF-Token"] = token;
    const response = await fetch(uploadUrl, {
      method: "POST",
      credentials: "same-origin",
      headers,
      body: data
    });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Не удалось загрузить файл");
    }
    return payload.path;
  }

  fileInput?.addEventListener("change", async () => {
    const file = fileInput.files?.[0];
    if (!file || !uploadTarget) return;
    try {
      setLoading(true);
      const path = await uploadImage(file);
      if (!path) return;
      if (uploadTarget.kind === "gallery") {
        state.gallery[uploadTarget.index].src = path;
        renderGallery();
      } else if (uploadTarget.kind === "pastor") {
        state.pastors[uploadTarget.index].photo = path;
        renderPastors();
      } else if (uploadTarget.kind === "button-icon") {
        state.buttons[uploadTarget.index].icon = path;
        renderButtons();
      } else if (uploadTarget.kind) {
        const field = form.querySelector(`[data-landing-media-field="${uploadTarget.kind}"]`);
        if (field) field.value = path;
      }
      showToast("Картинка загружена");
    } catch (error) {
      showToast(error.message || "Ошибка загрузки", "error");
    } finally {
      setLoading(false);
      fileInput.value = "";
      uploadTarget = null;
    }
  });

  form.querySelectorAll("[data-landing-upload]").forEach((button) => {
    button.addEventListener("click", () => {
      uploadTarget = { kind: button.getAttribute("data-landing-upload") };
      fileInput?.click();
    });
  });

  form.querySelectorAll("[data-landing-add]").forEach((button) => {
    button.addEventListener("click", () => {
      const type = button.getAttribute("data-landing-add");
      if (type === "gallery") {
        if (state.gallery.length >= 6) {
          showToast("Можно добавить не больше 6 фото", "error");
          return;
        }
        state.gallery.push({ src: "", alt: "", sort: state.gallery.length });
        renderGallery();
      } else if (type === "pastor") {
        state.pastors.push({ name: "", photo: "", description: "", sort: state.pastors.length });
        renderPastors();
      } else if (type === "schedule") {
        state.schedule.push({ weekday: 7, time_from: "", time_to: "", title: "", note: "", sort: state.schedule.length });
        renderSchedule();
      } else if (type === "event") {
        state.events.push({ date: "", time_from: "", time_to: "", title: "", note: "", sort: state.events.length });
        renderEvents();
      } else if (type === "ministry") {
        state.ministries.push({ title: "", description: "", url: "", sort: state.ministries.length });
        renderMinistries();
      } else if (type === "faq") {
        state.faq.push({ question: "", answer: "", sort: state.faq.length });
        renderFaq();
      } else if (type === "button-builtin") {
        const first = Object.keys(builtin)[0] || "homegroups";
        state.buttons.push({ kind: "builtin", network: first, label: builtin[first] || first, url: "", icon: "", sort: state.buttons.length });
        renderButtons();
      } else if (type === "button-social") {
        const first = Object.keys(social)[0] || "vk";
        state.buttons.push({ kind: "social", network: first, label: social[first]?.label || first, url: "", icon: "", sort: state.buttons.length });
        renderButtons();
      } else if (type === "button-custom") {
        state.buttons.push({ kind: "custom", network: "", label: "", url: "", icon: "", sort: state.buttons.length });
        renderButtons();
      }
    });
  });

  form.addEventListener("submit", () => {
    syncHidden();
  });

  renderSections();
  renderGallery();
  renderButtons();
  renderPastors();
  renderSchedule();
  renderEvents();
  renderMinistries();
  renderFaq();
  syncHidden();
}

function initChurchLandingEditor(root) {
  if (root instanceof Element || root instanceof Document) {
    initChurchLandingEditors(root);
    return;
  }
  initChurchLandingEditors(document);
}

initChurchLandingEditors();
churchLandingBindMap();

