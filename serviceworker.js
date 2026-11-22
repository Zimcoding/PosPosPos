/*
 * Copyright (C) 2025		Zimcoding
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/fluxpos/serviceworker.js
 *	\brief      Service worker of FluxPos: precaches the application shell and serves
 *				static assets and the last known pages when the terminal is offline.
 *
 *				Scope is the fluxpos module directory, so it only controls the FluxPos
 *				pages. Write requests (POST and the tokenized ajax calls) are never
 *				intercepted: the JS layers fall back on IndexedDB when offline.
 */

var fluxposCacheName = 'fluxpos-offline-v14';

// Directory of the module, derived from the scope of this service worker, so the module
// works whether it is installed into htdocs/fluxpos or htdocs/custom/fluxpos (or any other
// directory: the scope of a service worker is always the directory it is served from).
var fluxposModulePath = new URL(self.registration.scope).pathname.replace(/\/$/, '');


self.addEventListener('install', function (event) {
	// Relative URLs are resolved against the fluxpos module directory
	var urlsToCache = [
		'css/pos11.css.php',
		'css/colorbox.css',
		'js/jquery.colorbox-min.js',
		'js/offline11.js',
		'genimg/empty.png'
	];
	event.waitUntil(
		caches.open(fluxposCacheName).then(function (cache) {
			return cache.addAll(urlsToCache).catch(function (error) {
				console.log('FluxPos offline: precache failed', error);
			});
		})
	);
	self.skipWaiting();
});

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys().then(function (keys) {
			return Promise.all(
				keys.filter(function (key) {
					return key !== fluxposCacheName;
				}).map(function (key) {
					return caches.delete(key);
				})
			);
		})
	);
	self.clients.claim();
});

self.addEventListener('fetch', function (event) {
	var request = event.request;

	// Never intercept writes (payments, cart changes, form posts)
	if (request.method !== 'GET') {
		return;
	}

	var url = new URL(request.url);
	if (url.origin !== self.location.origin) {
		return;
	}
	var pathname = url.pathname;

	// Stylesheets and scripts of the module (css/pos11.css.php, css/colorbox.css,
	// js/offline11.js, ...): NETWORK FIRST, cached copy only when the server cannot
	// be reached. A cache-first strategy here would serve a stylesheet or an engine
	// captured long ago even after an update and even on a hard refresh, which is how
	// terminals kept running an old layout after deploying a new version.
	var isModuleCodeAsset = pathname.indexOf(fluxposModulePath + '/css/') >= 0 ||
		pathname.indexOf(fluxposModulePath + '/js/') >= 0;
	if (isModuleCodeAsset) {
		event.respondWith(fluxposNetworkFirst(request));
		return;
	}

	// Page navigations: network first to keep pages fresh, cached shell when offline
	if (request.mode === 'navigate') {
		event.respondWith(fluxposNetworkFirst(request));
		return;
	}

	// Everything else (images of any kind, ajax calls, dynamic PHP fragments, ...):
	// left untouched, no interception at all. Product, category and placeholder
	// images go straight to the browser HTTP cache, so a redirect (genimg/index.php
	// answers with a redirect to the nophoto placeholder for the products without a
	// photo) can never end in a service worker error again, and images simply break
	// gracefully when offline instead of failing inside the cache.
});


/**
 * Serve a request from the network, falling back on the cached copy when offline.
 *
 * @param  {Request} request Request to serve
 * @returns {Promise<Response>} Promise of the response
 */
function fluxposNetworkFirst(request) {
	return caches.open(fluxposCacheName).then(function (cache) {
		return fetch(request).then(function (response) {
			if (response.ok && response.type === 'basic') {
				// A failing cache write (quota, redirected or opaque response, ...)
				// must never break the request: the copy is just dropped.
				Promise.resolve(cache.put(request, response.clone())).catch(function (error) {
					console.log('FluxPos offline: cache write failed', error);
				});
			}
			return response;
		}).catch(function () {
			return cache.match(request).then(function (cached) {
				return cached || Response.error();
			});
		});
	});
}
