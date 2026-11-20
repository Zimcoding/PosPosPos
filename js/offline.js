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
 *	\file       htdocs/takepos/js/offline.js
 *	\brief      Offline support for TakePOS: catalog cache into IndexedDB, background
 *				synchronization and online/offline status.
 *
 *				Phase 1 (read only): the product catalog (categories, products, prices)
 *				is synchronized in background when online and read from IndexedDB when
 *				the terminal loses connectivity. Order entry still requires the server.
 */

var TakePOSOfflineDBName = 'takepos';
var TakePOSOfflineDBVersion = 1;


/**
 * Open the TakePOS offline IndexedDB database.
 *
 * @returns {Promise<IDBDatabase>} Promise of the database connection
 */
function openTakePOSOfflineDB() {
	'use strict';

	return new Promise(function (resolve, reject) {
		if (!window.indexedDB) {
			reject(new Error('IndexedDB is not supported by this browser'));
			return;
		}
		var request = window.indexedDB.open(TakePOSOfflineDBName, TakePOSOfflineDBVersion);
		request.onupgradeneeded = function (event) {
			var db = event.target.result;
			if (!db.objectStoreNames.contains('catalog')) {
				db.createObjectStore('catalog');
			}
		};
		request.onsuccess = function (event) {
			resolve(event.target.result);
		};
		request.onerror = function (event) {
			reject(event.target.error);
		};
	});
}

/**
 * Read one entry of the catalog store.
 *
 * @param  {string} key Key of the entry
 * @returns {Promise<*>} Promise of the entry value (undefined if not found)
 */
function takePOSOfflineStoreGet(key) {
	'use strict';

	return openTakePOSOfflineDB().then(function (db) {
		return new Promise(function (resolve, reject) {
			var transaction = db.transaction('catalog', 'readonly');
			var request = transaction.objectStore('catalog').get(key);
			request.onsuccess = function () {
				resolve(request.result);
			};
			request.onerror = function () {
				reject(request.error);
			};
			transaction.oncomplete = function () {
				db.close();
			};
		});
	});
}

/**
 * Write one entry of the catalog store.
 *
 * @param  {string} key   Key of the entry
 * @param  {*}      value Value to store
 * @returns {Promise<bool>} Promise resolved when the write is done
 */
function takePOSOfflineStorePut(key, value) {
	'use strict';

	return openTakePOSOfflineDB().then(function (db) {
		return new Promise(function (resolve, reject) {
			var transaction = db.transaction('catalog', 'readwrite');
			transaction.objectStore('catalog').put(value, key);
			transaction.oncomplete = function () {
				db.close();
				resolve(true);
			};
			transaction.onerror = function () {
				reject(transaction.error);
			};
		});
	});
}


var TakePOSOffline = {
	takeposbaseurl: (typeof takeposbaseuri != 'undefined') ? takeposbaseuri : '/takepos/',
	takeposnewtoken: (typeof takeposnewtoken != 'undefined') ? takeposnewtoken : '',

	/**
	 * Register the service worker that precaches the application shell.
	 */
	registerServiceWorker: function () {
		'use strict';

		if (!('serviceWorker' in navigator)) {
			console.log('TakePOS offline: service workers are not supported');
			return;
		}
		window.addEventListener('load', function () {
			navigator.serviceWorker.register(TakePOSOffline.takeposbaseurl + 'serviceworker.js').catch(function (error) {
				console.log('TakePOS offline: service worker registration failed', error);
			});
		});
	},

	/**
	 * Synchronize the full product catalog in background into IndexedDB.
	 * Never blocks the UI and never throws: failures are only logged.
	 *
	 * @returns {Promise<bool>} Promise resolved to true when the catalog has been synchronized
	 */
	syncCatalog: function () {
		'use strict';

		if (!window.indexedDB || !navigator.onLine) {
			return Promise.resolve(false);
		}
		var self = this;
		var socid = parseInt(jQuery('#thirdpartyid').val(), 10) || 0;
		var url = this.takeposbaseurl + 'ajax/ajax.php?action=getCatalog&token=' + this.takeposnewtoken + '&thirdpartyid=' + socid;

		return jQuery.getJSON(url).then(function (data) {
			var puts = [];
			var allproducts = {};

			jQuery.each(data.products || {}, function (catkey, products) {
				for (var i = 0; i < products.length; i++) {
					allproducts[products[i].rowid] = products[i];
				}
				puts.push(takePOSOfflineStorePut('products:' + socid + ':' + catkey, products));
			});
			puts.push(takePOSOfflineStorePut('products:' + socid + ':__all__', allproducts));
			puts.push(takePOSOfflineStorePut('maincategories', data.maincategories || {}));
			puts.push(takePOSOfflineStorePut('subcategories', data.subcategories || {}));
			puts.push(takePOSOfflineStorePut('lastsync', Math.floor(Date.now() / 1000)));

			return Promise.all(puts).then(function () {
				console.log('TakePOS offline: catalog synchronized for offline use');
				return true;
			});
		}).catch(function (error) {
			console.log('TakePOS offline: catalog synchronization failed', error);
			return false;
		});
	},

	/**
	 * Get the cached products of one category.
	 *
	 * @param  {number|string} catid ID of category or 'supplements'
	 * @param  {number}        socid ID of thirdparty (for the price level)
	 * @returns {Promise<Array|null>} Promise of the products array, or null when not cached
	 */
	getCategoryProducts: function (catid, socid) {
		'use strict';

		return takePOSOfflineStoreGet('products:' + socid + ':' + catid).then(function (value) {
			return (typeof value == 'undefined') ? null : value;
		}).catch(function () {
			return null;
		});
	},

	/**
	 * Search into the cached catalog (ref, label and barcode).
	 *
	 * @param  {string} search_term Term to search
	 * @param  {number} socid       ID of thirdparty (for the price level)
	 * @param  {number} limit       Maximum number of results
	 * @returns {Promise<Array>} Promise of the matching products
	 */
	searchProducts: function (search_term, socid, limit) {
		'use strict';

		var term = (search_term || '').toLowerCase();

		return takePOSOfflineStoreGet('products:' + socid + ':__all__').then(function (allproducts) {
			var res = [];
			if (!allproducts) {
				return res;
			}
			for (var rowid in allproducts) {
				var product = allproducts[rowid];
				var barcode = product.barcode || '';
				if (product.ref.toLowerCase().indexOf(term) >= 0 || product.label.toLowerCase().indexOf(term) >= 0 || barcode.toLowerCase().indexOf(term) >= 0) {
					res.push(product);
					if (limit > 0 && res.length >= limit) {
						break;
					}
				}
			}
			return res;
		}).catch(function () {
			return [];
		});
	},

	/**
	 * Init the online/offline status indicator and its listeners.
	 */
	initOfflineStatus: function () {
		'use strict';

		var self = this;
		window.addEventListener('online', function () {
			self.updateOfflineStatus();
			self.syncCatalog();
		});
		window.addEventListener('offline', function () {
			self.updateOfflineStatus();
		});
		this.updateOfflineStatus();
	},

	/**
	 * Show or hide the offline status pill.
	 */
	updateOfflineStatus: function () {
		'use strict';

		var element = document.getElementById('takeposofflinestatus');
		if (!element) {
			return;
		}
		if (!navigator.onLine) {
			element.textContent = 'Offline - catalog only';
			element.classList.add('offline');
		} else {
			element.classList.remove('offline');
		}
	}
};
