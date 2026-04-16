/**
 * Kivor Chat Agent — Product card component.
 *
 * Renders product cards (carousel or list) inside bot messages.
 * Supports configurable show/hide for price, link, add-to-cart, image.
 * Add-to-cart uses WooCommerce AJAX.
 *
 * @package KivorAgent
 * @since   1.0.0
 */
( function () {
	'use strict';

	document.addEventListener( 'kivor:ready', init );

	var sa, cfg;

	function init() {
		sa  = window.kivorAgent;
		cfg = sa.cfg;

		// Listen for product render events from the chat module.
		document.addEventListener( 'kivor:render-products', function ( e ) {
			var detail = e.detail || {};
			if ( detail.products && detail.container ) {
				renderProducts( detail.products, detail.container );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/*  Render product cards                                              */
	/* ------------------------------------------------------------------ */

	function renderProducts( products, msgEl ) {
		if ( ! products || ! products.length ) return;

		var appearance = cfg.appearance || {};
		var layout     = appearance.product_card_layout || 'carousel';
		var showImage  = appearance.product_card_show_image !== false;
		var showPrice  = appearance.product_card_show_price !== false;
		var showLink   = appearance.product_card_show_link !== false;
		var showCart   = appearance.product_card_show_add_to_cart !== false;

		var containerClass = 'kivor-chat-agent-product-cards kivor-chat-agent-product-cards--' + layout;
		var container = sa.el( 'div', { className: containerClass } );

		products.forEach( function ( product ) {
			var card = buildCard( product, {
				showImage: showImage,
				showPrice: showPrice,
				showLink:  showLink,
				showCart:  showCart,
			} );
			container.appendChild( card );
		} );

		// Append after the text bubble inside the message.
		var bubble = sa.qs( '.kivor-chat-agent-msg__bubble', msgEl );
		if ( bubble ) {
			bubble.appendChild( container );
		} else {
			msgEl.appendChild( container );
		}

		// If carousel, enable horizontal scroll with mouse drag.
		if ( layout === 'carousel' ) {
			enableDragScroll( container );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Build a single product card                                       */
	/* ------------------------------------------------------------------ */

	function buildCard( product, opts ) {
		var card = sa.el( 'div', { className: 'kivor-chat-agent-product-card' } );

		// Image.
		if ( opts.showImage && product.image ) {
			var imgLink = sa.el( 'a', {
				className: 'kivor-chat-agent-product-card__img-link',
				href: product.url || '#',
				target: '_blank',
				rel: 'noopener noreferrer',
			}, [
				sa.el( 'img', {
					className: 'kivor-chat-agent-product-card__img',
					src: product.image,
					alt: product.title || '',
					loading: 'lazy',
				} ),
			] );
			imgLink.addEventListener( 'click', function () {
				trackEvent( product.id, 'clicked', product.url || '' );
			} );
			card.appendChild( imgLink );
		}

		var body = sa.el( 'div', { className: 'kivor-chat-agent-product-card__body' } );

		// Title.
		var title = sa.el( 'div', {
			className: 'kivor-chat-agent-product-card__title',
			textContent: product.title || 'Product',
		} );
		body.appendChild( title );

		// Price.
		if ( opts.showPrice && product.price_html ) {
			body.appendChild(
				sa.el( 'div', {
					className: 'kivor-chat-agent-product-card__price',
					innerHTML: sanitizePriceHtml( product.price_html ),
				} )
			);
		} else if ( opts.showPrice && product.price ) {
			body.appendChild(
				sa.el( 'div', {
					className: 'kivor-chat-agent-product-card__price',
					textContent: product.price,
				} )
			);
		}

		// Short description.
		if ( product.short_description ) {
			body.appendChild(
				sa.el( 'div', {
					className: 'kivor-chat-agent-product-card__desc',
					textContent: product.short_description.length > 80
						? product.short_description.slice( 0, 80 ) + '...'
						: product.short_description,
				} )
			);
		}

		// Actions row.
		var actions = sa.el( 'div', { className: 'kivor-chat-agent-product-card__actions' } );

		if ( opts.showLink && product.url ) {
			var viewLink = sa.el( 'a', {
				className: 'kivor-chat-agent-product-card__link',
				href: product.url,
				target: '_blank',
				rel: 'noopener noreferrer',
				textContent: 'View',
			} );
			viewLink.addEventListener( 'click', function () {
				trackEvent( product.id, 'clicked', product.url );
			} );
			actions.appendChild( viewLink );
		}

		if ( opts.showCart && product.id && product.in_stock ) {
			var cartBtn = sa.el( 'button', {
				className: 'kivor-chat-agent-product-card__cart',
				textContent: 'Add to cart',
				'data-product-id': product.id,
				onClick: function () { addToCart( product.id, cartBtn ); },
			} );
			actions.appendChild( cartBtn );
		}

		body.appendChild( actions );
		card.appendChild( body );

		return card;
	}

	/* ------------------------------------------------------------------ */
	/*  Add to cart (WooCommerce AJAX)                                    */
	/* ------------------------------------------------------------------ */

	function addToCart( productId, btn ) {
		if ( btn.disabled ) return;
		btn.disabled    = true;
		btn.textContent = 'Adding...';

		var formData = new FormData();
		formData.append( 'product_id', productId );
		formData.append( 'quantity', '1' );

		// Include WooCommerce security nonce if available (VULN-007 fix).
		var wcParams = window.wc_add_to_cart_params;
		if ( wcParams && wcParams.wc_ajax_url ) {
			// WC provides its own nonce via localized script data.
			formData.append( 'security', wcParams.cart_nonce || '' );
		}
		if ( cfg.nonce ) {
			formData.append( '_wpnonce', cfg.nonce );
		}

		// Use WooCommerce's AJAX add-to-cart endpoint.
		var ajaxUrl = cfg.wc_ajax_url
			? cfg.wc_ajax_url.replace( '%%endpoint%%', 'add_to_cart' )
			: cfg.ajax_url;

		if ( ! ajaxUrl ) {
			// Fallback: construct from rest_url.
			ajaxUrl = ( cfg.site_url || '' ) + '/?wc-ajax=add_to_cart';
		}

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data.error ) {
					btn.textContent = 'Error';
					setTimeout( function () {
						btn.textContent = 'Add to cart';
						btn.disabled = false;
					}, 2000 );
					return;
				}
				btn.textContent = 'Added!';
				trackEvent( productId, 'added_to_cart', window.location.href || '' );
				btn.classList.add( 'kivor-chat-agent-product-card__cart--added' );

				// Update WC cart fragments if available.
				if ( window.jQuery ) {
					jQuery( document.body ).trigger( 'wc_fragment_refresh' );
				}

				setTimeout( function () {
					btn.textContent = 'Add to cart';
					btn.disabled = false;
					btn.classList.remove( 'kivor-chat-agent-product-card__cart--added' );
				}, 3000 );
			} )
			.catch( function () {
				btn.textContent = 'Error';
				setTimeout( function () {
					btn.textContent = 'Add to cart';
					btn.disabled = false;
				}, 2000 );
			} );
	}

	/**
	 * Track analytics event for product interactions.
	 *
	 * @param {number} productId Product ID.
	 * @param {string} eventType Event type.
	 * @param {string} sourceUrl Source URL.
	 */
	function trackEvent( productId, eventType, sourceUrl ) {
		if ( ! cfg || ! cfg.rest_url ) return;
		if ( ! productId || ! eventType ) return;
		if ( [ 'clicked', 'added_to_cart', 'recommended', 'purchased' ].indexOf( eventType ) === -1 ) return;

		var sessionId = '';
		if ( window.kivorAgentChat && typeof window.kivorAgentChat.getSessionId === 'function' ) {
			sessionId = window.kivorAgentChat.getSessionId() || '';
		}

		if ( ! sessionId ) {
			return;
		}

		fetch( cfg.rest_url + 'kivor-chat-agent/v1/analytics/event', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( {
				session_id: sessionId,
				product_id: parseInt( productId, 10 ) || 0,
				event_type: eventType,
				source_url: sourceUrl || '',
			} ),
		} ).catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/*  Sanitize price HTML (defense-in-depth for VULN-006)               */
	/* ------------------------------------------------------------------ */

	/**
	 * Allowlist-based HTML sanitizer for WooCommerce price markup.
	 *
	 * Only permits the tags and attributes that WooCommerce price_html
	 * actually uses. Everything else is stripped.
	 *
	 * @param {string} html Raw price HTML from REST API.
	 * @return {string} Sanitized HTML safe for innerHTML.
	 */
	function sanitizePriceHtml( html ) {
		// Allowed tags and their allowed attributes.
		var allowlist = {
			span:   [ 'class' ],
			del:    [],
			ins:    [],
			bdi:    [],
			strong: [],
		};

		var doc = new DOMParser().parseFromString( html, 'text/html' );
		return sanitizeNode( doc.body, allowlist );
	}

	function sanitizeNode( node, allowlist ) {
		var out = '';

		for ( var i = 0; i < node.childNodes.length; i++ ) {
			var child = node.childNodes[ i ];

			if ( child.nodeType === Node.TEXT_NODE ) {
				out += escapeHtmlChars( child.textContent );
				continue;
			}

			if ( child.nodeType !== Node.ELEMENT_NODE ) {
				continue;
			}

			var tag = child.tagName.toLowerCase();
			if ( ! allowlist.hasOwnProperty( tag ) ) {
				// Tag not allowed — include its text content only (stripped).
				out += sanitizeNode( child, allowlist );
				continue;
			}

			var allowedAttrs = allowlist[ tag ];
			var attrStr = '';
			for ( var a = 0; a < allowedAttrs.length; a++ ) {
				var attrName = allowedAttrs[ a ];
				var attrVal  = child.getAttribute( attrName );
				if ( attrVal !== null ) {
					attrStr += ' ' + attrName + '="' + escapeHtmlChars( attrVal ) + '"';
				}
			}

			out += '<' + tag + attrStr + '>' + sanitizeNode( child, allowlist ) + '</' + tag + '>';
		}

		return out;
	}

	function escapeHtmlChars( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/* ------------------------------------------------------------------ */
	/*  Carousel drag-to-scroll                                           */
	/* ------------------------------------------------------------------ */

	function enableDragScroll( container ) {
		var isDown = false;
		var startX = 0;
		var scrollLeft = 0;

		container.addEventListener( 'mousedown', function ( e ) {
			isDown    = true;
			startX    = e.pageX - container.offsetLeft;
			scrollLeft = container.scrollLeft;
			container.classList.add( 'kivor-chat-agent-product-cards--dragging' );
		} );

		container.addEventListener( 'mouseleave', function () {
			isDown = false;
			container.classList.remove( 'kivor-chat-agent-product-cards--dragging' );
		} );

		container.addEventListener( 'mouseup', function () {
			isDown = false;
			container.classList.remove( 'kivor-chat-agent-product-cards--dragging' );
		} );

		container.addEventListener( 'mousemove', function ( e ) {
			if ( ! isDown ) return;
			e.preventDefault();
			var x    = e.pageX - container.offsetLeft;
			var walk = ( x - startX ) * 2;
			container.scrollLeft = scrollLeft - walk;
		} );
	}
} )();
