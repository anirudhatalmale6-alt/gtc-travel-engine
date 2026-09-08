/**
 * Front-end controller.
 *
 * Deliberately dependency-free: this drops into whatever managed WordPress
 * theme the site already runs without dragging a framework and its build step
 * behind it.
 *
 * The browser holds no supplier state — only a search hash and an offer key.
 * Every price shown here came from the server, and the price that is charged
 * is re-confirmed by the server at checkout regardless of what this file says.
 */
( function () {
	'use strict';

	if ( typeof window.GTC === 'undefined' ) {
		return;
	}

	var cfg = window.GTC;

	/* ------------------------------------------------------------- helpers */

	function api( path, body ) {
		return fetch( cfg.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( ( data && data.message ) || cfg.i18n.error );
					error.code = data && data.code;
					throw error;
				}
				return data;
			} );
		} );
	}

	function apiUrl( path, params ) {
		var url = cfg.root + path;
		var join = url.indexOf( '?' ) === -1 ? '?' : '&';

		Object.keys( params || {} ).forEach( function ( key ) {
			url += join + encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
			join = '&';
		} );

		return url;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	function money( minor, currency ) {
		try {
			return new Intl.NumberFormat( document.documentElement.lang || 'en', {
				style: 'currency',
				currency: currency,
				maximumFractionDigits: minor % 100 === 0 ? 0 : 2
			} ).format( minor / 100 );
		} catch ( e ) {
			return currency + ' ' + ( minor / 100 ).toFixed( 2 );
		}
	}

	function stars( rating ) {
		var n = Math.round( Number( rating ) || 0 );
		return n > 0 ? '★'.repeat( Math.min( 5, n ) ) : '';
	}

	/* -------------------------------------------------------------- search */

	function initSearch( app ) {
		var form = app.querySelector( '[data-gtc-form]' );
		if ( ! form ) {
			return;
		}

		var results = app.querySelector( '[data-gtc-results]' );
		var list = app.querySelector( '[data-gtc-list]' );
		var state = app.querySelector( '[data-gtc-state]' );
		var titleEl = app.querySelector( '[data-gtc-title]' );
		var metaEl = app.querySelector( '[data-gtc-meta]' );
		var suppliersEl = app.querySelector( '[data-gtc-suppliers]' );
		var pager = app.querySelector( '[data-gtc-pager]' );
		var sortEl = app.querySelector( '[data-gtc-sort]' );
		var categoryEl = app.querySelector( '[data-gtc-category]' );

		var lastQuery = null;

		Array.prototype.forEach.call( app.querySelectorAll( '[data-gtc-tab]' ), function ( tab ) {
			tab.addEventListener( 'click', function () {
				Array.prototype.forEach.call( app.querySelectorAll( '[data-gtc-tab]' ), function ( other ) {
					other.classList.remove( 'is-active' );
				} );
				tab.classList.add( 'is-active' );
				categoryEl.value = tab.getAttribute( 'data-gtc-tab' );
			} );
		} );

		function collect( page ) {
			var data = new FormData( form );
			var rooms = parseInt( data.get( 'rooms' ) || '1', 10 );
			var adults = parseInt( data.get( 'adults' ) || '2', 10 );
			var children = parseInt( data.get( 'children' ) || '0', 10 );

			var occupancy = [];
			for ( var i = 0; i < rooms; i++ ) {
				var kids = [];
				// Children are spread across rooms; ages default to 8 until the
				// per-child age picker is added, and the server clamps them.
				for ( var c = i; c < children; c += rooms ) {
					kids.push( 8 );
				}
				occupancy.push( { adults: adults, children: kids } );
			}

			return {
				category: data.get( 'category' ),
				destination: data.get( 'destination' ),
				check_in: data.get( 'check_in' ),
				check_out: data.get( 'check_out' ),
				occupancy: occupancy,
				currency: cfg.currency,
				sort: sortEl ? sortEl.value : 'price_asc',
				page: page || 1
			};
		}

		function setState( message, kind ) {
			if ( ! message ) {
				state.hidden = true;
				state.textContent = '';
				return;
			}
			state.hidden = false;
			state.className = 'gtc-state' + ( kind ? ' gtc-state--' + kind : '' );
			state.textContent = message;
		}

		function run( page ) {
			var query = collect( page );
			lastQuery = query;

			setState( cfg.i18n.searching, 'busy' );
			results.hidden = true;

			api( '/search', query ).then( function ( data ) {
				render( data );
			} ).catch( function ( error ) {
				setState( error.message || cfg.i18n.error, 'error' );
			} );
		}

		function render( data ) {
			setState( null );
			results.hidden = false;
			list.innerHTML = '';
			suppliersEl.innerHTML = '';

			titleEl.textContent = data.summary;

			var meta = data.group_count + ' ' +
				( data.group_count === 1 ? 'property' : 'properties' ) +
				' · ' + data.offer_count + ' rates compared';

			if ( data.merged_count > 0 ) {
				meta += ' · ' + data.merged_count + ' duplicate listing' +
					( data.merged_count === 1 ? '' : 's' ) + ' merged';
			}

			metaEl.textContent = meta;

			Object.keys( data.providers || {} ).forEach( function ( id ) {
				var info = data.providers[ id ];
				var chip = el( 'span', 'gtc-chip' + ( info.cached ? ' gtc-chip--cached' : '' ) );
				chip.appendChild( el( 'strong', null, providerLabel( id ) ) );
				chip.appendChild( el( 'span', null, info.offers + ' rates' +
					( info.cached ? ' (cached)' : ' · ' + info.ms + 'ms' ) ) );
				suppliersEl.appendChild( chip );
			} );

			Object.keys( data.failures || {} ).forEach( function ( id ) {
				var chip = el( 'span', 'gtc-chip gtc-chip--failed' );
				chip.appendChild( el( 'strong', null, providerLabel( id ) ) );
				chip.appendChild( el( 'span', null, data.failures[ id ] ) );
				suppliersEl.appendChild( chip );
			} );

			( data.notices || [] ).forEach( function ( notice ) {
				list.appendChild( el( 'div', 'gtc-notice', notice ) );
			} );

			if ( ! data.groups || ! data.groups.length ) {
				list.appendChild( el( 'div', 'gtc-notice gtc-notice--empty', cfg.i18n.noResults ) );
				pager.hidden = true;
				return;
			}

			data.groups.forEach( function ( group ) {
				list.appendChild( card( group, data ) );
			} );

			renderPager( data );
		}

		function card( group, data ) {
			var best = group.best;
			var price = best.price;
			var wrap = el( 'article', 'gtc-card' );

			var head = el( 'div', 'gtc-card__head' );

			var titleWrap = el( 'div', 'gtc-card__identity' );
			titleWrap.appendChild( el( 'h3', 'gtc-card__name', best.product.name ) );

			var line = el( 'p', 'gtc-card__line' );
			if ( best.product.star_rating ) {
				line.appendChild( el( 'span', 'gtc-card__stars', stars( best.product.star_rating ) ) );
			}
			if ( best.product.address ) {
				line.appendChild( el( 'span', 'gtc-card__address', best.product.address + ', ' + best.product.city ) );
			}
			titleWrap.appendChild( line );

			if ( best.product.review_score ) {
				var score = el( 'div', 'gtc-score' );
				score.appendChild( el( 'span', 'gtc-score__value', Number( best.product.review_score ).toFixed( 1 ) ) );
				score.appendChild( el( 'span', 'gtc-score__count', best.product.review_count + ' reviews' ) );
				titleWrap.appendChild( score );
			}

			head.appendChild( titleWrap );

			var priceBox = el( 'div', 'gtc-card__price' );

			// Only claim a saving when the two prices being compared are for
			// the same product. group.saving spans every rate on the card, so
			// it can be the gap between a room-only rate and a breakfast rate
			// at a different supplier — a real number that means nothing.
			if ( group.like_for_like > 0 ) {
				priceBox.appendChild( el( 'span', 'gtc-badge gtc-badge--save',
					cfg.i18n.youSave + ' ' + money( group.like_for_like, price.currency ) ) );
			}

			priceBox.appendChild( el( 'span', 'gtc-card__from', cfg.i18n.from ) );
			priceBox.appendChild( el( 'span', 'gtc-card__amount', money( price.total, price.currency ) ) );
			priceBox.appendChild( el( 'span', 'gtc-card__unit', cfg.i18n.total ) );

			if ( price.payable_at_property > 0 ) {
				priceBox.appendChild( el( 'span', 'gtc-card__extra',
					'+ ' + money( price.payable_at_property, price.currency ) + ' ' + cfg.i18n.atProperty ) );
			}

			head.appendChild( priceBox );
			wrap.appendChild( head );

			if ( best.product.amenities && best.product.amenities.length ) {
				var amenities = el( 'ul', 'gtc-amenities' );
				best.product.amenities.slice( 0, 5 ).forEach( function ( amenity ) {
					amenities.appendChild( el( 'li', null, amenity ) );
				} );
				wrap.appendChild( amenities );
			}

			wrap.appendChild( compare( group, data ) );

			return wrap;
		}

		function providerLabel( id ) {
			return ( cfg.providers && cfg.providers[ id ] ) || id;
		}

		/**
		 * The supplier price comparison.
		 *
		 * Rates are grouped into comparable classes (board + cancellation
		 * terms) and each class puts one row per supplier side by side, so the
		 * customer is comparing the same product rather than whichever mix of
		 * rate plans each supplier happened to return. Classes that only one
		 * supplier quotes are not a comparison, so they are folded away.
		 */
		function compare( group, data ) {
			var box = el( 'div', 'gtc-compare' );
			var rows = group.comparisons || [];

			var multi = rows.filter( function ( r ) { return r.supplier_count > 1; } );
			var single = rows.filter( function ( r ) { return r.supplier_count < 2; } );

			var head = el( 'p', 'gtc-compare__head' );
			head.textContent = multi.length
				? 'Price comparison · same property, ' + group.provider_count + ' ' + cfg.i18n.suppliers
				: 'Rates from ' + providerLabel( group.best.provider_id );
			box.appendChild( head );

			if ( multi.length ) {
				box.appendChild( el( 'p', 'gtc-compare__note',
					'Each block compares the same board basis and cancellation terms across suppliers.' ) );
			}

			multi.forEach( function ( row ) {
				box.appendChild( classBlock( row, data ) );
			} );

			if ( ! single.length ) {
				return box;
			}

			// Single-supplier classes still need to be reachable — they are
			// often the cheapest rate on the card — but they must not sit in
			// the comparison as if something had been compared.
			if ( ! multi.length ) {
				single.forEach( function ( row ) {
					box.appendChild( classBlock( row, data ) );
				} );
				return box;
			}

			var extra = el( 'div', 'gtc-compare__extra' );
			extra.hidden = true;

			single.forEach( function ( row ) {
				extra.appendChild( classBlock( row, data ) );
			} );

			var count = single.reduce( function ( n, r ) { return n + r.offers.length; }, 0 );

			var toggle = el( 'button', 'gtc-compare__toggle',
				'Show ' + count + ' more rate' + ( count === 1 ? '' : 's' ) + ' from one supplier only' );
			toggle.type = 'button';
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.addEventListener( 'click', function () {
				var open = extra.hidden;
				extra.hidden = ! open;
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				toggle.textContent = open
					? 'Hide these rates'
					: 'Show ' + count + ' more rate' + ( count === 1 ? '' : 's' ) + ' from one supplier only';
			} );

			box.appendChild( toggle );
			box.appendChild( extra );

			return box;
		}

		function classBlock( row, data ) {
			var block = el( 'div', 'gtc-class' + ( row.supplier_count > 1 ? ' is-compared' : '' ) );

			var head = el( 'div', 'gtc-class__head' );
			head.appendChild( el( 'span', 'gtc-class__label', row.label ) );

			if ( row.supplier_count > 1 ) {
				head.appendChild( el( 'span', 'gtc-class__count',
					row.supplier_count + ' ' + cfg.i18n.suppliers ) );
				if ( row.saving > 0 ) {
					head.appendChild( el( 'span', 'gtc-badge gtc-badge--save',
						cfg.i18n.youSave + ' ' + money( row.saving, row.offers[ 0 ].price.currency ) ) );
				}
			} else {
				head.appendChild( el( 'span', 'gtc-class__count', '1 supplier' ) );
			}

			block.appendChild( head );

			var cheapest = row.offers[ 0 ].price.grand_total;

			row.offers.forEach( function ( offer, index ) {
				block.appendChild( offerRow( offer, data, index === 0 && row.supplier_count > 1, cheapest ) );
			} );

			return block;
		}

		function offerRow( offer, data, isCheapest, cheapest ) {
			var line = el( 'div', 'gtc-offer' + ( isCheapest ? ' is-best' : '' ) );

			var left = el( 'div', 'gtc-offer__detail' );
			left.appendChild( el( 'span', 'gtc-offer__supplier', providerLabel( offer.provider_id ) ) );

			// The supplier's own room name, not a normalised one. Two suppliers
			// describe the same room differently and flattening that would hide
			// a real difference the customer may care about.
			left.appendChild( el( 'span', 'gtc-offer__room', offer.rate.name ) );

			if ( offer.rate.rooms_left > 0 && offer.rate.rooms_left <= 3 ) {
				left.appendChild( el( 'span', 'gtc-tag gtc-tag--urgent',
					'only ' + offer.rate.rooms_left + ' left' ) );
			}

			line.appendChild( left );

			var right = el( 'div', 'gtc-offer__action' );

			var priceCol = el( 'span', 'gtc-offer__pricecol' );
			priceCol.appendChild( el( 'span', 'gtc-offer__price', money( offer.price.total, offer.price.currency ) ) );

			var delta = offer.price.grand_total - cheapest;

			if ( isCheapest ) {
				priceCol.appendChild( el( 'span', 'gtc-offer__delta gtc-offer__delta--best', 'cheapest' ) );
			} else if ( delta > 0 ) {
				priceCol.appendChild( el( 'span', 'gtc-offer__delta',
					'+' + money( delta, offer.price.currency ) ) );
			}

			right.appendChild( priceCol );

			right.appendChild( action( offer, data, isCheapest ) );

			line.appendChild( right );

			return line;
		}

		/**
		 * Referral mode hands the customer to the supplier; merchant mode
		 * starts our own checkout. A real anchor is used for the referral, not
		 * a button with a click handler, so middle-click, "open in new tab" and
		 * copy-link all behave the way a link is expected to.
		 */
		function action( offer, data, isCheapest ) {
			var classes = 'gtc-btn gtc-btn--select' + ( isCheapest ? ' gtc-btn--select-best' : '' );

			if ( ! cfg.referral ) {
				var button = el( 'button', classes, cfg.i18n.select );
				button.type = 'button';
				button.addEventListener( 'click', function () {
					select( button, data.search_hash, offerKey( offer ) );
				} );
				return button;
			}

			if ( ! offer.has_deeplink ) {
				var dead = el( 'span', 'gtc-nolink', cfg.i18n.noLink );
				dead.title = cfg.i18n.noLinkHint;
				return dead;
			}

			var link = el( 'a', classes + ' gtc-btn--out',
				cfg.i18n.viewOn.replace( '%s', providerLabel( offer.provider_id ) ) );

			link.href = apiUrl( '/go', { h: data.search_hash, k: offerKey( offer ) } );
			link.target = '_blank';
			// sponsored: the site earns commission on this click, and search
			// engines are entitled to know that. noopener: the supplier's page
			// must not get a handle on ours.
			link.rel = 'nofollow sponsored noopener';

			return link;
		}

		function offerKey( offer ) {
			// Mirrors GTC_Offer::get_key(). The server re-derives everything
			// else from it, so this is the only identifier the browser holds.
			return offer.provider_id + ':' + md5( offer.offer_id + '|' + offer.product.property_id );
		}

		function select( button, searchHash, key ) {
			button.disabled = true;
			button.textContent = '…';

			api( '/select', { search_hash: searchHash, offer_key: key } ).then( function ( data ) {
				var reval = data.revalidation || {};

				if ( ! reval.bookable ) {
					button.disabled = false;
					button.textContent = cfg.i18n.select;
					setState( reval.message || cfg.i18n.error, 'error' );
					if ( lastQuery ) {
						run( 1 );
					}
					return;
				}

				window.location.href = cfg.checkout +
					( cfg.checkout.indexOf( '?' ) === -1 ? '?' : '&' ) +
					'ref=' + encodeURIComponent( data.reference ) +
					'&token=' + encodeURIComponent( data.token );
			} ).catch( function ( error ) {
				button.disabled = false;
				button.textContent = cfg.i18n.select;
				setState( error.message || cfg.i18n.error, 'error' );
			} );
		}

		function renderPager( data ) {
			pager.innerHTML = '';

			if ( data.pages <= 1 ) {
				pager.hidden = true;
				return;
			}

			pager.hidden = false;

			for ( var page = 1; page <= data.pages; page++ ) {
				( function ( target ) {
					var button = el( 'button', 'gtc-page' + ( target === data.page ? ' is-current' : '' ), target );
					button.type = 'button';
					button.addEventListener( 'click', function () {
						run( target );
						results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					} );
					pager.appendChild( button );
				} )( page );
			}
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			run( 1 );
		} );

		if ( sortEl ) {
			sortEl.addEventListener( 'change', function () {
				if ( lastQuery ) {
					run( 1 );
				}
			} );
		}
	}

	/* ------------------------------------------------------------ checkout */

	function initCheckout( root ) {
		var form = root.querySelector( '[data-gtc-checkout-form]' );
		if ( ! form ) {
			return;
		}

		var errorBox = root.querySelector( '[data-gtc-error]' );
		var payButton = root.querySelector( '[data-gtc-pay]' );
		var reference = root.getAttribute( 'data-reference' );
		var token = root.getAttribute( 'data-token' );
		var acceptedTotal = parseInt( root.getAttribute( 'data-total' ), 10 );

		function showError( message ) {
			errorBox.hidden = false;
			errorBox.textContent = message;
			errorBox.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		function showPriceChange( reval ) {
			var box = el( 'div', 'gtc-pricechange' );
			box.appendChild( el( 'strong', null, reval.message ) );
			box.appendChild( el( 'p', null,
				'New total: ' + money( reval.offer.price.total, reval.offer.price.currency ) ) );

			var accept = el( 'button', 'gtc-btn gtc-btn--primary', 'Accept the new price and continue' );
			accept.type = 'button';
			accept.addEventListener( 'click', function () {
				acceptedTotal = reval.offer.price.total;
				root.setAttribute( 'data-total', String( acceptedTotal ) );

				var totalCell = root.querySelector( '[data-gtc-total]' );
				if ( totalCell ) {
					totalCell.textContent = money( acceptedTotal, reval.offer.price.currency );
				}

				box.remove();
				errorBox.hidden = true;
				payButton.disabled = false;
				payButton.textContent = 'Pay ' + money( acceptedTotal, reval.offer.price.currency ) + ' and confirm';
			} );

			box.appendChild( accept );
			form.insertBefore( box, form.firstChild );
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			errorBox.hidden = true;
			payButton.disabled = true;
			var original = payButton.textContent;
			payButton.textContent = 'Confirming with the supplier…';

			var data = new FormData( form );

			api( '/book', {
				reference: reference,
				token: token,
				accepted_total: acceptedTotal,
				travellers: [ {
					title: data.get( 'title' ),
					first_name: data.get( 'first_name' ),
					last_name: data.get( 'last_name' )
				} ],
				contact: {
					email: data.get( 'email' ),
					phone: data.get( 'phone' ),
					country: data.get( 'country' ),
					special_requests: data.get( 'special_requests' )
				},
				payment: {
					card_number: data.get( 'card_number' ),
					card_expiry: data.get( 'card_expiry' ),
					card_cvc: data.get( 'card_cvc' )
				}
			} ).then( function ( response ) {
				if ( response.success ) {
					window.location.href = response.confirmation_url;
					return;
				}

				payButton.disabled = false;
				payButton.textContent = original;
				showError( response.error );

				if ( response.revalidation && response.revalidation.outcome === 'changed' ) {
					payButton.disabled = true;
					showPriceChange( response.revalidation );
				}
			} ).catch( function ( error ) {
				payButton.disabled = false;
				payButton.textContent = original;
				showError( error.message || cfg.i18n.error );
			} );
		} );
	}

	/* ----------------------------------------------------------------- md5 */

	/**
	 * Minimal MD5 so the offer key can be derived in the browser exactly as
	 * PHP derives it. It is an identifier, never a security boundary — the
	 * server independently re-derives the offer, its price and its supplier.
	 */
	function md5( input ) {
		function rotate( value, shift ) {
			return ( value << shift ) | ( value >>> ( 32 - shift ) );
		}
		function add( a, b ) {
			var low = ( a & 0xffff ) + ( b & 0xffff );
			return ( ( ( ( a >> 16 ) + ( b >> 16 ) + ( low >> 16 ) ) << 16 ) | ( low & 0xffff ) );
		}
		function cmn( q, a, b, x, s, t ) {
			return add( rotate( add( add( a, q ), add( x, t ) ), s ), b );
		}
		function ff( a, b, c, d, x, s, t ) { return cmn( ( b & c ) | ( ~b & d ), a, b, x, s, t ); }
		function gg( a, b, c, d, x, s, t ) { return cmn( ( b & d ) | ( c & ~d ), a, b, x, s, t ); }
		function hh( a, b, c, d, x, s, t ) { return cmn( b ^ c ^ d, a, b, x, s, t ); }
		function ii( a, b, c, d, x, s, t ) { return cmn( c ^ ( b | ~d ), a, b, x, s, t ); }

		function toBlocks( string ) {
			var bytes = unescape( encodeURIComponent( string ) );
			var blocks = [];
			for ( var i = 0; i < bytes.length * 8; i += 8 ) {
				blocks[ i >> 5 ] |= ( bytes.charCodeAt( i / 8 ) & 0xff ) << ( i % 32 );
			}
			blocks[ bytes.length * 8 >> 5 ] |= 0x80 << ( ( bytes.length * 8 ) % 32 );
			blocks[ ( ( ( bytes.length * 8 + 64 ) >>> 9 ) << 4 ) + 14 ] = bytes.length * 8;
			return blocks;
		}

		var x = toBlocks( input );
		var a = 1732584193, b = -271733879, c = -1732584194, d = 271733878;

		for ( var i = 0; i < x.length; i += 16 ) {
			var oa = a, ob = b, oc = c, od = d;

			a = ff( a, b, c, d, x[ i ], 7, -680876936 );
			d = ff( d, a, b, c, x[ i + 1 ], 12, -389564586 );
			c = ff( c, d, a, b, x[ i + 2 ], 17, 606105819 );
			b = ff( b, c, d, a, x[ i + 3 ], 22, -1044525330 );
			a = ff( a, b, c, d, x[ i + 4 ], 7, -176418897 );
			d = ff( d, a, b, c, x[ i + 5 ], 12, 1200080426 );
			c = ff( c, d, a, b, x[ i + 6 ], 17, -1473231341 );
			b = ff( b, c, d, a, x[ i + 7 ], 22, -45705983 );
			a = ff( a, b, c, d, x[ i + 8 ], 7, 1770035416 );
			d = ff( d, a, b, c, x[ i + 9 ], 12, -1958414417 );
			c = ff( c, d, a, b, x[ i + 10 ], 17, -42063 );
			b = ff( b, c, d, a, x[ i + 11 ], 22, -1990404162 );
			a = ff( a, b, c, d, x[ i + 12 ], 7, 1804603682 );
			d = ff( d, a, b, c, x[ i + 13 ], 12, -40341101 );
			c = ff( c, d, a, b, x[ i + 14 ], 17, -1502002290 );
			b = ff( b, c, d, a, x[ i + 15 ], 22, 1236535329 );

			a = gg( a, b, c, d, x[ i + 1 ], 5, -165796510 );
			d = gg( d, a, b, c, x[ i + 6 ], 9, -1069501632 );
			c = gg( c, d, a, b, x[ i + 11 ], 14, 643717713 );
			b = gg( b, c, d, a, x[ i ], 20, -373897302 );
			a = gg( a, b, c, d, x[ i + 5 ], 5, -701558691 );
			d = gg( d, a, b, c, x[ i + 10 ], 9, 38016083 );
			c = gg( c, d, a, b, x[ i + 15 ], 14, -660478335 );
			b = gg( b, c, d, a, x[ i + 4 ], 20, -405537848 );
			a = gg( a, b, c, d, x[ i + 9 ], 5, 568446438 );
			d = gg( d, a, b, c, x[ i + 14 ], 9, -1019803690 );
			c = gg( c, d, a, b, x[ i + 3 ], 14, -187363961 );
			b = gg( b, c, d, a, x[ i + 8 ], 20, 1163531501 );
			a = gg( a, b, c, d, x[ i + 13 ], 5, -1444681467 );
			d = gg( d, a, b, c, x[ i + 2 ], 9, -51403784 );
			c = gg( c, d, a, b, x[ i + 7 ], 14, 1735328473 );
			b = gg( b, c, d, a, x[ i + 12 ], 20, -1926607734 );

			a = hh( a, b, c, d, x[ i + 5 ], 4, -378558 );
			d = hh( d, a, b, c, x[ i + 8 ], 11, -2022574463 );
			c = hh( c, d, a, b, x[ i + 11 ], 16, 1839030562 );
			b = hh( b, c, d, a, x[ i + 14 ], 23, -35309556 );
			a = hh( a, b, c, d, x[ i + 1 ], 4, -1530992060 );
			d = hh( d, a, b, c, x[ i + 4 ], 11, 1272893353 );
			c = hh( c, d, a, b, x[ i + 7 ], 16, -155497632 );
			b = hh( b, c, d, a, x[ i + 10 ], 23, -1094730640 );
			a = hh( a, b, c, d, x[ i + 13 ], 4, 681279174 );
			d = hh( d, a, b, c, x[ i ], 11, -358537222 );
			c = hh( c, d, a, b, x[ i + 3 ], 16, -722521979 );
			b = hh( b, c, d, a, x[ i + 6 ], 23, 76029189 );
			a = hh( a, b, c, d, x[ i + 9 ], 4, -640364487 );
			d = hh( d, a, b, c, x[ i + 12 ], 11, -421815835 );
			c = hh( c, d, a, b, x[ i + 15 ], 16, 530742520 );
			b = hh( b, c, d, a, x[ i + 2 ], 23, -995338651 );

			a = ii( a, b, c, d, x[ i ], 6, -198630844 );
			d = ii( d, a, b, c, x[ i + 7 ], 10, 1126891415 );
			c = ii( c, d, a, b, x[ i + 14 ], 15, -1416354905 );
			b = ii( b, c, d, a, x[ i + 5 ], 21, -57434055 );
			a = ii( a, b, c, d, x[ i + 12 ], 6, 1700485571 );
			d = ii( d, a, b, c, x[ i + 3 ], 10, -1894986606 );
			c = ii( c, d, a, b, x[ i + 10 ], 15, -1051523 );
			b = ii( b, c, d, a, x[ i + 1 ], 21, -2054922799 );
			a = ii( a, b, c, d, x[ i + 8 ], 6, 1873313359 );
			d = ii( d, a, b, c, x[ i + 15 ], 10, -30611744 );
			c = ii( c, d, a, b, x[ i + 6 ], 15, -1560198380 );
			b = ii( b, c, d, a, x[ i + 13 ], 21, 1309151649 );
			a = ii( a, b, c, d, x[ i + 4 ], 6, -145523070 );
			d = ii( d, a, b, c, x[ i + 11 ], 10, -1120210379 );
			c = ii( c, d, a, b, x[ i + 2 ], 15, 718787259 );
			b = ii( b, c, d, a, x[ i + 9 ], 21, -343485551 );

			a = add( a, oa );
			b = add( b, ob );
			c = add( c, oc );
			d = add( d, od );
		}

		var hex = '0123456789abcdef';
		return [ a, b, c, d ].map( function ( word ) {
			var out = '';
			for ( var j = 0; j < 4; j++ ) {
				out += hex.charAt( ( word >> ( j * 8 + 4 ) ) & 0x0f ) +
					hex.charAt( ( word >> ( j * 8 ) ) & 0x0f );
			}
			return out;
		} ).join( '' );
	}

	/* ----------------------------------------------------------------- go */

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-gtc-app]' ), initSearch );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-gtc-checkout]' ), initCheckout );
	} );
} )();
