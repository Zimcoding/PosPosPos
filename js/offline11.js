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
 *	\brief      Ticket engine of FluxPos: catalog cache into IndexedDB, shared open
 *				tickets synchronized both ways by operations, offline sales.
 *
 *				Catalog: categories, products, prices and the deposit data of Deposito
 *				are cached in IndexedDB while online and used when offline.
 *
 *				Open tickets (1.8): the ticket of a place lives on the server (its draft
 *				invoice) and can be handled from several devices. Every change made on a
 *				terminal is an operation with a unique id (add, delta, set, remove), kept
 *				in the outbox of the ticket ('ticket:<place>') and applied once by the
 *				applyOps endpoint, which answers the full ticket. The screen shows the last
 *				state of the server with the operations not confirmed yet on top of it.
 *				Other devices read the ticket every few seconds (getTicket, cheap while
 *				nothing changed). Offline, the operations wait in the outbox.
 *
 *				Offline payment: the ticket becomes a SALE with its own unique id (stored as
 *				'sale:<uid>'), booked exactly once by the syncOfflineSale endpoint, which also
 *				takes the sold lines off the ticket on the server (what another device added
 *				meanwhile stays open). The ticket is emptied at once on the terminal.
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

/**
 * Read, change and write back one entry in ONE read-write transaction: a change made
 * meanwhile by another call can never be overwritten (IndexedDB runs the read-write
 * transactions of a store one after the other).
 *
 * @param  {string}   key Key of the entry
 * @param  {Function} fn  Synchronous function(value) returning the new value (null or undefined removes the entry)
 * @returns {Promise<*>} Promise of the new value
 */
function takePOSOfflineStoreUpdate(key, fn) {
	'use strict';

	return openFluxPosOfflineDB().then(function (db) {
		return new Promise(function (resolve, reject) {
			var transaction = db.transaction('catalog', 'readwrite');
			var store = transaction.objectStore('catalog');
			var result;
			var request = store.get(key);
			request.onsuccess = function () {
				try {
					result = fn(request.result);
				} catch (error) {
					transaction.abort();
					reject(error);
					return;
				}
				if (result === null || typeof result == 'undefined') {
					store['delete'](key);
				} else {
					store.put(result, key);
				}
			};
			transaction.oncomplete = function () {
				db.close();
				resolve(result);
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
	_lastTokenReload: 0,		// timestamp of the last automatic reload after a refused token
	_lastBlockedNotice: 0,		// timestamp of the last "order locked on the server" notice

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
		window.alert(this.t('FluxPosOfflineBlockedNotice', 'This place has an order registered on the server, which cannot be modified while the terminal is offline and could not be captured on this terminal. Its lines can be edited again as soon as the connection is back.'));
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
	 * The server answered again: leave offline mode, send what waits (sales and the
	 * operations of the open tickets), then show the ticket of the place as the server
	 * holds it.
	 */
	markServerUp: function () {
		'use strict';

		var self = this;
		if (!this.serverDown) {
			return;
		}
		console.log('FluxPos offline: the server can be reached again');
		this.serverDown = false;
		if (this._probeTimer) {
			clearInterval(this._probeTimer);
			this._probeTimer = null;
		}
		this.updateOfflineStatus();
		this.syncCatalog();
		this.syncOutbox().then(function () {
			return self.refreshTicket(true);
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
	 * Make a unique id (for a sale or an operation). The server applies each id only once.
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
	 * Parse a formatted amount as displayed on a screen (e.g. "1 234,56 €" or
	 * "$1,234.56") into a number. When both separators appear, the last one is the
	 * decimal separator, so the European and the US formatting both parse.
	 *
	 * @param  {string} text Formatted amount as displayed
	 * @returns {number} The amount (0 when nothing can be parsed)
	 */
	parseAmountText: function (text) {
		'use strict';

		var num = String(text == null ? '' : text).replace(/[^0-9,.\-]/g, '');
		if (num.indexOf(',') >= 0 && num.indexOf('.') >= 0) {
			if (num.lastIndexOf(',') > num.lastIndexOf('.')) {
				num = num.replace(/\./g, '').replace(/,/g, '.');
			} else {
				num = num.replace(/,/g, '');
			}
		} else if (num.indexOf(',') >= 0) {
			num = num.replace(/,/g, '.');
		}
		return parseFloat(num) || 0;
	},

	/*
	 * ---------------------------------------------------------------------------------
	 * Open tickets (1.8): two-way synchronization by operations
	 *
	 * The open ticket of a place lives on the server (the draft invoice of the place) and
	 * can be handled from any device using the same terminal number. A terminal keeps,
	 * per place, in IndexedDB under 'ticket:<place>':
	 *   { place, socid, server: <last state answered by the server>, seq, ops: [...] }
	 * Every change made on the terminal is an OPERATION with its own unique id:
	 *   add {product, qty} - delta {lineid|product, qty} - set {lineid|product, qty} - remove {lineid|product}
	 * Operations are changes, never copies of the cart, so the changes of several devices
	 * on one ticket add up. The server applies each operation once (unique id) and answers
	 * the full ticket. What the screen shows is always:
	 *   last server state + the operations not confirmed yet, applied on top of it.
	 * A tap made while a request is on its way is still in the outbox and is applied
	 * again on top of the answer: nothing is overwritten, nothing is lost. The deposit
	 * lines of Deposito are written by the server; the terminal only predicts them for
	 * the operations not confirmed yet (deposit data of the catalog).
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Current place of the screen.
	 *
	 * @returns {string} Place
	 */
	currentPlace: function () {
		'use strict';

		return (typeof place != 'undefined' && place !== null) ? String(place) : '0';
	},

	/**
	 * Read the ticket of a place stored on this terminal.
	 *
	 * @param  {string} plc Place (current place when not provided)
	 * @returns {Promise<Object>} Promise of the ticket (an empty ticket when none is stored)
	 */
	getTicket: function (plc) {
		'use strict';

		var self = this;
		plc = (typeof plc == 'undefined') ? this.currentPlace() : String(plc);
		return takePOSOfflineStoreGet('ticket:' + plc).then(function (ticket) {
			return ticket || self.emptyTicket(plc);
		}).catch(function () {
			return self.emptyTicket(plc);
		});
	},

	/**
	 * An empty ticket of a place.
	 *
	 * @param  {string} plc Place
	 * @returns {Object} Empty ticket
	 */
	emptyTicket: function (plc) {
		'use strict';

		return { place: String(plc), socid: 0, server: null, seq: 0, ops: [] };
	},

	/**
	 * Change the stored ticket of a place in ONE read-write transaction (no change made
	 * in between can be overwritten). A ticket without server state and without
	 * operations is removed.
	 *
	 * @param  {string}   plc Place
	 * @param  {Function} fn  Synchronous function(ticket) changing the ticket in place
	 * @returns {Promise<Object>} Promise of the stored ticket
	 */
	updateTicket: function (plc, fn) {
		'use strict';

		var self = this;
		plc = String(plc);
		return takePOSOfflineStoreUpdate('ticket:' + plc, function (stored) {
			var ticket = stored || self.emptyTicket(plc);
			fn(ticket, !!stored);
			var serverempty = !ticket.server || !ticket.server.placeid;
			return (serverempty && !ticket.ops.length) ? null : ticket;
		}).then(function (ticket) {
			self.updateOfflineStatus();
			return ticket || self.emptyTicket(plc);
		});
	},

	/**
	 * Catalog data of a product (label, price, deposit data), from the catalog cached on
	 * this terminal, else from the tapped tile.
	 *
	 * @param  {number} idproduct Product id
	 * @param  {Object} [info]    Data of the tapped tile {label, price_ttc}
	 * @returns {Promise<Object|null>} Promise of {label, unit, ret, dep} or null when unknown
	 */
	productData: function (idproduct, info) {
		'use strict';

		var socid = parseInt(jQuery('#thirdpartyid').val(), 10) || 0;
		return takePOSOfflineStoreGet('products:' + socid + ':__all__').then(function (all) {
			var product = (all && all[idproduct]) ? all[idproduct] : null;
			if (!product && socid) {
				return takePOSOfflineStoreGet('products:0:__all__').then(function (all0) {
					return (all0 && all0[idproduct]) ? all0[idproduct] : null;
				});
			}
			return product;
		}).catch(function () {
			return null;
		}).then(function (product) {
			if (!product && info && info.label) {
				product = { label: String(info.label), price_ttc: parseFloat(info.price_ttc) || 0 };
			}
			if (!product) {
				return null;
			}
			var data = { label: product.label || ('Product ' + idproduct), unit: parseFloat(product.price_ttc) || 0, ret: !!product.ret, dep: null };
			if (product.deposit) {
				data.dep = { id: parseInt(product.deposit, 10), qty: parseFloat(product.deposit_qty) || 1, label: product.deposit_label || '', unit: parseFloat(product.deposit_price_ttc) || 0 };
			}
			return data;
		});
	},

	/**
	 * Compute what the screen shows for a ticket: the lines of the last server state,
	 * with the operations not confirmed yet applied on top, and the deposit lines those
	 * operations will bring (predicted from the catalog until the server answers).
	 *
	 * @param  {Object} ticket Stored ticket
	 * @returns {Object} {lines: [{key, lineid, product, label, count, unit, kind, ret, remise, pending}], total, placeid, socid}
	 */
	computeView: function (ticket) {
		'use strict';

		var lines = [];
		var server = ticket && ticket.server ? ticket.server : null;
		var i, line, op;
		if (server && server.lines) {
			for (i = 0; i < server.lines.length; i++) {
				var sl = server.lines[i];
				var count = Math.abs(parseFloat(sl.qty) || 0);
				lines.push({
					key: 'l' + sl.lineid,
					lineid: parseInt(sl.lineid, 10) || 0,
					product: parseInt(sl.product, 10) || 0,
					label: sl.label,
					count: count,
					unit: count > 0 ? (parseFloat(sl.total_ttc) || 0) / count : (parseFloat(sl.total_ttc) || 0),
					kind: sl.kind || 'other',
					ret: !!parseInt(sl.ret, 10),
					sent: !!parseInt(sl.sent, 10),
					remise: parseFloat(sl.remise_percent) || 0,
					pending: false
				});
			}
		}
		var findLine = function (o) {
			var j;
			if (o.type != 'add' && o.lineid > 0) {
				for (j = 0; j < lines.length; j++) {
					if (lines[j].lineid == o.lineid && lines[j].kind == 'product') {
						return lines[j];
					}
				}
			}
			// Same rule as the server: a tap never adds to a line already sent to the kitchen
			for (j = 0; j < lines.length; j++) {
				if (lines[j].product == o.product && o.product > 0 && lines[j].kind == 'product' && !lines[j].sent) {
					return lines[j];
				}
			}
			return null;
		};
		var deposits = {};
		var ops = (ticket && ticket.ops) ? ticket.ops : [];
		for (i = 0; i < ops.length; i++) {
			op = ops[i];
			var qty = parseFloat(op.qty) || 0;
			line = findLine(op);
			var before = line ? line.count : 0;
			var after = (op.type == 'add' || op.type == 'delta') ? before + qty : (op.type == 'set' ? qty : 0);
			if (!line) {
				if (op.type == 'remove' || after <= 0 || !(op.product > 0)) {
					continue;
				}
				line = { key: 'p' + op.product, lineid: 0, product: op.product, label: op.label || ('Product ' + op.product), count: 0, unit: op.ret ? -Math.abs(op.unit || 0) : (op.unit || 0), kind: 'product', ret: !!op.ret, remise: 0, pending: true };
				lines.push(line);
			}
			after = Math.max(0, after);
			if (op.dep && op.dep.id) {
				var d = deposits[op.dep.id] || (deposits[op.dep.id] = { qty: 0, label: op.dep.label, unit: op.dep.unit });
				d.qty += (after - line.count) * (op.dep.qty || 1);
			}
			line.count = after;
			line.pending = true;
		}
		for (var depid in deposits) {
			if (!deposits.hasOwnProperty(depid)) {
				continue;
			}
			var dl = null;
			for (i = 0; i < lines.length; i++) {
				if (lines[i].kind == 'deposit' && lines[i].product == depid) {
					dl = lines[i];
					break;
				}
			}
			if (dl) {
				dl.count = Math.max(0, dl.count + deposits[depid].qty);
				dl.pending = true;
			} else if (deposits[depid].qty > 0) {
				lines.push({ key: 'd' + depid, lineid: 0, product: parseInt(depid, 10), label: deposits[depid].label || ('Product ' + depid), count: deposits[depid].qty, unit: deposits[depid].unit, kind: 'deposit', ret: false, remise: 0, pending: true });
			}
		}
		// total: the whole ticket; chargeable: what an offline sale books (products, and the
		// deposits Deposito adds to them) - free lines and discount lines stay on the ticket
		var view = { lines: [], total: 0, chargeable: 0, placeid: server ? (parseInt(server.placeid, 10) || 0) : 0, socid: (server && server.socid) ? server.socid : ((ticket && ticket.socid) || 0) };
		for (i = 0; i < lines.length; i++) {
			if (lines[i].kind != 'other' && lines[i].count <= 0.0001) {
				continue;
			}
			view.lines.push(lines[i]);
			view.total += lines[i].unit * lines[i].count;
			if (lines[i].kind != 'other') {
				view.chargeable += lines[i].unit * lines[i].count;
			}
		}
		view.total = Math.round(view.total * 100) / 100;
		view.chargeable = Math.round(view.chargeable * 100) / 100;
		return view;
	},

	/**
	 * Read the ticket of the current place as a cart for the offline payment dialogs:
	 * only the lines an offline sale books (products and their deposits), so the amount
	 * asked is the amount booked.
	 *
	 * @param  {string} [plc] Place (current place when not provided)
	 * @returns {Promise<Object|null>} Promise of {place, socid, total, lines: [{rowid, label, price_ttc, qty}]}, null when empty
	 */
	getCart: function (plc) {
		'use strict';

		var self = this;
		return this.getTicket(plc).then(function (ticket) {
			var view = self.computeView(ticket);
			var cart = { place: ticket.place, socid: view.socid, total: view.chargeable, lines: [] };
			for (var i = 0; i < view.lines.length; i++) {
				if (view.lines[i].kind != 'other') {
					cart.lines.push({ rowid: view.lines[i].product, label: view.lines[i].label, price_ttc: view.lines[i].unit, qty: view.lines[i].count });
				}
			}
			return cart.lines.length ? cart : null;
		});
	},

	/**
	 * Add an operation to the ticket of a place, show it at once and send it.
	 *
	 * @param  {string} plc Place
	 * @param  {Object} op  Operation without uid
	 * @returns {Promise<bool>} Promise of true when stored
	 */
	pushOp: function (plc, op) {
		'use strict';

		var self = this;
		op.uid = this.newSaleUid();
		op.created = Date.now();
		return this.updateTicket(plc, function (ticket) {
			ticket.ops.push(op);
		}).then(function () {
			self._lastSaleNotice = '';
			return self.renderCart();
		}).then(function () {
			self.requestFlush();
			return true;
		});
	},

	/**
	 * A product tile was tapped: qty more of the product on the ticket of the place.
	 *
	 * @param  {number} idproduct ID of product
	 * @param  {number} qty       Quantity to add
	 * @param  {Object} [info]    Data read from the tapped tile: {label, price_ttc}
	 * @returns {Promise<bool>} Promise of false when the product could not be identified at all
	 */
	addProduct: function (idproduct, qty, info) {
		'use strict';

		var self = this;
		var plc = this.currentPlace();
		idproduct = parseInt(idproduct, 10) || 0;
		qty = (parseFloat(qty) > 0) ? parseFloat(qty) : 1;
		return this.productData(idproduct, info).then(function (data) {
			if (!data) {
				window.alert(self.t('FluxPosOfflineNotInCachedCatalog', 'This product is not into the catalog cached on this terminal. It cannot be sold while the terminal is offline.'));
				return false;
			}
			return self.pushOp(plc, { type: 'add', product: idproduct, lineid: 0, qty: qty, label: data.label, unit: data.unit, ret: data.ret, dep: data.dep });
		});
	},

	/**
	 * Kept for index.php: a tap on a place with an order on the server is an ordinary
	 * tap now (the ticket is shared, nothing has to be adopted first).
	 *
	 * @param  {number} idproduct ID of product
	 * @param  {number} qty       Quantity to add
	 * @param  {Object} [info]    Data read from the tapped tile
	 * @returns {Promise<bool>} See addProduct()
	 */
	captureOnServerOrder: function (idproduct, qty, info) {
		'use strict';

		return this.addProduct(idproduct, qty, info);
	},

	/**
	 * Line of the current view by its key, or by the id of the row on the screen (the
	 * invoice line id, or 'pending<product>' for a line not confirmed yet).
	 *
	 * @param  {string} key Key of the line, or id of its row
	 * @returns {Promise<Object|null>} Promise of the view line
	 */
	findViewLine: function (key) {
		'use strict';

		var self = this;
		key = String(key);
		return this.getTicket().then(function (ticket) {
			var view = self.computeView(ticket);
			for (var i = 0; i < view.lines.length; i++) {
				var line = view.lines[i];
				if (line.key == key || (line.lineid > 0 && String(line.lineid) == key) || ('pending' + line.product) == key) {
					return line;
				}
			}
			return null;
		});
	},

	/**
	 * Change the counted quantity of a line by delta (the +/- steppers).
	 *
	 * @param  {string} key   Key of the line (or id of its row)
	 * @param  {number} delta Quantity to add (can be negative)
	 * @returns {Promise<bool>} Promise resolved when done
	 */
	changeQty: function (key, delta) {
		'use strict';

		return this.lineOp(key, 'delta', parseFloat(delta) || 0);
	},

	/**
	 * Set the counted quantity of a line (quantity pad).
	 *
	 * @param  {string} key Key of the line (or id of its row)
	 * @param  {number} qty New quantity (0 removes the line)
	 * @returns {Promise<bool>} Promise resolved when done
	 */
	setQty: function (key, qty) {
		'use strict';

		qty = parseFloat(qty);
		if (isNaN(qty) || qty < 0) {
			return Promise.resolve(false);
		}
		return this.lineOp(key, qty > 0 ? 'set' : 'remove', qty);
	},

	/**
	 * Store an operation on a product line of the current view.
	 *
	 * @param  {string} key  Key of the line (or id of its row)
	 * @param  {string} type 'delta', 'set' or 'remove'
	 * @param  {number} qty  Quantity of the operation
	 * @returns {Promise<bool>} Promise of true when stored
	 */
	lineOp: function (key, type, qty) {
		'use strict';

		var self = this;
		var plc = this.currentPlace();
		return this.findViewLine(key).then(function (line) {
			if (!line || line.kind != 'product') {
				return false;	// Deposit and other lines follow the server, never the terminal
			}
			return self.productData(line.product, { label: line.label, price_ttc: Math.abs(line.unit) }).then(function (data) {
				return self.pushOp(plc, {
					type: type,
					product: line.product,
					lineid: line.lineid,
					qty: qty,
					label: line.label,
					unit: Math.abs(line.unit),
					ret: line.ret,
					dep: data ? data.dep : null
				});
			});
		});
	},

	/**
	 * Drop the operations of the current place not sent yet (New while offline): the
	 * ticket on the server stays as it is.
	 *
	 * @param  {string} [plc] Place (current place when not provided)
	 * @returns {Promise<bool>} Promise resolved when done
	 */
	clearCart: function (plc) {
		'use strict';

		var self = this;
		plc = (typeof plc == 'undefined') ? this.currentPlace() : String(plc);
		return this.updateTicket(plc, function (ticket) {
			ticket.ops = [];
		}).then(function () {
			return self.renderCart(true);
		});
	},

	/**
	 * Forget the ticket of the current place on this terminal (after New or a payment
	 * online): waits for a sending in progress first, so no operation of the old ticket
	 * can arrive after it and create it again.
	 *
	 * @returns {Promise<bool>} Promise resolved when done
	 */
	discardTicket: function () {
		'use strict';

		var plc = this.currentPlace();
		return (this._flushing || Promise.resolve()).then(function () {
			return takePOSOfflineStoreDelete('ticket:' + plc);
		}).then(function () {
			return true;
		}, function () {
			return false;
		});
	},

	/**
	 * Store the last state answered by the server for a place, unless a more recent
	 * answer was stored already (seq = order in which the requests were started).
	 *
	 * @param  {string} plc   Place
	 * @param  {Object} state Ticket state answered by the server
	 * @param  {number} seq   Sequence number of the request
	 * @param  {Object} [done] Uids of the operations the server applied or rejected
	 * @param  {boolean} [onlyifstored] Do nothing when the ticket is not stored anymore
	 * @returns {Promise<Object>} Promise of the stored ticket
	 */
	storeServerState: function (plc, state, seq, done, onlyifstored) {
		'use strict';

		var confirms = false;
		if (done) {
			for (var uid in done) {
				if (done.hasOwnProperty(uid)) {
					confirms = true;
					break;
				}
			}
		}
		return this.updateTicket(plc, function (ticket, existed) {
			if (onlyifstored && !existed) {
				// The ticket was paid offline or discarded while the answer was on its way:
				// never bring it back on this terminal
				ticket.server = null;
				ticket.ops = [];
				return;
			}
			if (done) {
				ticket.ops = ticket.ops.filter(function (op) {
					return !done[op.uid];
				});
			}
			// An answer that confirms operations is always stored: it holds them, while a
			// read started later could have been answered before they were applied
			if (confirms || seq >= (ticket.seq || 0)) {
				ticket.seq = Math.max(seq, ticket.seq || 0);
				ticket.server = state;
				if (state && state.socid) {
					ticket.socid = state.socid;
				}
			}
		});
	},

	/**
	 * Ask for a sending of the operations soon (fast taps are sent together).
	 */
	requestFlush: function () {
		'use strict';

		var self = this;
		if (this.isOffline() || !window.indexedDB) {
			return;
		}
		if (this._flushTimer) {
			clearTimeout(this._flushTimer);
		}
		this._flushTimer = setTimeout(function () {
			self._flushTimer = null;
			self.flush();
		}, 150);
	},

	/**
	 * Send the operations of every ticket stored on this terminal, one request per
	 * place, one sender at a time. The answer of the server replaces the server state
	 * of the ticket and removes the confirmed operations from the outbox; operations
	 * stored meanwhile stay and are sent by the next pass.
	 *
	 * @returns {Promise<bool>} Promise of true when every outbox is empty
	 */
	flush: function () {
		'use strict';

		var self = this;
		if (this._flushing) {
			this._flushAgain = true;
			return this._flushing;
		}
		if (this.isOffline() || !window.indexedDB) {
			return Promise.resolve(false);
		}
		var url = this.fluxposbaseurl + 'ajax/ajax.php?action=applyOps&token=' + this.fluxposnewtoken;
		this._flushing = takePOSOfflineStoreKeys('ticket:').then(function (keys) {
			var promise = Promise.resolve(true);
			jQuery.each(keys, function (index, key) {
				promise = promise.then(function (ok) {
					if (!ok) {
						return false;
					}
					var plc = String(key.substring('ticket:'.length));
					return takePOSOfflineStoreGet(key).then(function (ticket) {
						if (!ticket || !ticket.ops || !ticket.ops.length) {
							return true;
						}
						var ops = ticket.ops.slice(0, 50);
						var seq = self.nextSeq();
						return jQuery.ajax(url, {
							method: 'POST',
							contentType: 'application/json; charset=utf-8',
							data: JSON.stringify({
								place: plc,
								thirdpartyid: ticket.socid || 0,
								ops: ops.map(function (op) {
									return { uid: op.uid, type: op.type, product: op.product, lineid: op.lineid, qty: op.qty };
								})
							}),
							dataType: 'json',
							timeout: self.requestTimeout,	// Safe to send again: the server applies each operation once
							global: false
						}).then(function (data) {
							self.markServerUp();
							if (!data || data.error) {
								console.log('FluxPos offline: operations of place ' + plc + ' not applied: ' + (data ? data.error : 'no answer'));
								return false;	// Kept: sent again by the next pass
							}
							var done = {};
							(data.acked || []).forEach(function (uid) {
								done[uid] = true;
							});
							(data.rejected || []).forEach(function (rej) {
								done[rej.uid] = true;
								self._rejectedOps = (self._rejectedOps || 0) + 1;
								console.log('FluxPos offline: operation ' + rej.uid + ' refused by the server: ' + rej.error);
							});
							return self.storeServerState(plc, data, seq, done, true).then(function () {
								if (plc === self.currentPlace()) {
									return self.renderCart(true);
								}
							}).then(function () {
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
			console.log('FluxPos offline: sending of the operations failed', error);
			return false;
		}).then(function (result) {
			self._flushing = null;
			if (self._flushAgain) {
				self._flushAgain = false;
				return self.flush();
			}
			return result;
		});
		return this._flushing;
	},

	/**
	 * Sequence number for the requests that answer a ticket state (the newest wins).
	 *
	 * @returns {number} Next sequence number
	 */
	nextSeq: function () {
		'use strict';

		this._seq = Math.max((this._seq || 0) + 1, Date.now());
		return this._seq;
	},

	/**
	 * Read the ticket of the current place from the server (changes made on another
	 * device or by a screen of the server: payment, discount, free line, line pad) and
	 * show it.
	 *
	 * @param  {boolean} [force] Ask the full state even when the version did not change
	 * @returns {Promise<bool>} Promise of true when the state was read
	 */
	refreshTicket: function (force) {
		'use strict';

		var self = this;
		if (this.isOffline() || !window.indexedDB) {
			return Promise.resolve(false);
		}
		var plc = this.currentPlace();
		var seq = this.nextSeq();
		return this.getTicket(plc).then(function (ticket) {
			var version = (!force && ticket.server && ticket.server.version) ? ticket.server.version : '';
			return jQuery.ajax(self.fluxposbaseurl + 'ajax/ajax.php?action=getTicket&token=' + self.fluxposnewtoken + '&place=' + encodeURIComponent(plc) + '&version=' + encodeURIComponent(version), {
				dataType: 'json',
				timeout: self.requestTimeout,
				cache: false,
				global: false
			}).then(function (data) {
				self.markServerUp();
				if (!data || data.error || data.unchanged) {
					return false;
				}
				return self.storeServerState(plc, data, seq).then(function () {
					return self.renderCart(true);
				}).then(function () {
					return true;
				});
			}, function (jqxhr, textStatus) {
				self.handleFailure(jqxhr, textStatus);
				return false;
			});
		});
	},

	/**
	 * Kept for index.php (1.7 names): the screen of the server changed the ticket, read
	 * it again.
	 *
	 * @returns {Promise<bool>} See refreshTicket()
	 */
	replaceCartFromScreen: function () {
		'use strict';

		return this.refreshTicket(true);
	},

	/**
	 * Kept for index.php (1.7 names): see refreshTicket().
	 *
	 * @returns {Promise<bool>} See refreshTicket()
	 */
	cacheServerOrder: function () {
		'use strict';

		return this.refreshTicket(true);
	},

	/**
	 * Wait until the ticket of the current place holds every change of this terminal
	 * (the payment screens of the server read the ticket there), and put its id in the
	 * screen.
	 *
	 * @returns {Promise<bool|string>} Promise of true when the server holds it, 'offline' when the terminal works offline
	 */
	ensureSynced: function () {
		'use strict';

		var self = this;
		if (this.isOffline()) {
			return Promise.resolve('offline');
		}
		if (!window.indexedDB) {
			return Promise.resolve(true);
		}
		return this.flush().then(function () {
			return self.getTicket();
		}).then(function (ticket) {
			if (ticket.ops.length) {
				return false;
			}
			if (ticket.server && ticket.server.placeid) {
				jQuery('#invoiceid').val(String(ticket.server.placeid));
				if (typeof invoiceid != 'undefined') {
					invoiceid = String(ticket.server.placeid);
				}
			}
			return true;
		});
	},

	/**
	 * Pay the ticket of the current place while offline. It becomes a SALE with its own
	 * unique id, stored on this terminal until the server books it exactly once. The sale
	 * tells which lines of the open ticket on the server it covers, so the server takes
	 * them off (what another device added meanwhile stays open there). The ticket is
	 * emptied on this terminal at once so the next customer can be served.
	 *
	 * @param  {Array} payments Payments captured: [{pay: 'LIQ'|'CB'|'CHQ'|..., amount, excess}]
	 * @returns {Promise<bool>} Promise of true when the sale has been stored
	 */
	validateCart: function (payments) {
		'use strict';

		var self = this;
		var plc = this.currentPlace();
		// Never wait for a sending in progress: offline it can hang. The sale carries the
		// operations not confirmed yet; the server applies the ones it did not apply yet
		// (once, by their ids) before taking the sold quantities off the ticket.
		return this.getTicket(plc).then(function (ticket) {
			var view = self.computeView(ticket);
			var lines = [];
			var hasother = false;
			for (var i = 0; i < view.lines.length; i++) {
				var line = view.lines[i];
				if (line.kind == 'product' && line.count > 0) {
					lines.push({ rowid: line.product, label: line.label, price_ttc: line.unit, qty: line.count });
				} else if (line.kind == 'other') {
					hasother = true;
				}
			}
			if (!lines.length) {
				window.alert(self.t('FluxPosOfflineNoOrderCaptured', 'There is no order captured on this place.'));
				return false;
			}
			if (hasother && !window.confirm(self.t('FluxPosOfflineOtherLines', 'This ticket has lines that are not products (free text, discount): they cannot be booked offline and will stay on the ticket. Pay the products now?'))) {
				return false;
			}
			var sale = {
				uid: self.newSaleUid(),
				place: plc,
				socid: view.socid || 0,
				lines: lines,
				payments: payments || [],
				total_ttc: view.chargeable,
				ticket: {
					id: (ticket.server && ticket.server.placeid) ? ticket.server.placeid : 0,
					ops: (ticket.ops || []).map(function (op) {
						return { uid: op.uid, type: op.type, product: op.product, lineid: op.lineid, qty: op.qty };
					})
				},
				created: Math.floor(Date.now() / 1000),
				error: ''
			};
			return takePOSOfflineStorePutAndDelete('sale:' + sale.uid, sale, 'ticket:' + plc).then(function () {
				self._lastSaleNotice = self.t('FluxPosOfflineSaleStored', 'Sale of %s stored on this terminal. Its invoice number is given by the server when the connection is back.').replace('%s', self.formatAmount(sale.total_ttc));
				self.updateOfflineStatus();
				self.scheduleSync();
				return self.renderCart(true);
			}).then(function () {
				return true;
			});
		});
	},

	/**
	 * Turn what older versions stored on this terminal into the 1.8 storage: a cart
	 * validated offline becomes a sale; the products of a cart never confirmed by the
	 * server become 'add' operations of the ticket of its place; a cart the server
	 * already holds is dropped (the server has it).
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
						var plc = String(key.substring('cart:'.length));
						if (!cart || !cart.lines) {
							return takePOSOfflineStoreDelete(key);
						}
						if (cart.validated === true) {
							var total = 0;
							cart.lines.forEach(function (l) {
								total += (parseFloat(l.price_ttc) || 0) * (parseFloat(l.qty) || 0);
							});
							var sale = { uid: self.newSaleUid(), place: plc, socid: cart.socid || 0, lines: cart.lines.filter(function (l) {
								return l.mirror !== true;
							}), payments: cart.payments || [], total_ttc: Math.round(total * 100) / 100, ticket: null, created: Math.floor(Date.now() / 1000), error: '' };
							return takePOSOfflineStorePutAndDelete('sale:' + sale.uid, sale, key);
						}
						// A line the server already held (cart confirmed by the server, or a line
						// taken over from the order of the server) becomes a 'set' to the quantity
						// of the cart: changes not sent yet are kept, nothing is counted twice.
						// A line only captured on this terminal becomes an 'add'.
						var mirrored = (cart.synced === true || cart.adopted === true);
						return self.updateTicket(plc, function (ticket) {
							cart.lines.forEach(function (l) {
								var productid = parseInt(l.rowid, 10) || 0;
								var q = parseFloat(l.qty) || 0;
								if (l.mirror === true || q <= 0 || productid <= 0) {
									return;
								}
								var known = mirrored || l.adopted === true;
								ticket.ops.push({ uid: self.newSaleUid(), created: Date.now(), type: known ? 'set' : 'add', product: productid, lineid: known ? (parseInt(l.lineid, 10) || 0) : 0, qty: q, label: l.label, unit: parseFloat(l.price_ttc) || 0, ret: false, dep: null });
							});
						}).then(function () {
							return takePOSOfflineStoreDelete(key);
						});
					});
				});
			});
			return promise;
		}).catch(function (error) {
			console.log('FluxPos offline: migration of the carts of an older version failed', error);
			return false;
		});
	},

	/**
	 * Render the ticket of the current place into #poslines with the same markup as the
	 * fragment of invoice.php. An empty ticket leaves the screen as it is (the receipt
	 * screen shown after a payment, for example) unless force is set.
	 *
	 * @param  {boolean} [force] Render even an empty ticket over the screen
	 * @returns {Promise<bool>} Promise of true when the ticket has been rendered
	 */
	renderCart: function (force) {
		'use strict';

		var self = this;
		if (typeof jQuery == 'undefined' || typeof place == 'undefined') {
			return Promise.resolve(false);
		}
		return this.getTicket().then(function (ticket) {
			var view = self.computeView(ticket);
			if (!view.lines.length) {
				if (!force || jQuery('#offlinecart').val() != '1') {
					return false;	// Nothing on the ticket: the screen of the server stays
				}
			}
			var canedit = (typeof fluxposdisplaynumberpad == 'undefined' || fluxposdisplaynumberpad != 'never');
			var html = '';
			var i, line;

			html += '<div class="div-table-responsive-no-min invoice">';
			html += '<table id="tablelines" class="noborder noshadow postablelines centpercent">';
			html += '<tr class="liste_titre nodrag nodrop">';
			html += '<td class="linecoldescription">';
			html += '<input type="hidden" name="invoiceid" id="invoiceid" value="' + (view.placeid > 0 ? view.placeid : '') + '">';
			html += '<input type="hidden" name="thirdpartyid" id="thirdpartyid" value="' + (parseInt(view.socid, 10) || 0) + '">';
			html += '<input type="hidden" name="offlinecart" id="offlinecart" value="1">';
			html += '</td>';
			html += '<td class="linecolqty right">' + self.escapeHtml(self.t('ReductionShort', 'Disc.')) + '</td>';
			html += '<td class="linecolqty right">' + self.escapeHtml(self.t('Qty', 'Qty')) + '</td>';
			html += '<td class="linecolht right nowraponall"><span class="opacitymedium small">' + self.escapeHtml(self.t('TotalTTCShort', 'Total')) + '</span><br><span id="linecolht-span-total" style="font-size:1.3em; font-weight: bold;">' + self.formatAmount(view.total) + '</span></td>';
			html += '</tr>';
			if (!view.lines.length) {
				html += '<tr class="drag drop oddeven"><td class="left"><span class="opacitymedium">' + self.escapeHtml(self._lastSaleNotice || self.t('Empty', 'Empty')) + '</span></td><td></td><td></td><td></td></tr>';
			}
			for (i = view.lines.length - 1; i >= 0; i--) {
				line = view.lines[i];
				var rowid = line.lineid > 0 ? String(line.lineid) : ('pending' + line.product);
				var isproduct = (line.kind == 'product');
				html += '<tr class="drag drop oddeven posinvoiceline' + (line.pending ? ' fluxpospending' : '') + '" id="' + self.escapeHtml(rowid) + '">';
				html += '<td class="left">';
				if (line.product > 0 && !window.fluxposhideproductimages) {
					html += '<img class="cartthumb" loading="lazy" alt="" onerror="this.style.display=\'none\';" src="' + self.fluxposbaseurl + 'genimg/index.php?query=pro&id=' + parseInt(line.product, 10) + '">';
				}
				html += self.escapeHtml(line.label) + '</td>';
				html += '<td class="right"' + (isproduct && canedit && line.lineid > 0 ? ' onclick="event.stopPropagation(); FluxPosTapLine(this, \'r\');"' : '') + '>' + (line.remise ? self.escapeHtml(line.remise) + '%' : '') + '</td>';
				html += '<td class="right nowraponall">';
				if (isproduct) {
					html += '<button type="button" class="fluxposqtybtn" onclick="event.stopPropagation(); FluxPosOffline.changeQty(\'' + line.key + '\', -1);">-</button>';
				}
				html += '<span class="fluxposqtyvalue"' + (isproduct && canedit ? ' onclick="event.stopPropagation(); FluxPosTapLine(this, \'qty\');"' : '') + '>' + self.escapeHtml(Math.round(line.count * 1000) / 1000) + '</span>';
				if (isproduct) {
					html += '<button type="button" class="fluxposqtybtn" onclick="event.stopPropagation(); FluxPosOffline.changeQty(\'' + line.key + '\', 1);">+</button>';
				}
				html += '</td>';
				html += '<td class="right classfortooltip"' + (isproduct && canedit && line.lineid > 0 ? ' onclick="event.stopPropagation(); FluxPosTapLine(this, \'p\');"' : '') + '>' + self.formatAmount(line.unit * line.count) + '</td>';
				html += '</tr>';
			}
			html += '</table></div>';
			jQuery('#poslines').html(html);
			if (typeof invoiceid != 'undefined') {
				invoiceid = (view.placeid > 0 ? String(view.placeid) : '');
			}
			if (typeof fluxposUpdateCartTotal == 'function') {
				fluxposUpdateCartTotal(view.lines.length ? self.formatAmount(view.total) : '');
			}
			return true;
		});
	},

	/**
	 * Count what waits on this terminal: tickets with operations not sent yet, paid
	 * sales not booked yet, and among those the sales the server refused (with an error).
	 *
	 * @returns {Promise<Object>} Promise of {orders, sales, failed}
	 */
	countPending: function () {
		'use strict';

		var res = { orders: 0, sales: 0, failed: 0 };
		return takePOSOfflineStoreKeys('ticket:').then(function (keys) {
			return Promise.all(keys.map(function (key) {
				return takePOSOfflineStoreGet(key);
			}));
		}).then(function (tickets) {
			for (var i = 0; i < tickets.length; i++) {
				if (tickets[i] && tickets[i].ops && tickets[i].ops.length) {
					res.orders++;
				}
			}
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
	 * Count the tickets and sales not yet synchronized to the server.
	 *
	 * @returns {Promise<number>} Promise of the number of pending tickets and sales
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
	 * Init the online/offline status indicator, its listeners, the reading of the ticket
	 * every few seconds (changes made on another device) and the migration of what an
	 * older version stored on this terminal.
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
		// A screen of the server changed the ticket (line pad, discount, free line,
		// customer, payment, split...): it loads a fragment of invoice.php, also from the
		// popups through parent.$. Read the ticket again afterwards.
		jQuery(document).ajaxComplete(function (event, jqxhr, settings) {
			if (jqxhr && jqxhr.status == 200 && self.isDolibarrUrl(settings.url) && /(^|\/)invoice\.php(\?|$)/.test(String(settings.url))) {
				if (self._fragmentTimer) {
					clearTimeout(self._fragmentTimer);
				}
				self._fragmentTimer = setTimeout(function () {
					self._fragmentTimer = null;
					self.refreshTicket(true);
				}, 100);
			}
		});

		window.addEventListener('online', function () {
			self.updateOfflineStatus();
			self.syncCatalog();
			self.syncOutbox().then(function () {
				return self.refreshTicket(true);
			});
		});
		window.addEventListener('offline', function () {
			self.updateOfflineStatus();
			self.renderCart();
		});

		// Changes made on another device: read the ticket of the place every few seconds
		// (a cheap call: the server answers "unchanged" while the version is the same)
		setInterval(function () {
			// Not while sales or operations are being sent: a read before they are booked
			// would show their lines again for a moment
			if (self.isOffline() || self._flushing || self._syncing || (document.visibilityState && document.visibilityState != 'visible')) {
				return;
			}
			self.getTicket().then(function (ticket) {
				if (ticket.ops.length) {
					self.flush();
				} else {
					self.refreshTicket(false);
				}
			});
		}, 4000);

		// Retry what is still waiting (a sale refused by the server, a failed sending)
		setInterval(function () {
			if (!self.isOffline()) {
				self.syncOutbox();
			}
		}, 60000);

		this.migrateLegacyCarts().then(function () {
			self.updateOfflineStatus();
			return self.syncOutbox();
		}).then(function () {
			return self.refreshTicket(true);
		}).then(function (read) {
			if (!read) {
				return self.renderCart();	// Offline at start: show the ticket stored on this terminal
			}
		});
	},

	/**
	 * Plan the background synchronization some seconds after the last modification.
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
							created: sale.created,
							ticket: sale.ticket || null,
							total: sale.total_ttc
						}),
						dataType: 'json',
						timeout: 0,		// Never give up while the server books the sale
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
						console.log('FluxPos offline: sale ' + sale.uid + ' booked as invoice ' + data.ref + (data.duplicate ? ' (already booked before)' : '') + (data.check ? ' - marked to check' : ''));
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
	 * Send everything that waits on this terminal: the paid sales first, then the
	 * operations of the open tickets.
	 *
	 * @returns {Promise<bool>} Promise of true when everything was sent
	 */
	syncOutbox: function () {
		'use strict';

		var self = this;
		if (this._syncing || this.isOffline() || !window.indexedDB) {
			return Promise.resolve(false);
		}
		this._syncing = true;
		this._syncPromise = this.syncSales().then(function (salesok) {
			return self.flush().then(function (opsok) {
				return salesok && opsok;
			});
		}).catch(function (error) {
			console.log('FluxPos offline: background synchronization failed', error);
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
