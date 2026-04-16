/**
 * Kivor Chat Agent - Forms module.
 *
 * Renders primary and triggered forms, validates input, and submits to REST API.
 *
 * @package KivorAgent
 * @since   1.1.0
 */
( function () {
	'use strict';

	document.addEventListener( 'kivor:ready', init );

	var sa;
	var cfg;
	var formsCfg;
	var activeFormId = 0;
	var activeCard = null;
	var tabFormNode = null;

	function init() {
		sa = window.kivorAgent;
		cfg = sa.cfg || {};
		formsCfg = cfg.forms || {};

		if ( ! formsCfg.enabled ) {
			return;
		}

		document.addEventListener( 'kivor:form-triggered', onFormTriggered );

		if ( hasPrimaryCompleted() || hasPrimarySeen() ) {
			renderTabFormIfNeeded();
			return;
		}

		if ( needsConsent() && ! hasConsent() ) {
			document.addEventListener( 'kivor:consent-granted', function onConsentGranted() {
				document.removeEventListener( 'kivor:consent-granted', onConsentGranted );
				showPrimaryIfNeeded();
				renderTabFormIfNeeded();
			} );
			return;
		}

		showPrimaryIfNeeded();
		renderTabFormIfNeeded();
	}

	function showPrimaryIfNeeded() {
		var primary = formsCfg.primary_form;
		if ( ! primary || ! primary.form_data ) {
			return;
		}

		var blockInput = !! formsCfg.primary_block_input;

		renderFormCard( primary, {
			isPrimary: true,
			allowSkip: ! blockInput && !! formsCfg.primary_allow_skip,
			blockInput: blockInput,
			showFieldTitles: formsCfg.show_field_titles !== false,
		} );

		storePrimarySeen();
	}

	function onFormTriggered( e ) {
		var forms = ( e.detail && e.detail.forms ) || [];
		if ( ! forms.length ) {
			return;
		}

		var payload = forms[0];
		renderFormCard( payload, {
			isPrimary: !!payload.is_primary,
			allowSkip: !!formsCfg.primary_allow_skip,
			blockInput: !!payload.block_input,
			showFieldTitles: formsCfg.show_field_titles !== false,
		} );
	}

	function renderTabFormIfNeeded() {
		if ( ! sa.bodyFormTab ) {
			return;
		}

		var tabPayload = formsCfg.tab_form;
		if ( ! tabPayload || ! tabPayload.form_data || ! tabPayload.form_data.fields ) {
			return;
		}

		if ( tabFormNode && tabFormNode.parentNode ) {
			tabFormNode.parentNode.removeChild( tabFormNode );
		}

		var wrap = sa.el( 'div', { className: 'kivor-chat-agent-form-tab-wrap' } );
		var card = sa.el( 'div', { className: 'kivor-chat-agent-form-card kivor-chat-agent-form-card--panel' } );
		var form = tabPayload.form_data;

		var formEl = buildFormElement( form, {
			isPrimary: false,
			allowSkip: false,
			blockInput: false,
			showFieldTitles: formsCfg.show_field_titles !== false,
			panel: true,
		}, function () {
			formEl.reset();
			showError( formEl, '_success', 'Thanks, your message has been submitted.' );
		} );

		card.appendChild( formEl );
		wrap.appendChild( card );
		sa.bodyFormTab.appendChild( wrap );
		tabFormNode = wrap;
	}

	function renderFormCard( payload, options ) {
		if ( ! payload || ! payload.form_data || ! payload.form_data.fields ) {
			return;
		}

		var form = payload.form_data;
		if ( activeFormId && activeFormId === form.id ) {
			return;
		}

		activeFormId = form.id;

		var messages = sa.qs( '.kivor-chat-agent-messages', sa.bodyChat );
		if ( ! messages ) {
			return;
		}

		if ( activeCard && activeCard.parentNode ) {
			activeCard.parentNode.removeChild( activeCard );
		}

		var wrapper = sa.el( 'div', { className: 'kivor-chat-agent-msg kivor-chat-agent-msg--bot kivor-chat-agent-msg--form' } );
		var bubble = sa.el( 'div', { className: 'kivor-chat-agent-msg__bubble kivor-chat-agent-msg__bubble--form' } );
		var card = sa.el( 'div', { className: 'kivor-chat-agent-form-card' } );

		var formEl = buildFormElement( form, options, function () {
			clearActiveCard();
		} );

		card.appendChild( formEl );
		bubble.appendChild( card );
		wrapper.appendChild( bubble );

		messages.appendChild( wrapper );
		sa.bodyChat.scrollTop = sa.bodyChat.scrollHeight;
		activeCard = wrapper;

		if ( options.blockInput ) {
			sa.setChatInputBlocked( true, 'Please complete the form to continue...' );
			document.dispatchEvent( new CustomEvent( 'kivor:form-input-blocked' ) );
		}
	}

	function buildFormElement( form, options, onSuccess ) {
		var formClass = 'kivor-chat-agent-form' + ( options && options.panel ? ' kivor-chat-agent-form--panel' : '' );
		var formEl = sa.el( 'form', { className: formClass, novalidate: 'novalidate' } );
		formEl.dataset.formId = String( form.id );

		form.fields.forEach( function (field, index) {
			formEl.appendChild( renderField( field, index, options ) );
		} );

		var actions = sa.el( 'div', { className: 'kivor-chat-agent-form__actions' } );
		var submitBtn = sa.el( 'button', {
			type: 'submit',
			className: 'kivor-chat-agent-form__submit',
			textContent: 'Submit',
		} );
		actions.appendChild( submitBtn );

		if ( options.isPrimary && options.allowSkip ) {
			var skipBtn = sa.el( 'button', {
				type: 'button',
				className: 'kivor-chat-agent-form__skip',
				textContent: 'Skip',
				onClick: function () {
					if ( options.blockInput ) {
						sa.setChatInputBlocked( false, 'Type a message...' );
						document.dispatchEvent( new CustomEvent( 'kivor:form-input-unblocked' ) );
					}
					storePrimaryCompleted();
					document.dispatchEvent( new CustomEvent( 'kivor:form-skipped', {
						detail: { form_id: form.id },
					} ) );
					clearActiveCard();
				},
			} );
			actions.appendChild( skipBtn );
		}

		formEl.appendChild( actions );

		formEl.addEventListener( 'submit', function (evt) {
			evt.preventDefault();
			handleSubmit( form, formEl, options, submitBtn, onSuccess );
		} );

		return formEl;
	}

	function renderField( field, index, options ) {
		var row = sa.el( 'div', { className: 'kivor-chat-agent-form__row' } );
		var fieldId = 'kivor-form-field-' + index + '-' + Date.now();
		var showFieldTitle = ! options || options.showFieldTitles !== false;
		var isCheckbox = field.type === 'checkbox';

		if ( showFieldTitle && ! isCheckbox ) {
			var label = sa.el( 'label', {
				className: 'kivor-chat-agent-form__label',
				for: fieldId,
				textContent: field.label + ( field.required ? ' *' : '' ),
			} );
			row.appendChild( label );
		}

		var input;
		if ( field.type === 'textarea' ) {
			input = sa.el( 'textarea', {
				id: fieldId,
				name: field.name,
				placeholder: field.placeholder || ( ! showFieldTitle ? field.label : '' ),
				rows: 3,
			} );
		} else if ( field.type === 'select' ) {
			input = sa.el( 'select', {
				id: fieldId,
				name: field.name,
			} );
			input.appendChild( sa.el( 'option', {
				value: '',
				textContent: field.placeholder || ( ! showFieldTitle ? field.label : 'Select an option' ),
				disabled: true,
				selected: true,
			} ) );
			(field.options || []).forEach( function (option) {
				input.appendChild( sa.el( 'option', { value: option, textContent: option } ) );
			} );
		} else if ( field.type === 'checkbox' ) {
			input = sa.el( 'input', {
				id: fieldId,
				name: field.name,
				type: 'checkbox',
				value: '1',
			} );
		} else {
			input = sa.el( 'input', {
				id: fieldId,
				name: field.name,
				type: field.type === 'phone' ? 'tel' : field.type,
				placeholder: field.placeholder || ( ! showFieldTitle ? field.label : '' ),
			} );
		}

		input.className = 'kivor-chat-agent-form__input';
		if ( field.required ) {
			input.required = true;
		}
		if ( field.min_length ) {
			input.setAttribute( 'minlength', String( field.min_length ) );
		}
		if ( field.max_length ) {
			input.setAttribute( 'maxlength', String( field.max_length ) );
		}

		if ( isCheckbox ) {
			var checkboxWrap = sa.el( 'label', {
				className: 'kivor-chat-agent-form__checkbox-wrap',
				for: fieldId,
			} );
			checkboxWrap.appendChild( input );
			checkboxWrap.appendChild( sa.el( 'span', {
				className: 'kivor-chat-agent-form__checkbox-text',
				textContent: field.label + ( field.required ? ' *' : '' ),
			} ) );
			row.appendChild( checkboxWrap );
		} else {
			row.appendChild( input );
		}

		return row;
	}

	function handleSubmit( form, formEl, options, submitBtn, onSuccess ) {
		clearErrors( formEl );

		var payload = {};
		var hasError = false;

		form.fields.forEach( function (field) {
			var input = formEl.querySelector( '[name="' + field.name + '"]' );
			if ( ! input ) {
				return;
			}

			var value = field.type === 'checkbox' ? !!input.checked : ( input.value || '' ).trim();
			var err = validateValue( field, value );

			if ( err ) {
				hasError = true;
				showError( formEl, field.name, err );
			}

			payload[ field.name ] = value;
		} );

		if ( hasError ) {
			document.dispatchEvent( new CustomEvent( 'kivor:form-error' ) );
			return;
		}

		submitBtn.disabled = true;
		submitBtn.textContent = 'Submitting...';

		fetch( cfg.rest_url + 'kivor-chat-agent/v1/forms/submit', {
			method: 'POST',
			headers: getHeaders(),
			body: JSON.stringify( {
				form_id: form.id,
				session_id: getSessionId(),
				data: payload,
			} ),
		} )
			.then( function (res) {
				if ( !res.ok ) {
					return res.json().then( function (json) {
						throw json;
					} );
				}
				return res.json();
			} )
			.then( function () {
				if ( options.isPrimary ) {
					storePrimaryCompleted();
					appendPrimarySubmitMessage();
				}

				if ( options.blockInput ) {
					sa.setChatInputBlocked( false, 'Type a message...' );
					document.dispatchEvent( new CustomEvent( 'kivor:form-input-unblocked' ) );
				}

				document.dispatchEvent( new CustomEvent( 'kivor:form-submitted', {
					detail: { form_id: form.id },
				} ) );

				if ( typeof onSuccess === 'function' ) {
					onSuccess();
				}
			} )
			.catch( function (err) {
				if ( err && err.data && err.data.errors ) {
					Object.keys( err.data.errors ).forEach( function (fieldName) {
						showError( formEl, fieldName, err.data.errors[ fieldName ] );
					} );
				} else {
					showError( formEl, '_global', ( err && err.message ) || 'Failed to submit form.' );
				}
				document.dispatchEvent( new CustomEvent( 'kivor:form-error' ) );
			} )
			.finally( function () {
				submitBtn.disabled = false;
				submitBtn.textContent = 'Submit';
			} );
	}

	function appendPrimarySubmitMessage() {
		var text = ( formsCfg.primary_submit_message || '' ).trim();
		if ( ! text ) {
			return;
		}

		var messages = sa.qs( '.kivor-chat-agent-messages', sa.bodyChat );
		if ( ! messages ) {
			return;
		}

		var wrapper = sa.el( 'div', { className: 'kivor-chat-agent-msg kivor-chat-agent-msg--bot' } );
		var bubble = sa.el( 'div', { className: 'kivor-chat-agent-msg__bubble' } );
		var textWrap = sa.el( 'div', { className: 'kivor-chat-agent-msg__text' } );
		textWrap.textContent = text;

		bubble.appendChild( textWrap );
		wrapper.appendChild( bubble );
		messages.appendChild( wrapper );
		sa.bodyChat.scrollTop = sa.bodyChat.scrollHeight;
	}

	function validateValue( field, value ) {
		if ( field.type === 'checkbox' ) {
			if ( field.required && !value ) {
				return 'This field is required.';
			}
			return '';
		}

		if ( field.required && !value ) {
			return 'This field is required.';
		}

		if ( !value ) {
			return '';
		}

		if ( field.type === 'email' ) {
			if ( !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
				return 'Please enter a valid email address.';
			}
		}

		if ( field.type === 'phone' ) {
			var digits = String( value ).replace( /\D/g, '' );
			if ( digits.length < 7 ) {
				return 'Please enter a valid phone number.';
			}
		}

		if ( field.min_length && value.length < field.min_length ) {
			return 'Minimum length is ' + field.min_length + ' characters.';
		}

		if ( field.max_length && value.length > field.max_length ) {
			return 'Maximum length is ' + field.max_length + ' characters.';
		}

		if ( field.type === 'select' && field.options && field.options.length ) {
			if ( field.options.indexOf( value ) === -1 ) {
				return 'Please choose a valid option.';
			}
		}

		return '';
	}

	function showError( formEl, fieldName, message ) {
		if ( fieldName === '_success' ) {
			var success = formEl.querySelector( '.kivor-chat-agent-form__success' );
			if ( ! success ) {
				success = sa.el( 'div', { className: 'kivor-chat-agent-form__success' } );
				formEl.insertBefore( success, formEl.firstChild );
			}
			success.textContent = message;
			return;
		}

		if ( fieldName === '_global' ) {
			var existing = formEl.querySelector( '.kivor-chat-agent-form__error--global' );
			if ( !existing ) {
				existing = sa.el( 'div', { className: 'kivor-chat-agent-form__error kivor-chat-agent-form__error--global' } );
				formEl.insertBefore( existing, formEl.firstChild );
			}
			existing.textContent = message;
			return;
		}

		var errEl = formEl.querySelector( '[data-error-for="' + fieldName + '"]' );
		if ( !errEl ) {
			var input = formEl.querySelector( '[name="' + fieldName + '"]' );
			if ( input ) {
				var row = input.closest( '.kivor-chat-agent-form__row' );
				if ( row ) {
					errEl = sa.el( 'div', { className: 'kivor-chat-agent-form__error', 'data-error-for': fieldName } );
					row.appendChild( errEl );
				}
			}
		}

		if ( errEl ) {
			errEl.textContent = message || '';
		}
	}

	function clearErrors( formEl ) {
		var errs = formEl.querySelectorAll( '.kivor-chat-agent-form__error' );
		errs.forEach( function (el) {
			el.remove();
		} );
		var success = formEl.querySelector( '.kivor-chat-agent-form__success' );
		if ( success ) {
			success.textContent = '';
		}
	}

	function clearActiveCard() {
		activeFormId = 0;
		if ( activeCard && activeCard.parentNode ) {
			activeCard.parentNode.removeChild( activeCard );
		}
		activeCard = null;
	}

	function getHeaders() {
		var h = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			h['X-WP-Nonce'] = cfg.nonce;
		}
		return h;
	}

	function getSessionId() {
		try {
			if ( window.localStorage ) {
				var localId = window.localStorage.getItem( 'kivor_chat_agent_session_id' ) || '';
				if ( localId ) {
					return localId;
				}
			}
		} catch ( e ) {}

		try {
			return window.sessionStorage ? ( window.sessionStorage.getItem( 'kivor_chat_agent_session_id' ) || '' ) : '';
		} catch ( e ) {
			return '';
		}
	}

	function needsConsent() {
		return cfg.gdpr && cfg.gdpr.enabled && cfg.gdpr.consent_required;
	}

	function hasConsent() {
		if ( !needsConsent() ) return true;

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

	function storePrimaryCompleted() {
		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( getPrimaryStateKey( 'done' ), '1' );
			}
		} catch ( e ) {}
	}

	function hasPrimaryCompleted() {
		try {
			if ( window.sessionStorage && window.sessionStorage.getItem( getPrimaryStateKey( 'done' ) ) === '1' ) {
				return true;
			}
			return window.sessionStorage && window.sessionStorage.getItem( 'kivor_chat_agent_primary_form_done' ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function storePrimarySeen() {
		try {
			if ( window.sessionStorage ) {
				window.sessionStorage.setItem( getPrimaryStateKey( 'seen' ), '1' );
			}
		} catch ( e ) {}
	}

	function hasPrimarySeen() {
		try {
			return !!( window.sessionStorage && window.sessionStorage.getItem( getPrimaryStateKey( 'seen' ) ) === '1' );
		} catch ( e ) {
			return false;
		}
	}

	function getPrimaryStateKey( state ) {
		var sessionId = getSessionId();
		if ( sessionId ) {
			return 'kivor_chat_agent_primary_form_' + state + '_' + sessionId;
		}
		return 'kivor_chat_agent_primary_form_' + state;
	}
} )();
