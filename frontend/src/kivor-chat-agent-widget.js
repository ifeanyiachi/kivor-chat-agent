/**
 * Kivor Chat Agent — Main widget controller.
 *
 * Renders the floating chat button and chat window shell.
 * Delegates to sub-modules for chat, WhatsApp, consent, and product cards.
 *
 * @package KivorAgent
 * @since   1.0.0
 */
( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Shorthand DOM helpers.
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'className' ) {
					node.className = attrs[ k ];
				} else if ( k === 'innerHTML' ) {
					node.innerHTML = attrs[ k ];
				} else if ( k === 'textContent' ) {
					node.textContent = attrs[ k ];
				} else if ( k.indexOf( 'on' ) === 0 ) {
					node.addEventListener( k.slice( 2 ).toLowerCase(), attrs[ k ] );
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
		}
		if ( children ) {
			( Array.isArray( children ) ? children : [ children ] ).forEach( function ( c ) {
				if ( typeof c === 'string' ) {
					node.appendChild( document.createTextNode( c ) );
				} else if ( c ) {
					node.appendChild( c );
				}
			} );
		}
		return node;
	}

	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	/* ------------------------------------------------------------------ */
	/*  Config                                                            */
	/* ------------------------------------------------------------------ */

	var cfg = window.kivorChatAgentConfig || {};

	/* ------------------------------------------------------------------ */
	/*  SVG Icons                                                         */
	/* ------------------------------------------------------------------ */

	var ICON_CHAT =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24">' +
		'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' +
		'</svg>';

	var ICON_CLOSE =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">' +
		'<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' +
		'</svg>';

	var ICON_SEND =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">' +
		'<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>' +
		'</svg>';

	var ICON_MIC =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">' +
		'<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>' +
		'<path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>' +
		'</svg>';


	var ICON_WHATSAPP =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">' +
		'<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.019-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>' +
		'</svg>';

	var ICON_PHONE =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22">' +
		'<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.08 4.18 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.77.62 2.6a2 2 0 0 1-.45 2.11L8 9.94a16 16 0 0 0 6.06 6.06l1.51-1.23a2 2 0 0 1 2.11-.45c.83.29 1.7.5 2.6.62A2 2 0 0 1 22 16.92z"/>' +
		'</svg>';

	function getTriggerClosedIcon() {
		if ( cfg.widget_logo ) {
			return '<img class="kivor-chat-agent-trigger__logo" src="' + cfg.widget_logo + '" alt="Widget logo">';
		}

		return ICON_CHAT;
	}

	function applyAppearanceVars( container ) {
		if ( ! container || ! cfg.appearance ) return;

		var mapping = {
			'widget_primary_color': '--kivor-chat-agent-primary',
			'widget_primary_hover_color': '--kivor-chat-agent-primary-hover',
			'widget_primary_text_color': '--kivor-chat-agent-primary-text',
			'widget_background_color': '--kivor-chat-agent-bg',
			'widget_background_alt_color': '--kivor-chat-agent-bg-secondary',
			'widget_text_color': '--kivor-chat-agent-text',
			'widget_text_muted_color': '--kivor-chat-agent-text-muted',
			'widget_border_color': '--kivor-chat-agent-border',
			'widget_user_bubble_color': '--kivor-chat-agent-user-bubble',
			'widget_user_text_color': '--kivor-chat-agent-user-text',
			'widget_bot_bubble_color': '--kivor-chat-agent-bot-bubble',
			'widget_bot_text_color': '--kivor-chat-agent-bot-text',
			'widget_tab_background_color': '--kivor-chat-agent-tab-bg',
			'widget_tab_text_color': '--kivor-chat-agent-tab-text',
			'widget_tab_active_color': '--kivor-chat-agent-tab-active',
			'widget_tab_active_text_color': '--kivor-chat-agent-tab-active-text',
		};

		Object.keys( mapping ).forEach( function ( key ) {
			var val = cfg.appearance[ key ];
			if ( typeof val === 'string' && /^#[0-9A-Fa-f]{6}$/.test( val ) ) {
				container.style.setProperty( mapping[ key ], val );
			}
		} );
	}

	function supportsVoiceInput() {
		if ( ! cfg.voice || ! cfg.voice.enabled || ! cfg.voice.input_enabled ) {
			return false;
		}

		if ( ( cfg.voice.stt_provider || 'webspeech' ) === 'webspeech' ) {
			return !! ( window.SpeechRecognition || window.webkitSpeechRecognition );
		}

		return !! ( navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder );
	}


	/* ------------------------------------------------------------------ */
	/*  State                                                             */
	/* ------------------------------------------------------------------ */

	var state = {
		open: false,
		activeTab: 'chat', // 'chat' | 'whatsapp'
	};

	/* ------------------------------------------------------------------ */
	/*  Build DOM                                                         */
	/* ------------------------------------------------------------------ */

	var root, trigger, win, tabNav, bodyChat, bodyWhatsapp, bodyFormTab, footerChat, footerWhatsapp;

	function buildWidget() {
		var position = cfg.chat_position || 'bottom-right';
		var botName  = cfg.bot_name || 'Kivor';
		var chatTitle = ( cfg.chatbot_title || '' ).toString().trim();
		var chatDescription = ( cfg.chatbot_description || '' ).toString().trim();
		var useInAppIntro = !!cfg.use_in_app_intro;
		var whatsappEnabled = cfg.whatsapp && cfg.whatsapp.enabled;
		var formsEnabled = cfg.forms && cfg.forms.enabled && cfg.forms.tab_form && cfg.forms.tab_form.form_data;
		var activeTabCount = 1 + ( whatsappEnabled ? 1 : 0 ) + ( formsEnabled ? 1 : 0 );

		// Root container.
		root = el( 'div', {
			className: 'kivor-chat-agent kivor-chat-agent--' + position,
			'data-position': position,
		} );
		applyAppearanceVars( root );

		// Floating trigger button.
		var phoneCfg = cfg.phone_call || {};
		if ( phoneCfg.enabled && phoneCfg.number ) {
			root.appendChild(
				el( 'a', {
					className: 'kivor-chat-agent-call-trigger' + ( phoneCfg.mobile_only ? ' kivor-chat-agent-call-trigger--mobile' : '' ),
					href: 'tel:' + phoneCfg.number,
					'aria-label': ( phoneCfg.button_label || 'Call Support' ),
					title: ( phoneCfg.button_label || 'Call Support' ),
					innerHTML: ICON_PHONE,
				} )
			);
		}

		// Floating trigger button.
		trigger = el( 'button', {
			className: 'kivor-chat-agent-trigger',
			'aria-label': 'Open chat',
			innerHTML: getTriggerClosedIcon(),
			onClick: toggleWidget,
		} );

		if ( cfg.widget_logo ) {
			trigger.classList.add( 'kivor-chat-agent-trigger--has-logo' );
		}

		// Chat window.
		win = el( 'div', { className: 'kivor-chat-agent-window' } );

		// ── Header ────────────────────────────────────────────────
		var headerLeft = el( 'div', { className: 'kivor-chat-agent-header__left' } );

		// Avatar.
		if ( cfg.bot_avatar ) {
			headerLeft.appendChild(
				el( 'img', {
					className: 'kivor-chat-agent-header__avatar',
					src: cfg.bot_avatar,
					alt: botName,
				} )
			);
		}

		headerLeft.appendChild(
			el( 'span', { className: 'kivor-chat-agent-header__name', textContent: botName } )
		);

		var closeBtn = el( 'button', {
			className: 'kivor-chat-agent-close',
			'aria-label': 'Close chat',
			innerHTML: ICON_CLOSE,
			onClick: toggleWidget,
		} );

		var headerActions = el( 'div', { className: 'kivor-chat-agent-header__actions' } );
		if ( cfg.show_end_session_button ) {
			headerActions.appendChild(
				el( 'button', {
					className: 'kivor-chat-agent-end-session',
					type: 'button',
					textContent: 'End session',
					'aria-label': 'End current chat session',
					onClick: function () {
						document.dispatchEvent( new CustomEvent( 'kivor:end-session' ) );
					},
				} )
			);
		}
		headerActions.appendChild( closeBtn );

		var header = el( 'div', { className: 'kivor-chat-agent-header' }, [
			headerLeft,
			headerActions,
		] );

		win.appendChild( header );

		// ── Body: Chat tab ────────────────────────────────────────
		bodyChat = el( 'div', {
			className: 'kivor-chat-agent-body kivor-chat-agent-body--chat',
		} );

		if ( useInAppIntro && ( chatTitle || chatDescription ) ) {
			var introChildren = [];
			if ( chatTitle ) {
				introChildren.push( el( 'h3', { className: 'kivor-chat-agent-intro__title', textContent: chatTitle } ) );
			}
			if ( chatDescription ) {
				introChildren.push( el( 'p', { className: 'kivor-chat-agent-intro__description', textContent: chatDescription } ) );
			}
			bodyChat.appendChild( el( 'div', { className: 'kivor-chat-agent-intro' }, introChildren ) );
		}

		win.appendChild( bodyChat );

		// ── Body: WhatsApp tab ────────────────────────────────────
		if ( whatsappEnabled ) {
			bodyWhatsapp = el( 'div', {
				className: 'kivor-chat-agent-body kivor-chat-agent-body--whatsapp kivor-chat-agent-body--hidden',
			} );
			win.appendChild( bodyWhatsapp );
		}

		if ( formsEnabled ) {
			bodyFormTab = el( 'div', {
				className: 'kivor-chat-agent-body kivor-chat-agent-body--form-tab kivor-chat-agent-body--hidden',
			} );
			win.appendChild( bodyFormTab );
		}

		// Bottom tabs.
		var chatTabLabel = ( cfg.chat_tab_label || 'Chatbot' ).toString().trim() || 'Chatbot';
		tabNav = el( 'div', { className: 'kivor-chat-agent-bottom-tabs' }, [
			el( 'button', {
				className: 'kivor-chat-agent-bottom-tab kivor-chat-agent-bottom-tab--active',
				'data-tab': 'chat',
				textContent: chatTabLabel,
				type: 'button',
				onClick: function () { switchTab( 'chat' ); },
			} ),
		] );

		if ( whatsappEnabled ) {
			tabNav.appendChild(
				el( 'button', {
					className: 'kivor-chat-agent-bottom-tab',
					'data-tab': 'whatsapp',
					type: 'button',
					onClick: function () { switchTab( 'whatsapp' ); },
				}, [
					el( 'span', { innerHTML: ICON_WHATSAPP, className: 'kivor-chat-agent-bottom-tab__icon' } ),
					document.createTextNode( ' WhatsApp' ),
				] )
			);
		}

		if ( formsEnabled ) {
			tabNav.appendChild(
				el( 'button', {
					className: 'kivor-chat-agent-bottom-tab',
					'data-tab': 'form',
					type: 'button',
					onClick: function () { switchTab( 'form' ); },
					textContent: ( cfg.forms.tab_label || 'Form' ).toString().trim() || 'Form',
				} )
			);
		}

		// ── Footer: Chat tab ──────────────────────────────────────
		var chatInput = el( 'input', {
			className: 'kivor-chat-agent-input',
			type: 'text',
			placeholder: 'Type a message...',
			'aria-label': 'Chat message',
		} );

		var chatSend = el( 'button', {
			className: 'kivor-chat-agent-send',
			'aria-label': 'Send message',
			innerHTML: ICON_SEND,
			type: 'button',
		} );

		var chatFooterChildren = [ chatInput ];
		if ( cfg.voice && cfg.voice.enabled ) {
			if ( supportsVoiceInput() ) {
				chatFooterChildren.push(
					el( 'button', {
						className: 'kivor-chat-agent-mic',
						'aria-label': 'Voice input',
						innerHTML: ICON_MIC,
						type: 'button',
					} )
				);
			}

		}
		chatFooterChildren.push( chatSend );

		footerChat = el( 'div', { className: 'kivor-chat-agent-footer kivor-chat-agent-footer--chat' }, chatFooterChildren );
		win.appendChild( footerChat );

		// ── Footer: WhatsApp tab ──────────────────────────────────
		if ( whatsappEnabled ) {
			var waInput = el( 'input', {
				className: 'kivor-chat-agent-input kivor-chat-agent-input--whatsapp',
				type: 'text',
				placeholder: cfg.whatsapp.prefilled_message || 'Type a message...',
				'aria-label': 'WhatsApp message',
			} );

			var waSend = el( 'button', {
				className: 'kivor-chat-agent-send kivor-chat-agent-send--whatsapp',
				'aria-label': 'Send via WhatsApp',
				innerHTML: ICON_SEND,
				type: 'button',
			} );

			footerWhatsapp = el( 'div', {
				className: 'kivor-chat-agent-footer kivor-chat-agent-footer--whatsapp kivor-chat-agent-footer--hidden',
			}, [ waInput, waSend ] );
			win.appendChild( footerWhatsapp );
		}

		if ( activeTabCount > 1 ) {
			win.appendChild( tabNav );
		}

		// Assemble.
		root.appendChild( trigger );
		root.appendChild( win );
		document.body.appendChild( root );

		// Expose references for sub-modules.
		window.kivorAgent = {
			root: root,
			trigger: trigger,
			win: win,
			bodyChat: bodyChat,
			bodyWhatsapp: bodyWhatsapp,
			bodyFormTab: bodyFormTab,
			footerChat: footerChat,
			footerWhatsapp: footerWhatsapp,
			cfg: cfg,
			state: state,
			el: el,
			qs: qs,
			ICON_SEND: ICON_SEND,
			setChatInputBlocked: function ( blocked, placeholder ) {
				if ( ! footerChat ) return;
				var input = qs( '.kivor-chat-agent-input', footerChat );
				var send = qs( '.kivor-chat-agent-send', footerChat );

				if ( input ) {
					input.disabled = !! blocked;
					if ( placeholder ) {
						input.placeholder = placeholder;
					}
				}

				if ( send ) {
					send.disabled = !! blocked;
				}
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/*  Open / Close                                                      */
	/* ------------------------------------------------------------------ */

	function toggleWidget() {
		state.open = ! state.open;
		root.classList.toggle( 'kivor-chat-agent--open', state.open );
		trigger.setAttribute( 'aria-expanded', state.open ? 'true' : 'false' );
		trigger.innerHTML = state.open ? ICON_CLOSE : getTriggerClosedIcon();

		if ( state.open ) {
			// Focus the active input.
			var input = qs( '.kivor-chat-agent-input', state.activeTab === 'chat' ? footerChat : footerWhatsapp );
			if ( input ) {
				setTimeout( function () { input.focus(); }, 100 );
			}

			// Fire open event for sub-modules.
			document.dispatchEvent( new CustomEvent( 'kivor:open' ) );
		} else {
			document.dispatchEvent( new CustomEvent( 'kivor:close' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Tab switching                                                     */
	/* ------------------------------------------------------------------ */

	function switchTab( tab ) {
		if ( tab === state.activeTab ) return;
		state.activeTab = tab;

		// Update tab buttons.
		if ( tabNav ) {
			var buttons = tabNav.querySelectorAll( '.kivor-chat-agent-bottom-tab' );
			buttons.forEach( function ( btn ) {
				btn.classList.toggle( 'kivor-chat-agent-bottom-tab--active', btn.getAttribute( 'data-tab' ) === tab );
			} );
		}

		// Toggle body panels.
		bodyChat.classList.toggle( 'kivor-chat-agent-body--hidden', tab !== 'chat' );
		if ( bodyWhatsapp ) {
			bodyWhatsapp.classList.toggle( 'kivor-chat-agent-body--hidden', tab !== 'whatsapp' );
		}
		if ( bodyFormTab ) {
			bodyFormTab.classList.toggle( 'kivor-chat-agent-body--hidden', tab !== 'form' );
		}

		// Toggle footers.
		footerChat.classList.toggle( 'kivor-chat-agent-footer--hidden', tab !== 'chat' );
		if ( footerWhatsapp ) {
			footerWhatsapp.classList.toggle( 'kivor-chat-agent-footer--hidden', tab !== 'whatsapp' );
		}

		// Focus input.
		var footer = tab === 'chat' ? footerChat : footerWhatsapp;
		if ( footer ) {
			var input = qs( '.kivor-chat-agent-input', footer );
			if ( input ) input.focus();
		}

		document.dispatchEvent( new CustomEvent( 'kivor:tab-change', {
			detail: { tab: tab },
		} ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Init                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		if ( ! cfg.rest_url ) {
			// Missing configuration — likely not properly enqueued.
			return;
		}
		buildWidget();

		// Fire ready event for sub-modules.
		document.dispatchEvent( new CustomEvent( 'kivor:ready' ) );
	}

	// Wait for DOM.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
