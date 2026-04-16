/**
 * Kivor Chat Agent — GDPR consent UI.
 *
 * Displays a consent banner inside the chat body before the user can
 * send their first message. Stores consent in sessionStorage and
 * notifies other modules via the `kivor:consent-granted` event.
 *
 * @package KivorAgent
 * @since   1.0.0
 */
( function () {
	'use strict';

	document.addEventListener( 'kivor:ready', init );

	var sa, cfg, gdpr;
	var bannerEl = null;

	function init() {
		sa   = window.kivorAgent;
		cfg  = sa.cfg;
		gdpr = cfg.gdpr || {};

		if ( ! gdpr.enabled || ! gdpr.consent_required ) return;

		// If user already consented this session, nothing to do.
		if ( hasConsent() ) return;

		// Listen for explicit show-consent requests from chat module.
		document.addEventListener( 'kivor:show-consent', showBanner );

		// Show immediately on init (before first interaction).
		showBanner();
	}

	/* ------------------------------------------------------------------ */
	/*  Build banner                                                      */
	/* ------------------------------------------------------------------ */

	function showBanner() {
		// Don't show if already visible or already consented.
		if ( bannerEl || hasConsent() ) return;

		var message = gdpr.consent_message || 'You consent to the monitoring and recording of this chat for service improvement purposes.';

		bannerEl = sa.el( 'div', { className: 'kivor-chat-agent-consent' } );

		// Message text.
		var messageEl = sa.el( 'div', { className: 'kivor-chat-agent-consent__message' } );
		var dismissBtn = sa.el( 'button', {
			className: 'kivor-chat-agent-consent__dismiss',
			type: 'button',
			'aria-label': 'Dismiss consent notice',
			textContent: '×',
			onClick: onDismiss,
		} );

		// Build text with optional privacy link.
		if ( gdpr.show_privacy_link && gdpr.privacy_url ) {
			// Split the consent message and add a clickable link.
			var textNode = document.createTextNode( message + ' ' );
			messageEl.appendChild( textNode );
			messageEl.appendChild(
				sa.el( 'a', {
					className: 'kivor-chat-agent-consent__privacy-link',
					href: gdpr.privacy_url,
					target: '_blank',
					rel: 'noopener noreferrer',
					textContent: 'Privacy Policy',
				} )
			);
		} else {
			messageEl.textContent = message;
		}

		bannerEl.appendChild( dismissBtn );
		bannerEl.appendChild( messageEl );

		// Append into scrollable chat content.
		var messagesWrap = sa.qs( '.kivor-chat-agent-messages', sa.bodyChat );
		if ( messagesWrap ) {
			messagesWrap.appendChild( bannerEl );
			sa.bodyChat.scrollTop = sa.bodyChat.scrollHeight;
		} else {
			sa.bodyChat.appendChild( bannerEl );
		}

		storeConsent( true );
		document.dispatchEvent( new CustomEvent( 'kivor:consent-granted' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Handlers                                                          */
	/* ------------------------------------------------------------------ */

	function onDismiss() {
		removeBanner();
	}

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                           */
	/* ------------------------------------------------------------------ */

	function removeBanner() {
		if ( bannerEl && bannerEl.parentNode ) {
			bannerEl.parentNode.removeChild( bannerEl );
		}
		bannerEl = null;
	}

	function hasConsent() {
		try {
			if ( window.localStorage && window.localStorage.getItem( 'kivor_chat_agent_consent' ) === '1' ) {
				return true;
			}
		} catch ( e ) {}

		try {
			return window.sessionStorage && window.sessionStorage.getItem( 'kivor_chat_agent_consent' ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function storeConsent( granted ) {
		var value = granted ? '1' : '0';

		try {
			if ( window.localStorage ) {
				window.localStorage.setItem( 'kivor_chat_agent_consent', value );
			}
		} catch ( e ) {}

		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( 'kivor_chat_agent_consent', value );
			}
		} catch ( e ) {}
	}

} )();
