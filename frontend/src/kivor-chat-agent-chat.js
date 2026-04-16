/**
 * Kivor Chat Agent — AI Chat tab.
 *
 * Handles message sending/receiving via AJAX,
 * and typing indicators with conversation history.
 *
 * @package KivorAgent
 * @since   1.0.0
 */
( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/*  Wait for widget shell                                             */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'kivor:ready', init );

	var sa;   // kivorAgent reference
	var cfg;  // config
	var els;  // DOM references

	var sending   = false;
	var sessionId = '';
	var history   = [];
	var currentAbortController = null;
	var activeTypingEl = null;
	var inputLockedByForm = false;

	/* ------------------------------------------------------------------ */
	/*  Initialise                                                        */
	/* ------------------------------------------------------------------ */

	function init() {
		sa  = window.kivorAgent;
		cfg = sa.cfg;

		sessionId = loadSession();

		buildChatBody();
		bindEvents();
		showInitialGreeting();

		// Show consent first if needed.
		if ( needsConsent() ) {
			document.dispatchEvent( new CustomEvent( 'kivor:show-consent' ) );
		}

		window.kivorAgentChat = {
			sendText: function ( text ) {
				if ( typeof text !== 'string' ) return;
				els.input.value = text;
				onSend();
			},
			setInputText: function ( text ) {
				els.input.value = typeof text === 'string' ? text : '';
			},
			cancelResponse: function () {
				return cancelCurrentResponse();
			},
			isSending: function () {
				return sending;
			},
			getInputElement: function () {
				return els.input;
			},
			getSessionId: function () {
				return sessionId;
			},
			endSession: function () {
				resetConversation();
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/*  Build inner DOM                                                   */
	/* ------------------------------------------------------------------ */

	function buildChatBody() {
		var messagesWrap = sa.el( 'div', { className: 'kivor-chat-agent-messages' } );
		sa.bodyChat.appendChild( messagesWrap );

		els = {
			messages:    messagesWrap,			input:       sa.qs( '.kivor-chat-agent-input', sa.footerChat ),
			sendBtn:     sa.qs( '.kivor-chat-agent-send', sa.footerChat ),
		};
	}

	/* ------------------------------------------------------------------ */
	/*  Events                                                            */
	/* ------------------------------------------------------------------ */

	function bindEvents() {
		els.sendBtn.addEventListener( 'click', onSend );

		els.input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				onSend();
			}
		} );


		document.addEventListener( 'kivor:form-input-blocked', function () {
			inputLockedByForm = true;
			setSendingState( sending );
		} );

		document.addEventListener( 'kivor:form-input-unblocked', function () {
			inputLockedByForm = false;
			setSendingState( sending );
		} );

		document.addEventListener( 'kivor:cancel-response', function () {
			cancelCurrentResponse();
		} );

		document.addEventListener( 'kivor:end-session', function () {
			resetConversation();
		} );

	}

	/* ------------------------------------------------------------------ */
	/*  Send message                                                      */
	/* ------------------------------------------------------------------ */

	function onSend() {
		if ( sending ) return;

		var text = els.input.value.trim();
		if ( ! text ) return;

		// Client-side message length limit (VULN-012 fix).
		// Mirrors the server-side max_message_length setting.
		var maxLen = cfg.max_message_length || 2000;
		if ( text.length > maxLen ) {
			text = text.slice( 0, maxLen );
			els.input.value = text;
		}

		document.dispatchEvent( new CustomEvent( 'kivor:user-sent', {
			detail: { text: text },
		} ) );

		// Check consent.
		if ( needsConsent() && ! hasConsent() ) {
			document.dispatchEvent( new CustomEvent( 'kivor:show-consent' ) );
		}

		els.input.value = '';
		// Render user message.
		appendMessage( 'user', text );
		// Add to history.
		history.push( { role: 'user', content: text } );
		// Send.
		sending = true;
		setSendingState( true );

		activeTypingEl = showTyping();

		sendAjax( text, activeTypingEl );
	}

	function cancelCurrentResponse() {
		if ( ! sending ) return false;

		if ( currentAbortController ) {
			try {
				currentAbortController.abort();
			} catch ( e ) {}
		}
		currentAbortController = null;

		sending = false;
		setSendingState( false );

		if ( activeTypingEl ) {
			removeTyping( activeTypingEl );
			activeTypingEl = null;
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX (non-streaming) request                                      */
	/* ------------------------------------------------------------------ */

	function sendAjax( text, typingEl ) {
		currentAbortController = new AbortController();

		var body = {
			message:    text,
			session_id: sessionId,
			history:    getHistoryWindow(),
			consent:    hasConsent(),
		};

		fetch( cfg.rest_url + 'kivor-chat-agent/v1/chat', {
			method:  'POST',
			headers: getHeaders(),
			body:    JSON.stringify( body ),
			signal:  currentAbortController.signal,
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					return res.json().then( function ( err ) {
						throw new Error( err.message || 'Request failed' );
					} );
				}
				return res.json();
			} )
			.then( function ( data ) {
				removeTyping( typingEl );
				activeTypingEl = null;

				if ( data.session_id ) {
					sessionId = data.session_id;
					saveSession();
				}

				// Render bot reply.
				var msgEl = null;
				if ( data.reply ) {
					msgEl = appendMessage( 'bot', data.reply || '' );
				}

				// Render product cards.
				if ( data.products && data.products.length && msgEl ) {
					document.dispatchEvent( new CustomEvent( 'kivor:render-products', {
						detail: { products: data.products, container: msgEl },
					} ) );
				}

				if ( data.reply ) {
					history.push( { role: 'assistant', content: data.reply } );
					saveHistory();
					document.dispatchEvent( new CustomEvent( 'kivor:bot-reply', {
						detail: { text: data.reply },
					} ) );
				}

				sending = false;
				setSendingState( false );
				currentAbortController = null;

				if ( data.forms && data.forms.length ) {
					document.dispatchEvent( new CustomEvent( 'kivor:form-triggered', {
						detail: { forms: data.forms },
					} ) );
				}
			} )
			.catch( function ( err ) {
				if ( err && err.name === 'AbortError' ) {
					currentAbortController = null;
					return;
				}
				removeTyping( typingEl );
				activeTypingEl = null;
				appendMessage( 'bot', err.message || 'Something went wrong. Please try again.' );
				sending = false;
				setSendingState( false );
				currentAbortController = null;
			} );
	}

	/* ------------------------------------------------------------------ */
	/*  Message rendering                                                 */
	/* ------------------------------------------------------------------ */

	function appendMessage( role, text ) {
		var isBot  = role === 'bot' || role === 'assistant';
		var cssClass = isBot ? 'kivor-chat-agent-msg kivor-chat-agent-msg--bot' : 'kivor-chat-agent-msg kivor-chat-agent-msg--user';

		var msgEl = sa.el( 'div', { className: cssClass } );

		// Avatar for bot messages.
		if ( isBot && cfg.bot_avatar ) {
			msgEl.appendChild(
				sa.el( 'img', {
					className: 'kivor-chat-agent-msg__avatar',
					src: cfg.bot_avatar,
					alt: cfg.bot_name || 'Bot',
				} )
			);
		}

		var bubble = sa.el( 'div', { className: 'kivor-chat-agent-msg__bubble' } );
		var textEl = sa.el( 'div', { className: 'kivor-chat-agent-msg__text' } );

		if ( isBot && text ) {
			textEl.innerHTML = formatBotText( text );
		} else {
			textEl.textContent = text;
		}

		bubble.appendChild( textEl );
		msgEl.appendChild( bubble );

		els.messages.appendChild( msgEl );
		scrollToBottom();

		return msgEl;
	}

	/**
	 * Safe markdown-lite formatting for bot messages.
	 * Supports: headings (##, ###), ordered/unordered lists,
	 * paragraphs, **bold**, *italic*, and `code`.
	 */
	function formatBotText( text ) {
		if ( ! text ) {
			return '';
		}

		var normalized = escapeHtml( text ).replace( /\r\n?/g, '\n' );
		var lines = normalized.split( '\n' );
		var html = [];
		var listType = '';
		var paragraphLines = [];

		function closeParagraph() {
			if ( ! paragraphLines.length ) {
				return;
			}
			html.push( '<p>' + formatInline( paragraphLines.join( ' ' ) ) + '</p>' );
			paragraphLines = [];
		}

		function closeList() {
			if ( ! listType ) {
				return;
			}
			html.push( '</' + listType + '>' );
			listType = '';
		}

		for ( var i = 0; i < lines.length; i++ ) {
			var raw = lines[ i ];
			var line = raw.trim();

			if ( ! line ) {
				closeParagraph();
				closeList();
				continue;
			}

			var headingMatch = line.match( /^(#{2,3})\s+(.+)$/ );
			if ( headingMatch ) {
				closeParagraph();
				closeList();
				var headingTag = headingMatch[ 1 ].length === 2 ? 'h4' : 'h5';
				html.push( '<' + headingTag + '>' + formatInline( headingMatch[ 2 ] ) + '</' + headingTag + '>' );
				continue;
			}

			var orderedMatch = line.match( /^\d+\.\s+(.+)$/ );
			if ( orderedMatch ) {
				closeParagraph();
				if ( listType !== 'ol' ) {
					closeList();
					listType = 'ol';
					html.push( '<ol>' );
				}
				html.push( '<li>' + formatInline( orderedMatch[ 1 ] ) + '</li>' );
				continue;
			}

			var unorderedMatch = line.match( /^[\-*]\s+(.+)$/ );
			if ( unorderedMatch ) {
				closeParagraph();
				if ( listType !== 'ul' ) {
					closeList();
					listType = 'ul';
					html.push( '<ul>' );
				}
				html.push( '<li>' + formatInline( unorderedMatch[ 1 ] ) + '</li>' );
				continue;
			}

			closeList();
			paragraphLines.push( line );
		}

		closeParagraph();
		closeList();

		return html.join( '' );
	}

	function escapeHtml( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function formatInline( text ) {
		var codeTokens = [];

		var out = text.replace( /`([^`]+?)`/g, function ( _m, code ) {
			var token = '%%KIVOR_CODE_' + codeTokens.length + '%%';
			codeTokens.push( '<code>' + code + '</code>' );
			return token;
		} );

		out = out.replace( /\*\*([^*]+?)\*\*/g, '<strong>$1</strong>' );
		out = out.replace( /\*([^*]+?)\*/g, '<em>$1</em>' );

		out = out.replace( /%%KIVOR_CODE_(\d+)%%/g, function ( _m, idx ) {
			var i = parseInt( idx, 10 );
			return codeTokens[ i ] || '';
		} );

		return out;
	}

	/* ------------------------------------------------------------------ */
	/*  Typing indicator                                                  */
	/* ------------------------------------------------------------------ */

	function showTyping() {
		var dots = sa.el( 'div', { className: 'kivor-chat-agent-msg kivor-chat-agent-msg--bot kivor-chat-agent-msg--typing' }, [
			sa.el( 'div', { className: 'kivor-chat-agent-msg__bubble' }, [
				sa.el( 'div', { className: 'kivor-chat-agent-typing' }, [
					sa.el( 'span', { className: 'kivor-chat-agent-typing__dot' } ),
					sa.el( 'span', { className: 'kivor-chat-agent-typing__dot' } ),
					sa.el( 'span', { className: 'kivor-chat-agent-typing__dot' } ),
				] ),
			] ),
		] );

		els.messages.appendChild( dots );
		scrollToBottom();
		return dots;
	}

	function removeTyping( el ) {
		if ( el && el.parentNode ) {
			el.parentNode.removeChild( el );
		}
	}

	function showInitialGreeting() {
		if ( history.length ) {
			return;
		}

		var greeting = ( cfg.first_greeting_message || '' ).toString().trim();
		if ( ! greeting ) {
			return;
		}

		appendMessage( 'bot', greeting );
		history.push( { role: 'assistant', content: greeting } );
		saveHistory();
	}

	function endSession() {
		cancelCurrentResponse();

		history = [];
		if ( els.messages ) {
			els.messages.innerHTML = '';
		}

		if ( els.input ) {
			els.input.value = '';
		}

		sessionId = generateId();
		saveSession();

		document.dispatchEvent( new CustomEvent( 'kivor:form-input-unblocked' ) );
		if ( sa && typeof sa.setChatInputBlocked === 'function' ) {
			sa.setChatInputBlocked( false, 'Type a message...' );
		}

		showInitialGreeting();

		if ( needsConsent() ) {
			document.dispatchEvent( new CustomEvent( 'kivor:show-consent' ) );
		}

		scrollToBottom();
	}

	/* ------------------------------------------------------------------ */
	/*  UI state helpers                                                  */
	/* ------------------------------------------------------------------ */

	function setSendingState( isSending ) {
		var disable = isSending || inputLockedByForm;
		els.input.disabled  = disable;
		els.sendBtn.disabled = disable;
		els.sendBtn.classList.toggle( 'kivor-chat-agent-send--disabled', isSending );
	}

	function scrollToBottom() {
		if ( sa && sa.bodyChat ) {
			sa.bodyChat.scrollTop = sa.bodyChat.scrollHeight;
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Session management                                                */
	/* ------------------------------------------------------------------ */

	function loadSession() {
		try {
			return sessionStorage.getItem( 'kivor_chat_agent_session_id' ) || generateId();
		} catch ( e ) {
			return generateId();
		}
	}

	function saveSession() {
		try {
			sessionStorage.setItem( 'kivor_chat_agent_session_id', sessionId );
		} catch ( e ) {
			// sessionStorage may be unavailable.
		}
	}

	function generateId() {
		// Use crypto.getRandomValues for better entropy when available (VULN-011 fix).
		var randomPart;
		if ( window.crypto && window.crypto.getRandomValues ) {
			var arr = new Uint8Array( 8 );
			window.crypto.getRandomValues( arr );
			randomPart = Array.prototype.map.call( arr, function ( b ) {
				return b.toString( 16 ).padStart( 2, '0' );
			} ).join( '' );
		} else {
			// Fallback for very old browsers.
			randomPart = Math.random().toString( 36 ).slice( 2, 8 ) + Math.random().toString( 36 ).slice( 2, 8 );
		}
		var id = 'kivor-' + Date.now().toString( 36 ) + '-' + randomPart;
		try {
			sessionStorage.setItem( 'kivor_chat_agent_session_id', id );
		} catch ( e ) {
			// Ignore.
		}
		return id;
	}

	/* ------------------------------------------------------------------ */

	function loadSession() {
		var persisted = getPersistedValue( STORAGE_KEYS.session );
		if ( persisted ) {
			return persisted;
		}
		return generateId();
	}

	function saveSession() {
		setPersistedValue( STORAGE_KEYS.session, sessionId );
	}

	function loadHistory() {
		var raw = getPersistedValue( STORAGE_KEYS.history );
		if ( ! raw ) {
			return [];
		}

		var parsed;
		try {
			parsed = JSON.parse( raw );
		} catch ( e ) {
			return [];
		}

		if ( ! Array.isArray( parsed ) ) {
			return [];
		}

		return parsed
			.filter( function ( item ) {
				return item && typeof item === 'object' && ( item.role === 'user' || item.role === 'assistant' ) && typeof item.content === 'string';
			} )
			.slice( -200 );
	}

	function hasPersistedUserMessage() {
		if ( history.some( function ( item ) { return item && item.role === 'user'; } ) ) {
			return true;
		}

		var raw = getPersistedValue( STORAGE_KEYS.history );
		if ( ! raw ) {
			return false;
		}

		try {
			var parsed = JSON.parse( raw );
			if ( ! Array.isArray( parsed ) ) {
				return false;
			}

			return parsed.some( function ( item ) {
				return item && typeof item === 'object' && item.role === 'user' && typeof item.content === 'string' && item.content.trim() !== '';
			} );
		} catch ( e ) {
			return false;
		}
	}

	function saveHistory() {
		setPersistedValue( STORAGE_KEYS.history, JSON.stringify( history.slice( -200 ) ) );
	}

	function renderPersistedHistory() {
		if ( ! history.length ) {
			return;
		}

		history.forEach( function ( item ) {
			appendMessage( item.role, item.content );
		} );
	}

	function syncHistoryFromStorage() {
		var latest = loadHistory();
		if ( ! latest.length ) {
			history = [];
			hasUserInteracted = false;
			if ( els && els.messages ) {
				els.messages.innerHTML = '';
			}
			return;
		}

		var currentSerialized = JSON.stringify( history );
		var latestSerialized = JSON.stringify( latest );
		if ( currentSerialized === latestSerialized ) {
			hasUserInteracted = hasPersistedUserMessage();
			return;
		}

		history = latest;
		hasUserInteracted = hasPersistedUserMessage();
		els.messages.innerHTML = '';
		renderPersistedHistory();
	}

	function generateId() {
		// Use crypto.getRandomValues for better entropy when available (VULN-011 fix).
		var randomPart;
		if ( window.crypto && window.crypto.getRandomValues ) {
			var arr = new Uint8Array( 8 );
			window.crypto.getRandomValues( arr );
			randomPart = Array.prototype.map.call( arr, function ( b ) {
				return b.toString( 16 ).padStart( 2, '0' );
			} ).join( '' );
		} else {
			// Fallback for very old browsers.
			randomPart = Math.random().toString( 36 ).slice( 2, 8 ) + Math.random().toString( 36 ).slice( 2, 8 );
		}
		var id = 'kivor-' + Date.now().toString( 36 ) + '-' + randomPart;
		setPersistedValue( STORAGE_KEYS.session, id );
		return id;
	}

	function getPersistedValue( key ) {
		try {
			var localVal = window.localStorage ? window.localStorage.getItem( key ) : null;
			if ( localVal ) {
				return localVal;
			}
		} catch ( e ) {}

		try {
			var sessionVal = window.sessionStorage ? window.sessionStorage.getItem( key ) : null;
			if ( sessionVal ) {
				try {
					if ( window.localStorage ) {
						window.localStorage.setItem( key, sessionVal );
					}
				} catch ( migrationError ) {}
				return sessionVal;
			}
		} catch ( e ) {}

		return '';
	}

	function setPersistedValue( key, value ) {
		try {
			if ( window.localStorage ) {
				window.localStorage.setItem( key, value );
			}
		} catch ( e ) {}

		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( key, value );
			}
		} catch ( e ) {}
	}

	function clearPersistedValue( key ) {
		try {
			if ( window.localStorage ) {
				window.localStorage.removeItem( key );
			}
		} catch ( e ) {}

		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.removeItem( key );
			}
		} catch ( e ) {}
	}

	/* ------------------------------------------------------------------ */
	/*  History window                                                    */
	/* ------------------------------------------------------------------ */

	function getHistoryWindow() {
		var size = cfg.conversation_memory_size || 10;
		return history.slice( -size );
	}

	/* ------------------------------------------------------------------ */
	/*  Consent helpers                                                   */
	/* ------------------------------------------------------------------ */

	function needsConsent() {
		return cfg.gdpr && cfg.gdpr.enabled && cfg.gdpr.consent_required;
	}

	function hasConsent() {
		if ( ! needsConsent() ) return true;
		return getPersistedValue( STORAGE_KEYS.consent ) === '1';
	}

	/* ------------------------------------------------------------------ */
	/*  HTTP helpers                                                      */
	/* ------------------------------------------------------------------ */

	function getHeaders() {
		var h = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			h['X-WP-Nonce'] = cfg.nonce;
		}
		return h;
	}
} )();
