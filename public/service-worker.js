const CACHE_NAME = "church-care-v24";
const ASSETS = [
  "/assets/app.css",
  "/assets/app.js",
  "/assets/fonts/montserrat-400.woff2",
  "/assets/fonts/montserrat-500.woff2",
  "/assets/fonts/montserrat-600.woff2",
  "/assets/fonts/montserrat-700.woff2",
  "/assets/fonts/montserrat-800.woff2",
  "/assets/icon.svg",
  "/assets/icon-192.png",
  "/assets/icon-512.png",
  "/assets/logo.svg"
];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS)));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))));
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") return;

  const url = new URL(event.request.url);
  const isNavigation = event.request.mode === "navigate" || event.request.destination === "document";
  if (url.origin === self.location.origin && isNavigation) {
    event.respondWith(fetch(event.request, { cache: "no-store" }));
    return;
  }

  const isStaticAsset = url.origin === self.location.origin && ASSETS.includes(url.pathname);
  if (!isStaticAsset) return;

  event.respondWith(caches.match(event.request).then((cached) => {
    if (cached) return cached;
    return fetch(event.request).then((response) => {
      const copy = response.clone();
      caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      return response;
    });
  }));
});

self.addEventListener("push", (event) => {
  let payload = { title: "CRM Домашних групп", body: "Есть новое напоминание.", url: "/" };
  if (event.data) {
    try { payload = { ...payload, ...event.data.json() }; } catch (_) { payload.body = event.data.text(); }
  }
  event.waitUntil(self.registration.showNotification(payload.title, {
    body: payload.body,
    icon: "/assets/icon-192.png",
    badge: "/assets/icon-192.png",
    data: { url: payload.url || "/" }
  }));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = event.notification.data?.url || "/";
  event.waitUntil(clients.matchAll({ type: "window" }).then((clientList) => {
    for (const client of clientList) {
      if ("focus" in client) {
        client.navigate(url);
        return client.focus();
      }
    }
    return clients.openWindow(url);
  }));
});
