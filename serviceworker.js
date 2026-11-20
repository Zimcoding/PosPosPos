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
 *	\file       htdocs/takepos/serviceworker.js
 *	\brief      Service worker of TakePOS: precaches the application shell and serves
 *				static assets and the last known pages when the terminal is offline.
 *
 *				Scope is the takepos module directory, so it only controls the TakePOS
 *				pages. Write requests (POST and the tokenized ajax calls) are never
 *				intercepted: the JS layers fall back on IndexedDB when offline.
 */

var takeposCacheName = 'takepos-offline-v1';


self.addEventListener('install', function (event) {
	// Relative URLs are resolved against the takepos module directory
	var urlsToCache = [
		'css/pos.css.php',
		'css/colorbox.css',
		'js/jquery.colorbox-min.js',
		'js/offline.js',
		'genimg/empty.png'
	];
	event.waitUntil(
		caches.open(takeposCacheName).then(function (cache) {
			return cache.addAll(urlsToCache).catch(function (error) {
				console.log('TakePOS offline: precache failed', error);
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
					return key !== takeposCacheName;
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

	// Static assets of the module and of Dolibarr core: cache first, then network
	var isStaticAsset = pathname.indexOf('/takepos/css/') >= 0 ||
		pathname.indexOf('/takepos/js/') >= 0 ||
		pathname.indexOf('/takepos/img/') >= 0 ||
		pathname.indexOf('/takepos/genimg/') >= 0 ||
		/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)$/.test(pathname);
	if (isStaticAsset) {
		event.respondWith(takeposCacheFirst(request));
		return;
	}

	// Page navigations: network first to keep pages fresh, cached shell when offline
	if (request.mode === 'navigate') {
		event.respondWith(takeposNetworkFirst(request));
		return;
	}

	// Everything else (ajax calls, dynamic PHP fragments): network only
});


/**
 * Serve a request from the cache, filling the cache from the network on miss.
 *
 * @param  {Request} request Request to serve
 * @returns {Promise<Response>} Promise of the response
 */
function takeposCacheFirst(request) {
	return caches.open(takeposCacheName).then(function (cache) {
		return cache.match(request).then(function (cached) {
			if (cached) {
				return cached;
			}
			return fetch(request).then(function (response) {
				if (response.ok) {
					cache.put(request, response.clone());
				}
				return response;
			});
		});
	});
}

/**
 * Serve a request from the network, falling back on the cached copy when offline.
 *
 * @param  {Request} request Request to serve
 * @returns {Promise<Response>} Promise of the response
 */
function takeposNetworkFirst(request) {
	return caches.open(takeposCacheName).then(function (cache) {
		return fetch(request).then(function (response) {
			if (response.ok) {
				cache.put(request, response.clone());
			}
			return response;
		}).catch(function () {
			return cache.match(request).then(function (cached) {
				return cached || Response.error();
			});
		});
	});
}
