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
 *	\brief      Offline support for TakePOS: catalog cache into IndexedDB, online/offline
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
 * Remove one entry of the catalog store.
 *
 * @param  {string} key Key of the entry
 * @returns {Promise<bool>} Promise resolved when the removal is done
 */
function takePOSOfflineStoreDelete(key) {
	'use strict';

	return openTakePOSOfflineDB().then(function (db) {
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

	return openTakePOSOfflineDB().then(function (db) {
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
	takeposcurrency: (typeof takeposcurrency != 'undefined') ? takeposcurrency : '',
	takeposlang: (typeof takeposlang != 'undefined') ? takeposlang : {},

	/**
	 * Return a translated string passed by index.php (takeposlang), or a fallback text.
	 *
	 * @param  {string} key      Key into takeposlang
	 * @param  {string} fallback Text used when the key is not provided by index.php
	 * @returns {string} The translated text
	 */
	t: function (key, fallback) {
		'use strict';

		return (this.takeposlang && this.takeposlang[key]) ? this.takeposlang[key] : fallback;
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
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: this.takeposcurrency, minimumFractionDigits: 2 }).format(amount);
		} catch (error) {
			return (Math.round(amount * 100) / 100).toFixed(2) + ' ' + this.takeposcurrency;
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
		return this.getCart().then(function (cart) {
			if (cart == null) {
				cart = { place: String(place), socid: socid, created: Math.floor(Date.now() / 1000), lines: [] };
			}
			return takePOSOfflineStoreGet('products:' + socid + ':__all__').then(function (allproducts) {
				var product = (allproducts && allproducts[idproduct]) ? allproducts[idproduct] : null;
				if (product == null) {
					window.alert('This product is not into the catalog cached on this terminal. It cannot be sold while the terminal is offline.');
					return false;
				}
				var found = false;
				for (var i = 0; i < cart.lines.length; i++) {
					if (String(cart.lines[i].rowid) == String(idproduct)) {
						cart.lines[i].qty = Math.round((cart.lines[i].qty + qty) * 1000) / 1000;
						found = true;
						break;
					}
				}
				if (!found) {
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
			for (var i = 0; i < cart.lines.length; i++) {
				if (String(cart.lines[i].rowid) == String(idproduct)) {
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
	 * Render the local cart of the current place into #poslines, with the same markup
	 * as the #poslines fragment of invoice.php (same hidden fields, same line classes),
	 * so the POS interface keeps working exactly like when the order is on the server.
	 * An order already stored on the server is never replaced: it can only be rendered
	 * by invoice.php once the connection is back.
	 *
	 * @returns {Promise<bool>} Promise of true when the cart has been rendered
	 */
	renderCart: function () {
		'use strict';

		var self = this;
		if (typeof jQuery == 'undefined' || typeof place == 'undefined') {
			return Promise.resolve(false);
		}
		var offlinecartdisplayed = jQuery('#offlinecart').val() == '1';
		if (navigator.onLine && !offlinecartdisplayed) {
			return Promise.resolve(false);
		}
		if ((jQuery('#invoiceid').val() || '') != '' && !offlinecartdisplayed) {
			// An order stored on the server is displayed: never hide it with the local cart
			return Promise.resolve(false);
		}
		return this.getCart().then(function (cart) {
			var lines = (cart && cart.lines) ? cart.lines : [];
			var html = '';
			var i, line, total = 0;

			for (i = 0; i < lines.length; i++) {
				total += lines[i].price_ttc * lines[i].qty;
			}

			// Same hidden fields as the #poslines fragment of invoice.php
			html += '<input type="hidden" name="invoiceid" id="invoiceid" value="">';
			html += '<input type="hidden" name="thirdpartyid" id="thirdpartyid" value="' + ((cart && cart.socid) ? parseInt(cart.socid, 10) : 0) + '">';
			html += '<input type="hidden" name="offlinecart" id="offlinecart" value="1">';
			html += '<table class="takeposofflinecart">';
			html += '<tr class="liste_titre nodrag nodrop"><td class="linecoldescription"></td><td class="linecolqty right">' + self.escapeHtml(self.t('Qty', 'Qty')) + '</td><td class="linecolht right"><span class="opacitymedium small">' + self.escapeHtml(self.t('TotalTTCShort', 'Total')) + '</span></td></tr>';
			if (!lines.length) {
				html += '<tr class="drag drop oddeven"><td class="left"><span class="opacitymedium">' + self.escapeHtml(self.t('Empty', 'Empty')) + '</span></td><td></td><td></td></tr>';
			}
			for (i = lines.length - 1; i >= 0; i--) {
				line = lines[i];
				html += '<tr class="drag drop oddeven posinvoiceline takeposofflinecartline">';
				html += '<td class="left"><span class="takeposofflinelabel">' + self.escapeHtml(line.label) + '</span></td>';
				html += '<td class="right nowraponall takeposofflineqty">';
				html += '<button type="button" class="takeposofflineqtybtn" onclick="TakePOSOffline.changeQty(' + line.rowid + ', -1);">-</button>';
				html += '<span class="takeposofflineqtyval">' + self.escapeHtml(line.qty) + '</span>';
				html += '<button type="button" class="takeposofflineqtybtn" onclick="TakePOSOffline.changeQty(' + line.rowid + ', 1);">+</button>';
				html += '</td>';
				html += '<td class="right takeposofflinetotal">' + self.formatAmount(line.price_ttc * line.qty) + '</td>';
				html += '</tr>';
			}
			html += '<tr class="liste_total"><td colspan="2" class="right">' + self.escapeHtml(self.t('TotalTTCShort', 'Total')) + '</td><td class="right">' + self.formatAmount(total) + '</td></tr>';
			html += '</table>';
			if (lines.length) {
				html += '<div class="takeposofflinenotice">' + (navigator.onLine ? 'This order is synchronized to the server in background. Payment will be available as soon as the synchronization is done.' : 'This order is stored on this terminal and will be synchronized to the server when the connection is back.') + '</div>';
			} else {
				html += '<div class="takeposofflinenotice opacitymedium">Offline mode: a new order can be captured, it will be synchronized to the server when the connection is back. An order already registered on the server is shown again when the connection is back.</div>';
			}
			jQuery('#poslines').html(html);
			return true;
		});
	},

	/**
	 * Count the local orders not yet synchronized to the server.
	 *
	 * @returns {Promise<number>} Promise of the number of pending orders
	 */
	countPendingOrders: function () {
		'use strict';

		return takePOSOfflineStoreKeys('cart:').then(function (keys) {
			return keys.length;
		}).catch(function () {
			return 0;
		});
	},

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
		this.updateOfflineStatus();
	},

	/**
	 * Plan the background synchronization of the local orders some seconds after the
	 * last modification: this lets the cashier continue to enter products without
	 * being interrupted by the synchronization.
	 */
	scheduleSync: function () {
		'use strict';

		var self = this;
		if (!navigator.onLine) {
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
	 * Synchronize the local orders (captured while offline) to the server, one place
	 * after the other: each place is sent to the idempotent syncOfflineOrder endpoint
	 * which creates or completes the draft invoice of the place. The cart of a place
	 * is removed as soon as its synchronization succeeds. A failure stops the replay:
	 * it is retried on the next trigger (new change, back online, page load).
	 *
	 * @returns {Promise<bool>} Promise of true when all the pending orders are synchronized
	 */
	syncOutbox: function () {
		'use strict';

		var self = this;
		if (this._syncing || !navigator.onLine || !window.indexedDB) {
			return Promise.resolve(false);
		}
		this._syncing = true;
		var currentplace = (typeof place != 'undefined') ? String(place) : null;
		return takePOSOfflineStoreKeys('cart:').then(function (keys) {
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
						var lines = [];
						for (var i = 0; i < cart.lines.length; i++) {
							if (cart.lines[i].qty > 0) {
								lines.push({ id: cart.lines[i].rowid, qty: cart.lines[i].qty });
							}
						}
						var url = self.takeposbaseurl + 'ajax/ajax.php?action=syncOfflineOrder&token=' + self.takeposnewtoken;
						return jQuery.ajax(url, {
							method: 'POST',
							contentType: 'application/json; charset=utf-8',
							data: JSON.stringify({ place: cart.place, thirdpartyid: cart.socid, lines: lines }),
							dataType: 'json'
						}).then(function (data) {
							if (data && data.error) {
								console.log('TakePOS offline: synchronization of the order of place ' + cart.place + ' failed: ' + data.error);
								return false;
							}
							console.log('TakePOS offline: order of place ' + cart.place + ' synchronized to the server');
							return takePOSOfflineStoreDelete(key).then(function () {
								if (plc === currentplace && typeof Refresh == 'function') {
									// Reload the order of the current place from the server
									Refresh();
								}
								return true;
							});
						});
					});
				});
			});
			return promise;
		}).catch(function (error) {
			console.log('TakePOS offline: background order synchronization failed', error);
			return false;
		}).then(function (result) {
			self._syncing = false;
			if (!self._syncTimer) {
				self.updateOfflineStatus();
			}
			return result;
		});
	},

	/**
	 * Show or hide the offline status pill. It also shows the number of local orders
	 * waiting for synchronization (and allows to retry the synchronization on click).
	 */
	updateOfflineStatus: function () {
		'use strict';

		var element = document.getElementById('takeposofflinestatus');
		var self = this;
		if (!element) {
			return;
		}
		this.countPendingOrders().then(function (pending) {
			element.classList.remove('offline', 'pending');
			element.onclick = null;
			if (!navigator.onLine) {
				element.classList.add('offline');
				element.textContent = pending > 0 ? 'Offline - ' + pending + ' order(s) waiting for synchronization' : 'Offline - catalog only';
			} else if (pending > 0) {
				element.classList.add('pending');
				element.textContent = pending + ' offline order(s) waiting for synchronization - click to retry';
				element.onclick = function () {
					self.syncOutbox();
				};
			}
		});
	}
};
