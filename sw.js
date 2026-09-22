/* Roma PWA Service Worker — network-first for pages, cache-first for assets. */
'use strict';

var CACHE_VERSION = 'roma-v1';
var STATIC_CACHE = CACHE_VERSION + '-static';
var PAGES_CACHE = CACHE_VERSION + '-pages';

function scopeBase() {
  // sw.js lives at the app root (e.g. /roma97/sw.js); scope covers the app.
  var scope = (self.registration && self.registration.scope) || './';
  if (scope.charAt(scope.length - 1) !== '/') scope += '/';
  return scope;
}

function abs(path) {
  return new URL(path, scopeBase()).toString();
}

var PRECACHE = [
  './',
  'index.php',
  'parent/index.php',
  'login.php',
  'manifest.webmanifest',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/icons/icon-180.png',
  'assets/css/fonts.css',
  'assets/css/app-shell.css',
  'assets/css/parent-portal.css',
  'assets/js/app-shell.js',
  'assets/js/pwa-install.js'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC_CACHE).then(function (cache) {
      var urls = PRECACHE.map(function (p) { return abs(p); });
      return cache.addAll(urls.map(function (u) { return new Request(u, { cache: 'reload' }); }))
        .catch(function () { /* offline on first install — skip */ });
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key.indexOf('roma-v') === 0 && key !== STATIC_CACHE && key !== PAGES_CACHE) {
          return caches.delete(key);
        }
        return Promise.resolve(false);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isAsset(url) {
  return /\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot)(\?|#|$)/i.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;
  var url;
  try { url = new URL(req.url); } catch (e) { return; }
  // Only handle same-origin requests inside our scope.
  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf(new URL(scopeBase()).pathname) !== 0) return;

  // Navigations (HTML pages): network first, fall back to cache, then home.
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').indexOf('text/html') !== -1) {
    event.respondWith(
      fetch(req).then(function (res) {
        var copy = res.clone();
        caches.open(PAGES_CACHE).then(function (cache) { cache.put(req, copy); });
        return res;
      }).catch(function () {
        return caches.match(req).then(function (hit) {
          return hit || caches.match(abs('index.php')).then(function (home) {
            return home || Response.error();
          });
        });
      })
    );
    return;
  }

  // Static assets: cache first, refresh in background.
  if (isAsset(url)) {
    event.respondWith(
      caches.match(req).then(function (hit) {
        var network = fetch(req).then(function (res) {
          if (res && res.ok) {
            var copy = res.clone();
            caches.open(STATIC_CACHE).then(function (cache) { cache.put(req, copy); });
          }
          return res;
        }).catch(function () { return hit; });
        return hit || network;
      })
    );
  }
});
