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
 *	\file       htdocs/fluxpos/js/offline11.js
 *	\brief      Offline support for FluxPos: catalog cache into IndexedDB, online/offline
 *				status and offline order entry with background synchronization.
 *
 *				Phase 1 (read only): the product catalog (categories, products, prices)
 *				is synchronized in background when online and read from IndexedDB when
 *				the terminal loses connectivity. Order entry still requires the server.
 *
 *				Phase 2 (offline order entry): a new order (with no invoice created yet on
 *				the server) can be captured while offline into a local cart stored into
 *				IndexedDB and displayed instead of the #poslines fragment of invoice.php.
 *				The local carts are replayed in background to the syncOfflineOrder endpoint
 *				of ajax/ajax.php when the connection is back, without interrupting the
 *				capture of new orders.
 *
 *				Phase 3 (offline payment and validation): the cashier can pay and validate
 *				an order captured while offline (cash, credit card or cheque, with change
 *				given for cash). The paid order becomes a SALE with its own unique id
 *				(stored as 'sale:<uid>'), and the cart of the place is emptied at once so
 *				the next customer can be served. Each sale is sent to the syncOfflineSale
 *				endpoint, which books it exactly once (invoice number, payments) even when
 *				it is sent again; the receipt is printed by the server receipt printer.
 *
 *				Offline detection: the terminal is offline when the browser has no network
 *				(navigator.onLine false) OR when a call to the server fails for a network
 *				reason (no answer, timeout, gateway error): Wi-Fi can be up while the
 *				server cannot be reached. A light ping finds out when it is back.
 */

var FluxPosOfflineDBName = 'fluxpos';
var FluxPosOfflineDBVersion = 1;


/**
 * Open the FluxPos offline IndexedDB database.
 *
 * @returns {Promise<IDBDatabase>} Promise of the database connection
 */
function openFluxPosOfflineDB() {
	'use strict';

	return new Promise(function (resolve, reject) {
		if (!window.indexedDB) {
			reject(new Error((window.fluxposlang && window.fluxposlang.FluxPosOfflineNoIndexedDB) ? window.fluxposlang.FluxPosOfflineNoIndexedDB : 'IndexedDB is not supported by this browser'));
			return;
		}
		var request = window.indexedDB.open(FluxPosOfflineDBName, FluxPosOfflineDBVersion);
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

	return openFluxPosOfflineDB().then(function (db) {
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
 * Remove one entry of the catalog store.
 *
 * @param  {string} key Key of the entry
 * @returns {Promise<bool>} Promise resolved when the removal is done
 */
function takePOSOfflineStoreDelete(key) {
	'use strict';

	return openFluxPosOfflineDB().then(function (db) {
		return new Promise(function (resolve, reject) {
			var transaction = db.transaction('catalog', 'readwrite');
			transaction.objectStore('catalog')['delete'](key);
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

/**
 * Read the keys of the entries of the catalog store having a given prefix.
 *
 * @param  {string} prefix Prefix of the keys to return
 * @returns {Promise<Array>} Promise of the matching keys
 */
function takePOSOfflineStoreKeys(prefix) {
	'use strict';

	return openFluxPosOfflineDB().then(function (db) {
		return new Promise(function (resolve, reject) {
			var transaction = db.transaction('catalog', 'readonly');
			var request = transaction.objectStore('catalog').getAllKeys();
			request.onsuccess = function () {
				var keys = [];
				for (var i = 0; i < request.result.length; i++) {
					if (!prefix || String(request.result[i]).indexOf(prefix) === 0) {
						keys.push(request.result[i]);
					}
				}
				resolve(keys);
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

	return openFluxPosOfflineDB().then(function (db) {
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

/**
 * Write one entry and remove another one in ONE transaction: both happen, or neither
 * (used to turn a cart into a sale, so a sale can never also stay as an open cart).
 *
 * @param  {string} putkey    Key of the entry to write
 * @param  {*}      value     Value to write
 * @param  {string} deletekey Key of the entry to remove
 * @returns {Promise<bool>} Promise resolved when the transaction is done
 */
function takePOSOfflineStorePutAndDelete(putkey, value, deletekey) {
	'use strict';

	return openFluxPosOfflineDB().then(function (db) {
		return new Promise(function (resolve, reject) {
			var transaction = db.transaction('catalog', 'readwrite');
			var store = transaction.objectStore('catalog');
			store.put(value, putkey);
			store['delete'](deletekey);
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


var FluxPosOffline = {
	/**
	 * Root URL of the module ('/custom/fluxpos/' for a custom placement), resolved at
	 * call time. Prefers the fluxposbaseuri global of the page; falls back on the URL
	 * of this script file (.../fluxpos/js/offline11.js), the only reliable marker when
	 * the page does not define the global.
	 *
	 * @returns {string} Root URL of the module, with a trailing slash
	 */
	get fluxposbaseurl() {
		'use strict';

		if (typeof window.fluxposbaseuri == 'string' && window.fluxposbaseuri) {
			return window.fluxposbaseuri;
		}
		var scripts = document.getElementsByTagName('script');
		for (var i = 0; i < scripts.length; i++) {
			var src = scripts[i].src || '';
			if (/(^|\/)offline\d*\.js([?#]|$)/.test(src)) {
				return src.replace(/js\/offline\d*\.js([?#].*)?$/, '');
			}
		}
		return '/fluxpos/';
	},

	/**
	 * Security token of the POS screen, resolved at call time.
	 *
	 * @returns {string} Current CSRF token of the page (empty when unknown)
	 */
	get fluxposnewtoken() {
		'use strict';

		return (typeof window.fluxposnewtoken == 'string') ? window.fluxposnewtoken : '';
	},

	/**
	 * Currency code of the Dolibarr installation, resolved at call time.
	 *
	 * @returns {string} Currency code
	 */
	get fluxposcurrency() {
		'use strict';

		return (typeof window.fluxposcurrency == 'string') ? window.fluxposcurrency : '';
	},

	/**
	 * Translation strings passed by index.php, resolved at call time.
	 *
	 * @returns {Object} Map of translation keys
	 */
	get fluxposlang() {
		'use strict';

		return (window.fluxposlang && typeof window.fluxposlang == 'object') ? window.fluxposlang : {};
	},

	serverDown: false,			// true after a call to the server failed for a network reason
	requestTimeout: 15000,		// ms before a call to the server is considered lost
	probeInterval: 15000,		// ms between two checks of the server while it is down
	_lastSaleNotice: '',		// message shown after a sale was stored on the terminal
	_separatePlace: null,		// place where a new order was started offline next to an order on the server
	_lastTokenReload: 0,		// timestamp of the last automatic reload after a refused token
	_lastBlockedNotice: 0,		// timestamp of the last "order locked on the server" notice

	/**
	 * Start a new order on this terminal while the place shows an order stored on the
	 * server that cannot be changed offline. The new order is kept apart: it is never
	 * merged into the order on the server, it can only be paid (then it is a sale).
	 *
	 * @returns {Promise<bool>} Promise of true when the empty order is shown
	 */
	startSeparateOrder: function () {
		'use strict';

		this._separatePlace = (typeof place != 'undefined') ? String(place) : null;
		return this.renderCart(true);
	},

	/**
	 * Is the terminal offline? True when the browser has no network, or when the server
	 * could not be reached on the last call (Wi-Fi up but server or internet down).
	 *
	 * @returns {boolean} True when the terminal must work offline
	 */
	isOffline: function () {
		'use strict';

		return !navigator.onLine || this.serverDown;
	},

	/**
	 * Did an ajax call fail because the server could not be reached (as opposed to an
	 * error answered by the server)?
	 *
	 * @param  {Object} jqxhr      jQuery XHR of the failed call
	 * @param  {string} textStatus Status text given by jQuery ('timeout', 'error', ...)
	 * @returns {boolean} True for a network failure
	 */
	isNetworkFailure: function (jqxhr, textStatus) {
		'use strict';

		if (textStatus == 'abort') {
			return false;
		}
		if (textStatus == 'timeout') {
			return true;
		}
		var status = (jqxhr && typeof jqxhr.status != 'undefined') ? jqxhr.status : 0;
		return status === 0 || status == 502 || status == 503 || status == 504;
	},

	/**
	 * Is this URL on the Dolibarr server (same origin as the page)?
	 *
	 * @param  {string} url URL of an ajax call (absolute or relative)
	 * @returns {boolean} True when the call goes to the Dolibarr server
	 */
	isDolibarrUrl: function (url) {
		'use strict';

		try {
			return new URL(url, window.location.href).origin === window.location.origin;
		} catch (error) {
			return false;
		}
	},

	/**
	 * To call from the failure handler of an ajax call. When the failure is a network
	 * failure, the terminal switches to offline mode and true is returned, so the caller
	 * can fall back to the data stored on the terminal. When the server answered that the
	 * security token of the page is not valid anymore (HTTP 403 of the CSRF check), the
	 * screen is reloaded to get a fresh one.
	 *
	 * @param  {Object} jqxhr      jQuery XHR of the failed call
	 * @param  {string} textStatus Status text given by jQuery
	 * @returns {boolean} True when the caller must work offline
	 */
	handleFailure: function (jqxhr, textStatus) {
		'use strict';

		if (this.isNetworkFailure(jqxhr, textStatus)) {
			this.markServerDown();
			return true;
		}
		if (this.isSecurityTokenFailure(jqxhr)) {
			this.handleSecurityTokenFailure();
			return false;
		}
		return !navigator.onLine;
	},

	/**
	 * Does this answer mean that the security token of the page was refused by the CSRF
	 * check of Dolibarr? A POS screen stays open for days, so its token (and sometimes
	 * its session) can become invalid while the tab keeps running with the token it was
	 * born with: every call to the server is then refused with HTTP 403 and, without this
	 * check, every touch of the cashier silently does nothing.
	 *
	 * @param  {Object} jqxhr	jQuery XHR of the failed call
	 * @returns {boolean} True when the token was refused
	 */
	isSecurityTokenFailure: function (jqxhr) {
		'use strict';

		if (!jqxhr || jqxhr.status != 403) {
			return false;
		}
		var text = (typeof jqxhr.responseText == 'string') ? jqxhr.responseText : '';
		return /CSRFCHECK|CSRF protection|Token not provided|token/i.test(text);
	},

	/**
	 * The security token of this screen was refused by the server. Reload the screen once
	 * to get a fresh token (orders captured offline are in IndexedDB, a reload keeps them),
	 * and only tell the cashier to reload manually if the fresh screen is refused too.
	 */
	handleSecurityTokenFailure: function () {
		'use strict';

		var now = Date.now();
		if (this._lastTokenReload && (now - this._lastTokenReload) < 30000) {
			return;
		}
		this._lastTokenReload = now;
		if (window.sessionStorage) {
			try {
				var last = parseInt(window.sessionStorage.getItem('fluxpossecurityreload'), 10) || 0;
				if (now - last < 30000) {
					return;	// The reload of 30 seconds ago did not fix it: never loop
				}
				window.sessionStorage.setItem('fluxpossecurityreload', String(now));
			} catch (error) {
				// Private mode or disabled storage: fall back on the throttled alert
			}
		}
		console.log('FluxPos offline: security token refused by the server, reloading the POS screen');
		window.location.reload();
	},

	/**
	 * The cashier tapped a product while offline on a place whose order already exists on
	 * the server: such an order cannot be modified from a terminal that is offline. Guide
	 * the cashier instead of doing nothing.
	 */
	notifyBlockedCapture: function () {
		'use strict';

		var now = Date.now();
		if (this._lastBlockedNotice && (now - this._lastBlockedNotice) < 10000) {
			return;
		}
		this._lastBlockedNotice = now;
		window.alert(this.t('FluxPosOfflineBlockedNotice', 'This place has an order registered on the server, which cannot be modified while the terminal is offline. Press New to start a new separate order on this terminal.'));
	},

	/**
	 * Switch to offline mode because the server could not be reached, and check it
	 * regularly until it answers again.
	 */
	markServerDown: function () {
		'use strict';

		var self = this;
		if (!this.serverDown) {
			console.log('FluxPos offline: the server cannot be reached, working offline');
		}
		this.serverDown = true;
		this.updateOfflineStatus();
		if (typeof jQuery('#offlinecart').val() != 'undefined') {
			// Show the local cart of the place at once when one is being captured,
			// instead of leaving the lines of the server order on the screen
			this.renderCart();
		}
		if (!this._probeTimer) {
			this._probeTimer = setInterval(function () {
				self.probe();
			}, this.probeInterval);
		}
	},

	/**
	 * The server answered again: leave offline mode, send the waiting sales and orders,
	 * then give the display back to the server.
	 */
	markServerUp: function () {
		'use strict';

		var self = this;
		if (!this.serverDown) {
			return;
		}
		console.log('FluxPos offline: the server can be reached again');
		this.serverDown = false;
		this._separatePlace = null;
		if (this._probeTimer) {
			clearInterval(this._probeTimer);
			this._probeTimer = null;
		}
		this.updateOfflineStatus();
		this.syncCatalog();
		this.syncOutbox().then(function () {
			if (jQuery('#offlinecart').val() != '1') {
				return;
			}
			self.getCart().then(function (cart) {
				if (cart && cart.separate === true) {
					// A separate local order is being captured on this place: keep it
					// displayed until it is paid (the order on the server is untouched)
					self.renderCart(true);
					return;
				}
				if (typeof Refresh == 'function') {
					Refresh();
				}
			});
		});
	},

	/**
	 * Light call to the server to know if it can be reached again.
	 */
	probe: function () {
		'use strict';

		var self = this;
		if (!navigator.onLine) {
			return;
		}
		jQuery.ajax(this.fluxposbaseurl + 'ajax/ajax.php?action=ping&token=' + this.fluxposnewtoken, {
			dataType: 'json',
			timeout: this.requestTimeout,
			cache: false,
			global: false
		}).then(function (data) {
			if (data && data.ok) {
				self.markServerUp();
			}
		}, function (jqxhr) {
			// Still down: next try at the next interval. A 403 is not "down": it says the
			// security token of this screen is not valid anymore, the screen must reload.
			if (self.isSecurityTokenFailure(jqxhr)) {
				self.handleSecurityTokenFailure();
			}
		});
	},

	/**
	 * Make a unique id for a sale. The server books a sale only once per id.
	 *
	 * @returns {string} Unique id
	 */
	newSaleUid: function () {
		'use strict';

		if (window.crypto && typeof window.crypto.randomUUID == 'function') {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array(16);
		if (window.crypto && window.crypto.getRandomValues) {
			window.crypto.getRandomValues(bytes);
		} else {
			for (var j = 0; j < 16; j++) {
				bytes[j] = Math.floor(Math.random() * 256);
			}
		}
		var hex = '';
		for (var i = 0; i < 16; i++) {
			hex += ('0' + bytes[i].toString(16)).slice(-2);
			if (i == 3 || i == 5 || i == 7 || i == 9) {
				hex += '-';
			}
		}
		return hex;
	},

	/**
	 * Turn the carts locked as "validated" by an older version of this file into sales,
	 * so they are booked through the exactly-once endpoint and their place is free again.
	 *
	 * @returns {Promise<bool>} Promise resolved when done
	 */
	migrateLegacyCarts: function () {
		'use strict';

		var self = this;
		return takePOSOfflineStoreKeys('cart:').then(function (keys) {
			var promise = Promise.resolve(true);
			jQuery.each(keys, function (index, key) {
				promise = promise.then(function () {
					return takePOSOfflineStoreGet(key).then(function (cart) {
						if (!cart || cart.validated !== true) {
							return true;
						}
						return self.storeSale(cart, cart.payments || [], key);
					});
				});
			});
			return promise;
		}).catch(function (error) {
			console.log('FluxPos offline: migration of the validated carts failed', error);
			return false;
		});
	},

	/**
	 * Store a paid cart as a sale waiting to be booked on the server, and remove the cart
	 * in the same transaction (a sale must never also stay as an open order).
	 *
	 * @param  {Object} cart     Cart (place, socid, lines)
	 * @param  {Array}  payments Payments captured: [{pay, amount, excess}]
	 * @param  {string} cartkey  Key of the cart to remove
	 * @returns {Promise<Object>} Promise of the stored sale
	 */
	storeSale: function (cart, payments, cartkey) {
		'use strict';

		var total = 0;
		for (var i = 0; i < cart.lines.length; i++) {
			total += cart.lines[i].price_ttc * cart.lines[i].qty;
		}
		var sale = {
			uid: this.newSaleUid(),
			place: String(cart.place),
			socid: cart.socid || 0,
			lines: cart.lines,
			payments: payments || [],
			total_ttc: Math.round(total * 100) / 100,
			created: Math.floor(Date.now() / 1000),
			error: ''
		};
		return takePOSOfflineStorePutAndDelete('sale:' + sale.uid, sale, cartkey).then(function () {
			return sale;
		});
	},

	/**
	 * Return a translated string passed by index.php (fluxposlang), or a fallback text.
	 *
	 * @param  {string} key      Key into fluxposlang
	 * @param  {string} fallback Text used when the key is not provided by index.php
	 * @returns {string} The translated text
	 */
	t: function (key, fallback) {
		'use strict';

		return (this.fluxposlang && this.fluxposlang[key]) ? this.fluxposlang[key] : fallback;
	},

	/**
	 * Escape a text to print it into HTML (label of a product for example).
	 *
	 * @param  {*} value Value to escape
	 * @returns {string} Escaped text
	 */
	escapeHtml: function (value) {
		'use strict';

		return jQuery('<div>').text((typeof value == 'undefined' || value == null) ? '' : String(value)).html();
	},

	/**
	 * Format an amount with the currency of the Dolibarr installation.
	 *
	 * @param  {number} amount Amount to format
	 * @returns {string} Formatted amount
	 */
	formatAmount: function (amount) {
		'use strict';

		try {
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: this.fluxposcurrency, minimumFractionDigits: 2 }).format(amount);
		} catch (error) {
			return (Math.round(amount * 100) / 100).toFixed(2) + ' ' + this.fluxposcurrency;
		}
	},

	/**
	 * Read the local cart of a place (an order captured on the terminal and not yet
	 * synchronized to the server).
	 *
	 * @param  {number|string} plc Place to read (current place when not provided)
	 * @returns {Promise<Object|null>} Promise of the cart, or null when there is no cart
	 */
	getCart: function (plc) {
		'use strict';

		if (typeof plc == 'undefined') {
			plc = (typeof place != 'undefined') ? place : 0;
		}
		return takePOSOfflineStoreGet('cart:' + plc).then(function (cart) {
			return (typeof cart == 'undefined') ? null : cart;
		}).catch(function () {
			return null;
		});
	},

	/**
	 * Save the local cart of a place. An empty cart is removed from the database.
	 * This also updates the pending orders indicator and schedules the background
	 * synchronization when the terminal is online.
	 *
	 * @param  {Object} cart Cart to save (with 'place' and 'lines' properties)
	 * @returns {Promise<bool>} Promise resolved when the cart has been saved
	 */
	saveCart: function (cart) {
		'use strict';

		var self = this;
		cart.updated = Math.floor(Date.now() / 1000);
		var promise = cart.lines.length ? takePOSOfflineStorePut('cart:' + cart.place, cart) : takePOSOfflineStoreDelete('cart:' + cart.place);
		return promise.then(function () {
			self.updateOfflineStatus();
			self.scheduleSync();
			return true;
		});
	},

	/**
	 * Add a product into the local cart of the current place.
	 * The product data (label, price) come from the catalog cached while online.
	 *
	 * @param  {number} idproduct ID of product
	 * @param  {number} qty       Quantity to add
	 * @returns {Promise<bool>} Promise resolved to false when the product is not into the cached catalog
	 */
	addProduct: function (idproduct, qty) {
		'use strict';

		var self = this;
		qty = (parseFloat(qty) > 0) ? parseFloat(qty) : 1;
		var socid = parseInt(jQuery('#thirdpartyid').val(), 10) || 0;
		this._lastSaleNotice = '';
		return this.getCart().then(function (cart) {
			if (cart == null) {
				cart = { place: String(place), socid: socid, created: Math.floor(Date.now() / 1000), lines: [], separate: (self._separatePlace === String(place)) };
			}
			if (cart.validated) {
				window.alert(self.t('FluxPosOfflineSaleLocked', 'This sale has been validated on this terminal and is waiting for its synchronization to the server. It cannot be modified anymore.'));
				return false;
			}
			return takePOSOfflineStoreGet('products:' + socid + ':__all__').then(function (allproducts) {
				var product = (allproducts && allproducts[idproduct]) ? allproducts[idproduct] : null;

				// The product may already be a line of the cart, including a line adopted
				// from the order of the place on the server (see captureOnServerOrder()):
				// only the quantity is raised then, even when the product is not in the
				// cached catalog (its price is on the line).
				var found = false;
				for (var i = 0; i < cart.lines.length; i++) {
					if (String(cart.lines[i].rowid) == String(idproduct)) {
						cart.lines[i].qty = Math.round((cart.lines[i].qty + qty) * 1000) / 1000;
						found = true;
						break;
					}
				}
				if (!found) {
					if (product == null) {
						window.alert(self.t('FluxPosOfflineNotInCachedCatalog', 'This product is not into the catalog cached on this terminal. It cannot be sold while the terminal is offline.'));
						return false;
					}
					cart.lines.push({ rowid: product.rowid, ref: product.ref, label: product.label, price_ttc: parseFloat(product.price_ttc) || 0, qty: qty });
				}
				return self.saveCart(cart).then(function () {
					return self.renderCart();
				});
			});
		});
	},

	/**
	 * Change the quantity of a line of the local cart of the current place.
	 * The line is removed when its quantity reaches zero.
	 *
	 * @param  {number} idproduct ID of product of the line
	 * @param  {number} delta     Quantity to add (can be negative)
	 * @returns {Promise<bool>} Promise resolved when done
	 */
	changeQty: function (idproduct, delta) {
		'use strict';

		var self = this;
		return this.getCart().then(function (cart) {
			if (cart == null) {
				return false;
			}
			if (cart.validated) {
				window.alert(self.t('FluxPosOfflineSaleLocked', 'This sale has been validated on this terminal and is waiting for its synchronization to the server. It cannot be modified anymore.'));
				return false;
			}
			for (var i = 0; i < cart.lines.length; i++) {
				if (String(cart.lines[i].rowid) == String(idproduct)) {
					if (cart.lines[i].adopted === true && parseFloat(delta) < 0) {
						// A line of the order of the place on the server can only be raised
						// while the terminal is offline: the merge into the server order
						// raises a quantity to the captured one, never lowers it.
						window.alert(self.t('FluxPosOfflineAdoptedLineRaiseOnly', 'A line of the order of this place on the server can only be raised while the terminal is offline. The quantity is changed on the server when the connection is back.'));
						return false;
					}
					cart.lines[i].qty = Math.round((cart.lines[i].qty + parseFloat(delta)) * 1000) / 1000;
					if (cart.lines[i].qty <= 0) {
						cart.lines.splice(i, 1);
					}
					return self.saveCart(cart).then(function () {
						return self.renderCart();
					});
				}
			}
			return false;
		});
	},

	/**
	 * Pay the local cart of the current place with the payments captured on the terminal
	 * (same intent as invoice.php action=valid). An amount of 0 means "the remain to pay".
	 * The cart becomes a sale with its own unique id, stored on the terminal until the
	 * server has booked it (invoice number and payments, exactly once), and the cart of
	 * the place is emptied at once so the next customer can be served.
	 *
	 * @param  {Array} payments Payments captured: [{pay: 'LIQ'|'CB'|'CHQ'|..., amount, excess}]
	 * @returns {Promise<bool>} Promise of true when the sale has been stored
	 */
	validateCart: function (payments) {
		'use strict';

		var self = this;
		// Wait for a synchronization in progress: if it sends this order to the server, the
		// order must be paid there, not turned into a second, offline sale
		return (this._syncPromise || Promise.resolve()).then(function () {
			return self.getCart();
		}).then(function (cart) {
			if (cart == null || !cart.lines.length) {
				if (!self.isOffline() && typeof Refresh == 'function') {
					window.alert(self.t('FluxPosOfflineOrderJustSent', 'This order has just been sent to the server. Pay it again on the screen.'));
					Refresh();
				} else {
					window.alert(self.t('FluxPosOfflineNoOrderCaptured', 'There is no order captured on this place.'));
				}
				return false;
			}
			if (cart.adopted === true && cart.separate !== true) {
				// The captured products are merged into the draft invoice which already
				// exists on the server for this place: that order is paid on the server
				// (only an order started offline can be paid whole from this terminal).
				window.alert(self.t('FluxPosOfflineAdoptedValidate', 'This place has an order registered on the server. The products captured while offline are synchronized to it as soon as the connection is back, then it is paid there.'));
				return false;
			}
			return self.storeSale(cart, payments || [], 'cart:' + cart.place).then(function (sale) {
				self._lastSaleNotice = self.t('FluxPosOfflineSaleStored', 'Sale of %s stored on this terminal. Its invoice number is given by the server when the connection is back.').replace('%s', self.formatAmount(sale.total_ttc));
			}).then(function () {
				self.updateOfflineStatus();
				self.scheduleSync();
				return self.renderCart();
			});
		});
	},

	/**
	 * Discard the local cart of a place (nothing was sent to the server yet).
	 *
	 * @param  {number|string} plc Place to clear (current place when not provided)
	 * @returns {Promise<bool>} Promise resolved when done
	 */
	clearCart: function (plc) {
		'use strict';

		var self = this;
		if (typeof plc == 'undefined') {
			plc = (typeof place != 'undefined') ? place : 0;
		}
		return takePOSOfflineStoreDelete('cart:' + plc).then(function () {
			self.updateOfflineStatus();
			return self.renderCart();
		});
	},

	/**
	 * Keep a snapshot of the order currently displayed for a place (the draft invoice
	 * registered on the server), taken while the connection is up. It is the baseline
	 * used when the terminal has to capture on a place with a server order: the first
	 * product tap adopts the order into the local cart (see captureOnServerOrder()),
	 * so the cashier can keep capturing and the merge into the server order is exact.
	 * Called after every load of the order fragment from the server (index.php Refresh
	 * and addline) and when the POS screen is opened.
	 *
	 * @returns {Promise<bool>} Promise of true when the order is cached
	 */
	cacheServerOrder: function () {
		'use strict';

		var invoiceid = parseInt(jQuery('#invoiceid').val(), 10) || 0;
		var plc = (typeof place != 'undefined') ? place : 0;
		if (invoiceid <= 0 || !window.indexedDB) {
			// No order on the server for this place: its snapshot is not needed anymore
			return takePOSOfflineStoreDelete('order:' + plc).then(function () {
				return true;
			}).catch(function () {
				return false;
			});
		}
		var self = this;
		var url = this.fluxposbaseurl + 'ajax/ajax.php?action=getInvoice&token=' + this.fluxposnewtoken + '&id=' + invoiceid;
		return jQuery.ajax(url, { dataType: 'json', timeout: this.requestTimeout, global: false }).then(function (data) {
			if (!data || !data.lines || !jQuery.isArray(data.lines)) {
				return false;
			}
			var order = {
				invoiceid: invoiceid,
				socid: parseInt(data.socid, 10) || 0,
				created: Math.floor(Date.now() / 1000),
				lines: []
			};
			for (var i = 0; i < data.lines.length; i++) {
				var srcline = data.lines[i];
				var lineid = parseInt(srcline.id || srcline.rowid, 10) || 0;
				var fwproduct = parseInt(srcline.fk_product, 10) || 0;
				var lineqty = parseFloat(srcline.qty) || 0;
				if (lineid <= 0 || fwproduct <= 0 || parseInt(srcline.fk_parent_line, 10) > 0 || parseInt(srcline.special_code, 10) == 4) {
					continue;	// Free lines, sub-lines and discounts are not merged by product (same rule as the server merge)
				}
				order.lines.push({
					rowid: fwproduct,
					ref: srcline.product_ref || '',
					label: srcline.label || srcline.product_label || srcline.product_ref || ('Product ' + fwproduct),
					price_ttc: (lineqty > 0) ? Math.round((parseFloat(srcline.total_ttc) / lineqty) * 1000000) / 1000000 : 0,
					qty: lineqty,
					lineid: lineid
				});
			}
			return takePOSOfflineStorePut('order:' + plc, order).then(function () {
				console.log('FluxPos offline: order ' + invoiceid + ' of place ' + plc + ' cached for offline use (' + order.lines.length + ' product lines)');
				return true;
			});
		}, function (jqxhr, textStatus) {
			self.handleFailure(jqxhr, textStatus);
			return false;
		}).catch(function (error) {
			console.log('FluxPos offline: caching of the order of place ' + plc + ' failed', error);
			return false;
		});
	},

	/**
	 * Capture a product tap while offline on a place whose order exists on the server.
	 * On the first tap, the order of the place is adopted into the local cart from the
	 * last snapshot cached while online (see cacheServerOrder()): its lines are shown
	 * again and the new products can be added; the background synchronization merges
	 * the cart into the draft invoice of the place (same endpoint and rules as for an
	 * order started offline: quantities raised to the captured ones, never doubled).
	 * When no snapshot is cached (the order has never been displayed on this screen),
	 * the cashier is guided to "New" as before: only the lines can then be merged into
	 * the order of the place, not the price of the lines already on it.
	 *
	 * @param  {number} idproduct ID of product
	 * @param  {number} qty       Quantity to add
	 * @returns {Promise<bool>} Promise of true when the product is added to the cart
	 */
	captureOnServerOrder: function (idproduct, qty) {
		'use strict';

		var self = this;
		return this.getCart().then(function (cart) {
			if (cart != null) {
				if (cart.separate === true) {
					// The cashier is capturing a separate order next to the order of the
					// place: never merge both.
					self.notifyBlockedCapture();
					return false;
				}
				return self.addProduct(idproduct, qty);
			}
			var plc = (typeof place != 'undefined') ? place : 0;
			return takePOSOfflineStoreGet('order:' + plc).then(function (order) {
				if (!order || !order.invoiceid) {
					self.notifyBlockedCapture();
					return false;
				}
				var adopted = {
					place: String(plc),
					socid: parseInt(order.socid, 10) || 0,
					created: Math.floor(Date.now() / 1000),
					invoiceid: parseInt(order.invoiceid, 10) || 0,
					adopted: true,
					lines: []
				};
				for (var i = 0; i < order.lines.length; i++) {
					adopted.lines.push({
						rowid: order.lines[i].rowid,
						ref: order.lines[i].ref,
						label: order.lines[i].label,
						price_ttc: parseFloat(order.lines[i].price_ttc) || 0,
						qty: parseFloat(order.lines[i].qty) || 0,
						lineid: parseInt(order.lines[i].lineid, 10) || 0,
						adopted: true
					});
				}
				return self.saveCart(adopted).then(function () {
					// Show the adopted order (its lines, then the added product) at once:
					// the screen still displays the fragment of the server order, which must
					// be replaced even though #invoiceid still holds its id
					return self.renderCart(true);
				}).then(function () {
					return self.addProduct(idproduct, qty);
				});
			});
		});
	},

	/**
	 * Render the local cart of the current place into #poslines, with the same markup
	 * as the #poslines fragment of invoice.php (same hidden fields, same line classes),
	 * so the POS interface keeps working exactly like when the order is on the server.
	 * An order already stored on the server is never replaced: it can only be rendered
	 * by invoice.php once the connection is back.
	 *
	 * @param  {boolean} force Show the local cart even when an order stored on the server is displayed
	 * @returns {Promise<bool>} Promise of true when the cart has been rendered
	 */
	renderCart: function (force) {
		'use strict';

		var self = this;
		if (typeof jQuery == 'undefined' || typeof place == 'undefined') {
			return Promise.resolve(false);
		}
		var offlinecartdisplayed = (force === true) || jQuery('#offlinecart').val() == '1';
		if (!this.isOffline() && !offlinecartdisplayed) {
			return Promise.resolve(false);
		}
		if ((jQuery('#invoiceid').val() || '') != '' && !offlinecartdisplayed) {
			// An order stored on the server is displayed: never hide it with the local cart
			return Promise.resolve(false);
		}
		return this.getCart().then(function (cart) {
			var lines = (cart && cart.lines) ? cart.lines : [];
			var payments = (cart && cart.payments) ? cart.payments : [];
			var validated = (cart != null && cart.validated === true);
			var html = '';
			var i, line, posnochange, total = 0;

			for (i = 0; i < lines.length; i++) {
				total += lines[i].price_ttc * lines[i].qty;
			}

			// Same hidden fields as the #poslines fragment of invoice.php
			html += '<input type="hidden" name="invoiceid" id="invoiceid" value="">';
			html += '<input type="hidden" name="thirdpartyid" id="thirdpartyid" value="' + ((cart && cart.socid) ? parseInt(cart.socid, 10) : 0) + '">';
			html += '<input type="hidden" name="offlinecart" id="offlinecart" value="1">';
			html += '<table class="fluxposofflinecart">';
			html += '<tr class="liste_titre nodrag nodrop"><td class="linecoldescription"></td><td class="linecolqty right">' + self.escapeHtml(self.t('Qty', 'Qty')) + '</td><td class="linecolht right"><span class="opacitymedium small">' + self.escapeHtml(self.t('TotalTTCShort', 'Total')) + '</span></td></tr>';
			if (!lines.length) {
				html += '<tr class="drag drop oddeven"><td class="left"><span class="opacitymedium">' + self.escapeHtml(self.t('Empty', 'Empty')) + '</span></td><td></td><td></td></tr>';
			}
			for (i = lines.length - 1; i >= 0; i--) {
				line = lines[i];
				html += '<tr class="drag drop oddeven posinvoiceline fluxposofflinecartline">';
				html += '<td class="left">';
				// Same thumbnail as the server cart lines (line.rowid holds the product id,
				// also for the lines adopted from the order of the place on the server)
				if (line.rowid > 0 && !window.fluxposhideproductimages) {
					html += '<img class="cartthumb" loading="lazy" alt="" src="' + self.fluxposbaseurl + 'genimg/index.php?query=pro&id=' + parseInt(line.rowid, 10) + '">';
				}
				html += '<span class="fluxposofflinelabel">' + self.escapeHtml(line.label) + '</span></td>';
				html += '<td class="right nowraponall fluxposofflineqty">';
				if (!validated) {
					html += '<button type="button" class="fluxposofflineqtybtn" onclick="FluxPosOffline.changeQty(' + line.rowid + ', -1);">-</button>';
				}
				html += '<span class="fluxposofflineqtyval">' + self.escapeHtml(line.qty) + '</span>';
				if (!validated) {
					html += '<button type="button" class="fluxposofflineqtybtn" onclick="FluxPosOffline.changeQty(' + line.rowid + ', 1);">+</button>';
				}
				html += '</td>';
				html += '<td class="right fluxposofflinetotal">' + self.formatAmount(line.price_ttc * line.qty) + '</td>';
				html += '</tr>';
			}
			html += '<tr class="liste_total"><td colspan="2" class="right">' + self.escapeHtml(self.t('TotalTTCShort', 'Total')) + '</td><td class="right">' + self.formatAmount(total) + '</td></tr>';
			html += '</table>';

			// Payments validated on the terminal
			if (validated) {
				var paid = 0;
				html += '<div class="fluxposofflinepayments">';
				for (i = 0; i < payments.length; i++) {
					posnochange = (payments[i].pay == 'LIQ' && payments[i].excess) ? ' - ' + self.escapeHtml(self.t('Change', 'Change')) + ' ' + self.formatAmount(payments[i].excess) : '';
					html += '<div class="fluxposofflinepayment">' + self.escapeHtml(self.t('PaymentTypeShort' + payments[i].pay, payments[i].pay)) + ' : <span class="fluxposofflinepaymentamount">' + self.formatAmount(payments[i].amount) + posnochange + '</span></div>';
					paid += parseFloat(payments[i].amount) || 0;
				}
				html += '</div>';
				if (Math.round(total * 100) / 100 > Math.round(paid * 100) / 100) {
					html += '<div class="fluxposofflinepayments remaining">' + self.escapeHtml(self.t('RemainToPay', 'Remain to pay')) + ' : <span class="fluxposofflinepaymentamount">' + self.formatAmount(total - paid) + '</span></div>';
				}
			}

			if (validated) {
				html += '<div class="fluxposofflinenotice">' + self.escapeHtml(self.t('FluxPosOfflineSaleStoredNotice', 'This sale is validated. It has been safely stored with its payments: its invoice number and its receipt will be issued by the server as soon as the synchronization can be done.')) + '</div>';
			} else if (lines.length) {
				if (cart && cart.adopted === true && cart.separate !== true) {
					html += '<div class="fluxposofflinenotice">' + self.escapeHtml(self.t('FluxPosOfflineAdoptedNotice', 'This order is captured on this terminal, its lines included. It is merged into the order of this place on the server as soon as the connection is back (only the quantities of its own lines can be raised while offline).')) + '</div>';
				} else {
					html += '<div class="fluxposofflinenotice">' + self.escapeHtml(!self.isOffline() ? self.t('FluxPosOfflineOrderSynced', 'This order is synchronized to the server in background.') : self.t('FluxPosOfflineOrderStoredOffline', 'This order is stored on this terminal and will be synchronized to the server when the connection is back.')) + '</div>';
				}
			} else if (self._lastSaleNotice) {
				html += '<div class="fluxposofflinenotice">' + self.escapeHtml(self._lastSaleNotice) + '</div>';
			} else {
				html += '<div class="fluxposofflinenotice opacitymedium">' + self.escapeHtml(self.t('FluxPosOfflineEmptyCartNotice', 'Offline mode: a new order can be captured, it will be synchronized to the server when the connection is back. An order already registered on the server is shown again when the connection is back.')) + '</div>';
			}
			jQuery('#poslines').html(html);
			// The sticky total of the cart footer of the 1.1 layout follows the local cart
			if (typeof fluxposUpdateCartTotal == 'function') {
				fluxposUpdateCartTotal(self.formatAmount(total));
			}
			return true;
		});
	},

	/**
	 * Count what waits on this terminal: orders not paid yet (carts), paid sales not
	 * booked yet, and among those the sales the server refused (with an error).
	 *
	 * @returns {Promise<Object>} Promise of {orders, sales, failed}
	 */
	countPending: function () {
		'use strict';

		var res = { orders: 0, sales: 0, failed: 0 };
		return takePOSOfflineStoreKeys('cart:').then(function (keys) {
			res.orders = keys.length;
			return takePOSOfflineStoreKeys('sale:');
		}).then(function (keys) {
			res.sales = keys.length;
			return Promise.all(keys.map(function (key) {
				return takePOSOfflineStoreGet(key);
			}));
		}).then(function (sales) {
			for (var i = 0; i < sales.length; i++) {
				if (sales[i] && sales[i].error) {
					res.failed++;
				}
			}
			return res;
		}).catch(function () {
			return res;
		});
	},

	/**
	 * Count the local orders and sales not yet synchronized to the server.
	 *
	 * @returns {Promise<number>} Promise of the number of pending orders and sales
	 */
	countPendingOrders: function () {
		'use strict';

		return this.countPending().then(function (res) {
			return res.orders + res.sales;
		});
	},

	/**
	 * Register the service worker that precaches the application shell.
	 */
	registerServiceWorker: function () {
		'use strict';

		if (!('serviceWorker' in navigator)) {
			console.log('FluxPos offline: service workers are not supported');
			return;
		}
		window.addEventListener('load', function () {
			navigator.serviceWorker.register(FluxPosOffline.fluxposbaseurl + 'serviceworker.js').catch(function (error) {
				console.log('FluxPos offline: service worker registration failed', error);
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
		var url = this.fluxposbaseurl + 'ajax/ajax.php?action=getCatalog&token=' + this.fluxposnewtoken + '&thirdpartyid=' + socid;

		return jQuery.ajax(url, { dataType: 'json', timeout: this.requestTimeout, global: false }).then(function (data) {
			var puts = [];
			var allproducts = {};
			self.markServerUp();

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
				console.log('FluxPos offline: catalog synchronized for offline use');
				return true;
			});
		}, function (jqxhr, textStatus) {
			self.handleFailure(jqxhr, textStatus);
			console.log('FluxPos offline: catalog synchronization failed', textStatus);
			return false;
		}).catch(function (error) {
			console.log('FluxPos offline: catalog synchronization failed', error);
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

		// A call that hangs (Wi-Fi up, server unreachable) must end in an error, so the
		// terminal can switch to offline mode instead of waiting forever. While the server
		// is known to be down, calls give up quickly and the terminal falls back at once.
		// Only the read-only calls get this default (the catalog calls of ajax.php and the
		// fragment loads of invoice.php without an action): a payment or a validation
		// (invoice.php with an action) may be slow and must never be cut off by the tablet.
		jQuery.ajaxPrefilter(function (options, originalOptions) {
			var url = String(options.url);
			if (typeof originalOptions.timeout != 'undefined' || !self.isDolibarrUrl(url)) {
				return;
			}
			if (url.indexOf('/ajax/ajax.php') >= 0) {
				options.timeout = self.serverDown ? 3000 : 30000;
			} else if ((options.type || 'GET') == 'GET' && /(^|\/)invoice\.php(\?|$)/.test(url) && url.indexOf('action=') < 0) {
				// Read-only load of an order fragment (Refresh): it must end in an error
				// quickly so the terminal falls back on the data of the terminal instead of
				// waiting forever. The write loads of invoice.php (action=addline, valid,
				// delete) are never cut off by the terminal.
				options.timeout = self.serverDown ? 3000 : 30000;
			}
		});

		// Any call of the page to the Dolibarr server tells whether it can be reached
		// (calls to another host, like a local print server, say nothing about it)
		jQuery(document).ajaxSuccess(function (event, jqxhr, settings) {
			if (self.isDolibarrUrl(settings.url)) {
				self.markServerUp();
			}
		});
		jQuery(document).ajaxError(function (event, jqxhr, settings, thrownError) {
			if (!self.isDolibarrUrl(settings.url)) {
				return;
			}
			if (self.isSecurityTokenFailure(jqxhr)) {
				// The page has been open too long: Dolibarr refused its token on every
				// call. Reload once so the terminal gets a fresh token by itself.
				self.handleSecurityTokenFailure();
				return;
			}
			var textStatus = (thrownError === 'timeout' || thrownError === 'abort') ? thrownError : '';
			if (self.isNetworkFailure(jqxhr, textStatus)) {
				self.markServerDown();
			}
		});

		window.addEventListener('online', function () {
			self.updateOfflineStatus();
			self.syncCatalog();
			// Replay the orders captured while offline, then give back the display to the server
			self.syncOutbox().then(function () {
				if (jQuery('#offlinecart').val() == '1' && typeof Refresh == 'function') {
					Refresh();
				}
			});
		});
		window.addEventListener('offline', function () {
			self.updateOfflineStatus();
			if (jQuery('#offlinecart').val() == '1') {
				self.renderCart();	// Refresh the notice of the local cart currently displayed
			}
		});

		// Retry what is still waiting (a sale refused by the server, a failed sync)
		setInterval(function () {
			if (!self.isOffline()) {
				self.syncOutbox();
			}
		}, 60000);

		// Keep a snapshot of the order of the place while the connection is up (baseline
		// for the capture of new lines while offline on a place with a server order)
		this.cacheServerOrder();
		this.migrateLegacyCarts().then(function () {
			self.updateOfflineStatus();
		});
	},

	/**
	 * Plan the background synchronization of the local orders some seconds after the
	 * last modification: this lets the cashier continue to enter products without
	 * being interrupted by the synchronization.
	 */
	scheduleSync: function () {
		'use strict';

		var self = this;
		if (this.isOffline()) {	// Nothing to send while the browser is offline or the server is unreachable
			return;
		}
		if (this._syncTimer) {
			clearTimeout(this._syncTimer);
		}
		this._syncTimer = setTimeout(function () {
			self._syncTimer = null;
			self.syncOutbox();
		}, 4000);
	},

	/**
	 * Send the paid sales stored on this terminal to the server, oldest first. Each sale
	 * is booked exactly once by the syncOfflineSale endpoint (unique id), so a sale can
	 * be sent again safely. A sale refused by the server keeps its error and stays on the
	 * terminal, the next sales are still sent. When the server cannot be reached, the
	 * sending stops and is retried later.
	 *
	 * @returns {Promise<bool>} Promise of false when the server could not be reached
	 */
	syncSales: function () {
		'use strict';

		var self = this;
		var url = this.fluxposbaseurl + 'ajax/ajax.php?action=syncOfflineSale&token=' + this.fluxposnewtoken;

		return takePOSOfflineStoreKeys('sale:').then(function (keys) {
			return Promise.all(keys.map(function (key) {
				return takePOSOfflineStoreGet(key);
			}));
		}).then(function (sales) {
			sales = sales.filter(function (sale) {
				return sale && sale.uid;
			}).sort(function (a, b) {
				return a.created - b.created;
			});

			var promise = Promise.resolve(true);
			jQuery.each(sales, function (index, sale) {
				promise = promise.then(function (ok) {
					if (!ok) {
						return false;
					}
					var lines = [];
					for (var i = 0; i < sale.lines.length; i++) {
						if (sale.lines[i].qty > 0) {
							lines.push({ id: sale.lines[i].rowid, qty: sale.lines[i].qty });
						}
					}
					return jQuery.ajax(url, {
						method: 'POST',
						contentType: 'application/json; charset=utf-8',
						data: JSON.stringify({
							uid: sale.uid,
							place: sale.place,
							thirdpartyid: sale.socid,
							lines: lines,
							payments: sale.payments,
							created: sale.created
						}),
						dataType: 'json',
						timeout: self.requestTimeout,
						global: false
					}).then(function (data) {
						self.markServerUp();
						if (!data || data.error) {
							// Refused by the server: keep the sale with its error and go on with the next one
							sale.error = (data && data.error) ? String(data.error) : self.t('FluxPosOfflineUnexpectedAnswer', 'Unexpected answer of the server');
							console.log('FluxPos offline: sale ' + sale.uid + ' refused by the server: ' + sale.error);
							return takePOSOfflineStorePut('sale:' + sale.uid, sale).then(function () {
								return true;
							});
						}
						console.log('FluxPos offline: sale ' + sale.uid + ' booked as invoice ' + data.ref + (data.duplicate ? ' (already booked before)' : ''));
						return takePOSOfflineStoreDelete('sale:' + sale.uid).then(function () {
							return true;
						});
					}, function (jqxhr, textStatus) {
						// Not reached, or no usable answer (session expired for example): try again later
						self.handleFailure(jqxhr, textStatus);
						console.log('FluxPos offline: sale ' + sale.uid + ' not sent (' + textStatus + '), will retry');
						return false;
					});
				});
			});
			return promise;
		});
	},

	/**
	 * Synchronize what was captured on this terminal: first the paid sales (see
	 * syncSales()), then the orders not paid yet, one place after the other: each
	 * place is sent to the idempotent syncOfflineOrder endpoint which creates or
	 * completes the draft invoice of the place. The cart of a place is removed as soon
	 * as its synchronization succeeds. A failure stops the replay: it is retried on the
	 * next trigger (new change, back online, page load, every minute).
	 *
	 * @returns {Promise<bool>} Promise of true when all the pending orders are synchronized
	 */
	syncOutbox: function () {
		'use strict';

		var self = this;
		if (this._syncing || this.isOffline() || !window.indexedDB) {
			return Promise.resolve(false);
		}
		this._syncing = true;
		var currentplace = (typeof place != 'undefined') ? String(place) : null;
		this._syncPromise = this.syncSales().then(function (salesok) {
			if (!salesok) {
				return false;	// Server not reached: keep the orders for later too
			}
			return takePOSOfflineStoreKeys('cart:');
		}).then(function (keys) {
			if (keys === false) {
				return false;
			}
			var promise = Promise.resolve(true);
			jQuery.each(keys, function (index, key) {
				promise = promise.then(function (ok) {
					if (!ok) {
						return false;
					}
					var plc = String(key.substring('cart:'.length));
					return takePOSOfflineStoreGet(key).then(function (cart) {
						if (cart == null || !cart.lines || !cart.lines.length) {
							// Discard an empty cart (nothing was captured)
							return takePOSOfflineStoreDelete(key).then(function () {
								return true;
							});
						}
						if (cart.separate === true) {
							// New order started offline while the place had an order on the server:
							// sending it would merge it into that other order. It stays on the
							// terminal until it is paid (then it is a sale) or discarded.
							return true;
						}
						var lines = [];
						for (var i = 0; i < cart.lines.length; i++) {
							if (cart.lines[i].qty > 0) {
								lines.push({ id: cart.lines[i].rowid, qty: cart.lines[i].qty });
							}
						}
						var url = self.fluxposbaseurl + 'ajax/ajax.php?action=syncOfflineOrder&token=' + self.fluxposnewtoken;
						return jQuery.ajax(url, {
							method: 'POST',
							contentType: 'application/json; charset=utf-8',
							data: JSON.stringify({
								place: cart.place,
								thirdpartyid: cart.socid,
								lines: lines
							}),
							dataType: 'json',
							timeout: 0,		// No timeout: giving up while the server still adds the lines would leave them on the server AND on the terminal
							global: false
						}).then(function (data) {
							self.markServerUp();
							if (data && data.error) {
								console.log('FluxPos offline: synchronization of the order of place ' + cart.place + ' failed: ' + data.error);
								return false;
							}
							console.log('FluxPos offline: order of place ' + cart.place + ' synchronized to the server');
							return takePOSOfflineStoreDelete(key).then(function () {
								if (plc === currentplace && typeof Refresh == 'function') {
									// Reload the order of the current place from the server
									Refresh();
								}
								return true;
							});
						}, function (jqxhr, textStatus) {
							self.handleFailure(jqxhr, textStatus);
							return false;
						});
					});
				});
			});
			return promise;
		}).catch(function (error) {
			console.log('FluxPos offline: background order synchronization failed', error);
			return false;
		}).then(function (result) {
			self._syncing = false;
			self._syncPromise = null;
			if (!self._syncTimer) {
				self.updateOfflineStatus();
			}
			return result;
		});
		return this._syncPromise;
	},

	/**
	 * Show or hide the offline status pill: offline or not, the number of sales and
	 * orders waiting for synchronization and the sales refused by the server (a click
	 * retries the synchronization).
	 */
	updateOfflineStatus: function () {
		'use strict';

		var element = document.getElementById('fluxposofflinestatus');
		var self = this;
		if (!element) {
			return;
		}
		this.countPending().then(function (pending) {
			var parts = [];
			if (pending.sales > 0) {
				parts.push(self.t('FluxPosOfflineStatusSalesWaiting', '%s sale(s) waiting').replace('%s', pending.sales));
			}
			if (pending.failed > 0) {
				parts.push(self.t('FluxPosOfflineStatusRefused', '%s refused by the server, see the browser console').replace('%s', pending.failed));
			}
			if (pending.orders > 0) {
				parts.push(self.t('FluxPosOfflineStatusOrdersWaiting', '%s open order(s) waiting').replace('%s', pending.orders));
			}
			element.classList.remove('offline', 'pending');
			element.onclick = null;
			if (self.isOffline()) {
				element.classList.add('offline');
				element.textContent = self.t('FluxPosOfflineStatusOffline', 'Offline') + (parts.length ? ' - ' + parts.join(', ') : ' ' + self.t('FluxPosOfflineStatusCatalogOnly', '- catalog only'));
			} else if (parts.length) {
				element.classList.add('pending');
				element.textContent = parts.join(', ') + self.t('FluxPosOfflineStatusClickRetry', ' - click to retry');
				element.onclick = function () {
					self.syncOutbox();
				};
			} else {
				element.textContent = '';
			}
		});
	}
};
