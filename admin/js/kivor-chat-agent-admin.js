/**
 * Kivor Chat Agent Admin — Vanilla JS
 *
 * Handles AJAX actions: test connection, scrape URL, export CSV,
 * clear logs, and conditional field toggling.
 *
 * @package KivorAgent
 * @since   1.0.0
 */
(function () {
	'use strict';

	// kivorChatAgentAdmin is injected via wp_add_inline_script.
	var config = window.kivorChatAgentAdmin || {};

	// =========================================================================
	// Helpers
	// =========================================================================

	function $( selector, parent ) {
		return ( parent || document ).querySelector( selector );
	}

	function $$( selector, parent ) {
		return Array.prototype.slice.call( ( parent || document ).querySelectorAll( selector ) );
	}

	function apiFetch( endpoint, options ) {
		options = options || {};
		var url = config.restUrl + endpoint;
		var fetchOpts = {
			method: options.method || 'GET',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json',
			},
			credentials: 'same-origin',
		};
		if ( options.body ) {
			fetchOpts.body = JSON.stringify( options.body );
		}
		return fetch( url, fetchOpts ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( !res.ok ) {
					var err = new Error( data && data.message ? data.message : 'Request failed' );
					err.payload = data;
					throw err;
				}
				return data;
			} );
		} );
	}

	function showResult( el, success, message ) {
		if ( ! el ) return;
		el.textContent = message;
		el.className = 'kivor-chat-agent-test-result ' + ( success ? 'is-success' : 'is-error' );
		el.style.display = 'block';
	}

	function formatApiMessage( data, fallback ) {
		var message = data && data.message ? data.message : ( fallback || 'Unknown response.' );
		if ( data && data.upgrade_url ) {
			message += ' Upgrade: ' + data.upgrade_url;
		}
		return message;
	}

	function formatErrorMessage( err, fallback ) {
		var base = err && err.message ? err.message : ( fallback || 'Request failed' );
		if ( err && err.payload && err.payload.upgrade_url ) {
			base += ' Upgrade: ' + err.payload.upgrade_url;
		}
		return base;
	}

	function setLoading( btn, loading ) {
		if ( loading ) {
			btn.disabled = true;
			btn.dataset.origText = btn.textContent;
			btn.innerHTML = btn.textContent + ' <span class="kivor-chat-agent-spinner"></span>';
		} else {
			btn.disabled = false;
			btn.textContent = btn.dataset.origText || btn.textContent;
		}
	}

	// =========================================================================
	// Test AI Connection
	// =========================================================================

	function initTestConnection() {
		$$( '.kivor-chat-agent-test-connection' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var provider = btn.dataset.provider;
				var resultEl = $( '#kivor-chat-agent-test-result-' + provider );

				// Read current form values.
				var keyInput = $( '[name="provider_' + provider + '_api_key"]' );
				var modelInput = $( '[name="provider_' + provider + '_model"]' );

				if ( ! keyInput || ! modelInput ) return;

				var apiKey = keyInput.value;
				var model = modelInput.value;

				if ( ! apiKey ) {
					showResult( resultEl, false, 'API key is required.' );
					return;
				}
				if ( ! model ) {
					showResult( resultEl, false, 'Model is required.' );
					return;
				}

				setLoading( btn, true );
				if ( resultEl ) resultEl.style.display = 'none';

				apiFetch( 'test-connection', {
					method: 'POST',
					body: { provider: provider, api_key: apiKey, model: model },
				} ).then( function ( data ) {
					setLoading( btn, false );
					showResult( resultEl, data.success, formatApiMessage( data, 'Unknown response.' ) );
				} ).catch( function ( err ) {
					setLoading( btn, false );
					showResult( resultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
				} );
			} );
		} );
	}

	// =========================================================================
	// Test Vector Store Connection
	// =========================================================================

	function initTestVectorStoreConnection() {
		$$( '.kivor-chat-agent-test-vector-store' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var storeType = btn.dataset.store;
				var resultEl = $( '#kivor-chat-agent-test-vector-result-' + storeType );

				if ( ! storeType ) {
					showResult( resultEl, false, 'Invalid vector store.' );
					return;
				}

				var payload = { store_type: storeType, config: {} };

				if ( storeType === 'pinecone' ) {
					var pineconeKey = $( '#pinecone_api_key' );
					var pineconeIndex = $( '#pinecone_index_name' );
					var pineconeEnv = $( '#pinecone_environment' );

					payload.config = {
						api_key: pineconeKey ? pineconeKey.value : '',
						index_name: pineconeIndex ? pineconeIndex.value : '',
						environment: pineconeEnv ? pineconeEnv.value : '',
					};
				} else if ( storeType === 'qdrant' ) {
					var qdrantEndpoint = $( '#qdrant_endpoint_url' );
					var qdrantKey = $( '#qdrant_api_key' );
					var qdrantCollection = $( '#qdrant_collection_name' );

					payload.config = {
						endpoint_url: qdrantEndpoint ? qdrantEndpoint.value : '',
						api_key: qdrantKey ? qdrantKey.value : '',
						collection_name: qdrantCollection ? qdrantCollection.value : '',
					};
				}

				setLoading( btn, true );
				if ( resultEl ) resultEl.style.display = 'none';

				apiFetch( 'test-vector-store', {
					method: 'POST',
					body: payload,
				} ).then( function ( data ) {
					setLoading( btn, false );
					showResult( resultEl, !!data.success, formatApiMessage( data, 'Unknown response.' ) );
				} ).catch( function ( err ) {
					setLoading( btn, false );
					showResult( resultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
				} );
			} );
		} );
	}

	// =========================================================================
	// Test Embedding Provider Connection
	// =========================================================================

	function initTestEmbeddingProviderConnection() {
		$$( '.kivor-chat-agent-test-embedding-provider' ).forEach( function ( btn ) {
			var provider = btn.dataset.provider;
			if ( provider ) {
				var providerEnabled = $( '[name="embedding_provider_' + provider + '_enabled"]' );
				if ( providerEnabled ) {
					btn.disabled = ! providerEnabled.checked;
					providerEnabled.addEventListener( 'change', function () {
						btn.disabled = ! providerEnabled.checked;
					} );
				}
			}

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				provider = btn.dataset.provider;
				var resultEl = $( '#kivor-chat-agent-test-embedding-result-' + provider );

				if ( !provider ) {
					showResult( resultEl, false, 'Invalid embedding provider.' );
					return;
				}

				var apiKeyInput = $( '#embedding_provider_' + provider + '_api_key' );
				var modelInput = $( '#embedding_provider_' + provider + '_model' );

				var payload = {
					provider: provider,
					api_key: apiKeyInput ? apiKeyInput.value : '',
					model: modelInput ? modelInput.value : '',
				};

				if ( provider === 'azure_openai' ) {
					var endpointInput = $( '#embedding_provider_azure_openai_endpoint' );
					var deploymentInput = $( '#embedding_provider_azure_openai_deployment' );
					var apiVersionInput = $( '#embedding_provider_azure_openai_api_version' );

					payload.endpoint = endpointInput ? endpointInput.value : '';
					payload.deployment = deploymentInput ? deploymentInput.value : '';
					payload.api_version = apiVersionInput ? apiVersionInput.value : '';
				}

				setLoading( btn, true );
				if ( resultEl ) resultEl.style.display = 'none';

				apiFetch( 'test-embedding-provider', {
					method: 'POST',
					body: payload,
				} ).then( function ( data ) {
					setLoading( btn, false );
					showResult( resultEl, !!data.success, formatApiMessage( data, 'Unknown response.' ) );
				} ).catch( function ( err ) {
					setLoading( btn, false );
					showResult( resultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
				} );
			} );
		} );
	}

	// =========================================================================
	// URL Scraper
	// =========================================================================

	function initScraper() {
		var btn = $( '#kivor-chat-agent-scrape-btn' );
		if ( ! btn ) return;

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var input = $( '#kivor-chat-agent-scrape-url' );
			var resultEl = $( '#kivor-chat-agent-scrape-result' );

			if ( ! input || ! input.value ) {
				showResult( resultEl, false, 'Please enter a URL.' );
				return;
			}

			setLoading( btn, true );
			if ( resultEl ) resultEl.style.display = 'none';

			apiFetch( 'knowledge-base/scrape', {
				method: 'POST',
				body: { url: input.value },
			} ).then( function ( data ) {
				setLoading( btn, false );
				if ( data.success ) {
					var titleInput = $( '#kb_title' );
					var contentInput = $( '#kb_content' );
					var article = data.article || {};

					if ( titleInput && article.title ) {
						titleInput.value = article.title;
					}
					if ( contentInput && article.content ) {
						contentInput.value = article.content;
						contentInput.dispatchEvent( new Event( 'input' ) );
					}

					var sourceType = $( '#kb_source_type' );
					var sourceId = $( '#kb_source_id' );
					var sourceUrl = $( '#kb_source_url' );
					var importMethod = $( '#kb_import_method' );
					var syncInterval = $( '#kb_sync_interval' );

					if ( sourceType ) sourceType.value = 'webpage';
					if ( sourceId ) sourceId.value = article.source_url || '';
					if ( sourceUrl ) sourceUrl.value = article.source_url || '';
					if ( importMethod ) importMethod.value = 'manual';
					if ( syncInterval ) syncInterval.value = 'manual';

					showResult( resultEl, true, 'Imported into editor: ' + ( article.title || 'Untitled' ) );
					input.value = '';
					if ( titleInput ) titleInput.focus();
				} else {
					showResult( resultEl, false, formatApiMessage( data, 'Scrape failed.' ) );
				}
			} ).catch( function ( err ) {
				setLoading( btn, false );
				showResult( resultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
			} );
		} );
	}

	// =========================================================================
	// Export CSV
	// =========================================================================

	function initExportCSV() {
		var btn = $( '#kivor-chat-agent-export-csv' );
		if ( ! btn ) return;

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			setLoading( btn, true );

			apiFetch( 'logs/export', { method: 'POST' } ).then( function ( data ) {
				setLoading( btn, false );
				if ( data.success && data.data ) {
					// Decode base64 and trigger download.
					var csv = atob( data.data );
					var blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
					var link = document.createElement( 'a' );
					link.href = URL.createObjectURL( blob );
					link.download = data.filename || 'kivor-chat-agent-chat-logs.csv';
					document.body.appendChild( link );
					link.click();
					document.body.removeChild( link );
				} else {
					alert( formatApiMessage( data, 'No logs to export.' ) );
				}
			} ).catch( function ( err ) {
				setLoading( btn, false );
				alert( 'Export failed: ' + formatErrorMessage( err ) );
			} );
		} );
	}

	// =========================================================================
	// Clear All Logs
	// =========================================================================

	function initClearLogs() {
		var btn = $( '#kivor-chat-agent-clear-logs' );
		if ( ! btn ) return;

		var wrap = btn.parentElement;
		var confirmed = false;

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			if ( ! confirmed ) {
				// Show confirmation.
				var confirmMsg = document.createElement( 'span' );
				confirmMsg.className = 'kivor-chat-agent-confirm-msg';
				confirmMsg.textContent = 'Are you sure?';

				var yesBtn = document.createElement( 'button' );
				yesBtn.type = 'button';
				yesBtn.className = 'button button-link-delete';
				yesBtn.textContent = 'Yes, delete all';

				var cancelBtn = document.createElement( 'button' );
				cancelBtn.type = 'button';
				cancelBtn.className = 'button';
				cancelBtn.textContent = 'Cancel';

				btn.style.display = 'none';
				var confirmWrap = document.createElement( 'span' );
				confirmWrap.className = 'kivor-chat-agent-confirm-wrap';
				confirmWrap.appendChild( confirmMsg );
				confirmWrap.appendChild( yesBtn );
				confirmWrap.appendChild( cancelBtn );
				wrap.appendChild( confirmWrap );

				cancelBtn.addEventListener( 'click', function () {
					wrap.removeChild( confirmWrap );
					btn.style.display = '';
				} );

				yesBtn.addEventListener( 'click', function () {
					setLoading( yesBtn, true );
					apiFetch( 'logs', { 
						method: 'DELETE',
						body: { confirm: true }
					} ).then( function ( data ) {
						if ( data.success ) {
							window.location.reload();
						} else {
							alert( formatApiMessage( data, 'Failed to clear logs.' ) );
							wrap.removeChild( confirmWrap );
							btn.style.display = '';
						}
					} ).catch( function ( err ) {
						alert( 'Request failed: ' + formatErrorMessage( err ) );
						wrap.removeChild( confirmWrap );
						btn.style.display = '';
					} );
				} );

				return;
			}
		} );
	}

	// =========================================================================
	// Sync Embeddings
	// =========================================================================

	function initSyncEmbeddings() {
		var btn = $( '#kivor-chat-agent-sync-embeddings' );
		if ( ! btn ) return;

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var resultEl = $( '#kivor-chat-agent-sync-result' );
			setLoading( btn, true );

			apiFetch( 'sync-embeddings', { method: 'POST' } ).then( function ( data ) {
				setLoading( btn, false );
				showResult( resultEl, data.success, formatApiMessage( data, 'Done.' ) );
			} ).catch( function ( err ) {
				setLoading( btn, false );
				showResult( resultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
			} );
		} );
	}

	// =========================================================================
	// Conditional Field Visibility
	// =========================================================================

	function initConditionalFields() {
		$$( '.kivor-chat-agent-provider-accordion' ).forEach( function ( panel ) {
			var summary = panel.querySelector( 'summary' );
			if ( ! summary ) return;
			summary.addEventListener( 'click', function () {
				panel.classList.toggle( 'is-open', panel.open );
			} );
		} );

		// Vector store toggle (Pinecone / Qdrant sections).
		var vectorStoreSelect = $( '[name="vector_store"]' );
		if ( vectorStoreSelect ) {
			var pineconeSection = $( '#kivor-chat-agent-pinecone-section' );
			var qdrantSection = $( '#kivor-chat-agent-qdrant-section' );

			function toggleVectorStore() {
				var val = vectorStoreSelect.value;
				if ( pineconeSection ) pineconeSection.className = 'kivor-chat-agent-conditional-section' + ( val === 'pinecone' ? '' : ' is-hidden' );
				if ( qdrantSection ) qdrantSection.className = 'kivor-chat-agent-conditional-section' + ( val === 'qdrant' ? '' : ' is-hidden' );
			}

			vectorStoreSelect.addEventListener( 'change', toggleVectorStore );
			toggleVectorStore();
		}

		// WhatsApp enabled toggle.
		var waEnabled = $( '[name="whatsapp_enabled"]' );
		if ( waEnabled ) {
			var waFields = $( '#kivor-chat-agent-whatsapp-fields' );
			function toggleWA() {
				if ( waFields ) waFields.style.display = waEnabled.checked ? '' : 'none';
			}
			waEnabled.addEventListener( 'change', toggleWA );
			toggleWA();
		}

		// GDPR enabled toggle.
		var gdprEnabled = $( '[name="gdpr_enabled"]' );
		if ( gdprEnabled ) {
			var gdprFields = $( '#kivor-chat-agent-gdpr-fields' );
			function toggleGDPR() {
				if ( gdprFields ) gdprFields.style.display = gdprEnabled.checked ? '' : 'none';
			}
			gdprEnabled.addEventListener( 'change', toggleGDPR );
			toggleGDPR();
		}

		// Voice toggles: show fields when input is enabled.
		var voiceInputEnabled = $( '[name="voice_input_enabled"]' );
		if ( voiceInputEnabled ) {
			var voiceFields = $( '#kivor-chat-agent-voice-fields' );
			function toggleVoice() {
				if ( voiceFields ) voiceFields.style.display = voiceInputEnabled.checked ? '' : 'none';
			}
			voiceInputEnabled.addEventListener( 'change', toggleVoice );
			toggleVoice();
		}

		$$( '.kivor-chat-agent-external-platform' ).forEach( function ( panel ) {
			var summary = panel.querySelector( 'summary' );
			if ( ! summary ) return;
			summary.addEventListener( 'click', function () {
				panel.classList.toggle( 'is-open', panel.open );
			} );
		} );

		// Voice auto-send mode toggle.
		var autoSendMode = $( '#voice_auto_send_mode' );
		if ( autoSendMode ) {
			var delayRow = $( '#kivor-chat-agent-voice-delay-row' );
			function toggleDelay() {
				if ( delayRow ) delayRow.style.display = autoSendMode.value === 'silence' ? '' : 'none';
			}
			autoSendMode.addEventListener( 'change', toggleDelay );
			toggleDelay();
		}

		// Voice provider accordion state labels.
		var sttProvider = $( '#voice_stt_provider' );
		var providerCards = $$( '.kivor-chat-agent-voice-provider[data-provider]' );
		if ( providerCards.length && sttProvider ) {
			function refreshProviderStates() {
				providerCards.forEach( function ( card ) {
					var provider = card.getAttribute( 'data-provider' );
					var isStt = sttProvider.value === provider;
					var state = $( '.kivor-chat-agent-voice-provider__state', card );

					card.classList.toggle( 'is-active', isStt );

					if ( ! state ) return;
					if ( isStt ) {
						state.textContent = 'Active for STT';
					} else {
						state.textContent = 'Not active';
					}
				} );
			}

			sttProvider.addEventListener( 'change', refreshProviderStates );
			refreshProviderStates();
		}

		// Voice provider readiness hints.
		if ( sttProvider ) {
			var sttHint = $( '#kivor-chat-agent-stt-provider-hint' );

			var openaiKey = $( '#voice_openai_api_key' );
			var cartesiaKey = $( '#voice_cartesia_api_key' );
			var cartesiaVersion = $( '#voice_cartesia_version' );
			var deepgramKey = $( '#voice_deepgram_api_key' );

			function hasSecret( input ) {
				if ( ! input ) return false;
				var v = ( input.value || '' ).trim();
				if ( ! v ) return false;
				return v.indexOf( '****' ) === 0 || v === '***configured***' || v.length > 8;
			}

			function setHint( el, text, isWarn ) {
				if ( ! el ) return;
				el.textContent = text;
				el.classList.remove( 'is-ok', 'is-warn' );
				el.classList.add( isWarn ? 'is-warn' : 'is-ok' );
			}

			function refreshProviderHints() {
				var stt = sttProvider.value;

				if ( stt === 'webspeech' ) {
					setHint( sttHint, 'STT ready: Web Speech runs in browser (no credentials needed).', false );
				} else if ( stt === 'openai' ) {
					setHint( sttHint, hasSecret( openaiKey ) ? 'STT ready: OpenAI key detected.' : 'STT needs OpenAI API key.', ! hasSecret( openaiKey ) );
				} else if ( stt === 'cartesia' ) {
					var cartesiaReady = hasSecret( cartesiaKey ) && cartesiaVersion && ( cartesiaVersion.value || '' ).trim();
					setHint( sttHint, cartesiaReady ? 'STT ready: Cartesia key and version detected.' : 'STT needs Cartesia API key and Cartesia Version (e.g. 2025-04-16).', ! cartesiaReady );
				} else if ( stt === 'deepgram' ) {
					setHint( sttHint, hasSecret( deepgramKey ) ? 'STT ready: Deepgram key detected.' : 'STT needs Deepgram API key.', ! hasSecret( deepgramKey ) );
				}
			}

			[
				sttProvider,
				openaiKey,
				cartesiaKey,
				cartesiaVersion,
				deepgramKey,
			].forEach( function ( el ) {
				if ( el ) {
					el.addEventListener( 'change', refreshProviderHints );
					el.addEventListener( 'input', refreshProviderHints );
				}
			} );

			refreshProviderHints();
		}

		// Forms primary behavior toggles.
		var primaryFormSelect = $( '#forms_primary_form_id' );
		var primarySubmitMessageWrap = $( '#kivor-chat-agent-primary-submit-message-wrap' );
		var primaryBlockInput = $( '#forms_primary_block_input' ) || $( '[name="forms_primary_block_input"]' );
		var primaryAllowSkip = $( '#forms_primary_allow_skip' ) || $( '[name="forms_primary_allow_skip"]' );
		var primaryAllowSkipLabel = $( '#kivor-chat-agent-forms-primary-allow-skip-label' );

		if ( primaryFormSelect && primarySubmitMessageWrap ) {
			function togglePrimarySubmitMessage() {
				primarySubmitMessageWrap.style.display = primaryFormSelect.value !== '0' ? '' : 'none';
			}
			primaryFormSelect.addEventListener( 'change', togglePrimarySubmitMessage );
			togglePrimarySubmitMessage();
		}

		if ( primaryBlockInput && primaryAllowSkip ) {
			function toggleAllowSkipState() {
				var blocked = !!primaryBlockInput.checked;
				primaryAllowSkip.disabled = blocked;
				if ( blocked ) {
					primaryAllowSkip.checked = false;
				}
				if ( primaryAllowSkipLabel ) {
					primaryAllowSkipLabel.classList.toggle( 'is-disabled', blocked );
				}
			}

			primaryBlockInput.addEventListener( 'change', toggleAllowSkipState );
			toggleAllowSkipState();
		}

		var useInAppIntro = $( '#use_in_app_intro' );
		var chatbotTitleRow = $( '#kivor-chat-agent-chatbot-title-row' );
		var chatbotDescriptionRow = $( '#kivor-chat-agent-chatbot-description-row' );

		if ( useInAppIntro ) {
			function toggleInAppIntroRows() {
				var show = !!useInAppIntro.checked;
				if ( chatbotTitleRow ) chatbotTitleRow.style.display = show ? '' : 'none';
				if ( chatbotDescriptionRow ) chatbotDescriptionRow.style.display = show ? '' : 'none';
			}

			useInAppIntro.addEventListener( 'change', toggleInAppIntroRows );
			toggleInAppIntroRows();
		}
	}

	// =========================================================================
	// External Platforms
	// =========================================================================

	function initExternalPlatforms() {
		var platformPrefix = {
			wordpress: 'ext_wp_',
			zendesk: 'ext_zendesk_',
			notion: 'ext_notion_',
		};

		var platformFields = {
			zendesk: [ 'subdomain', 'email', 'api_token', 'sync_mode', 'trigger', 'enabled' ],
			notion: [ 'api_key', 'database_id', 'sync_mode', 'trigger', 'enabled' ],
			wordpress: [ 'sync_mode', 'trigger', 'enabled', 'posts_enabled', 'pages_enabled' ],
		};

		function getFieldValue( id ) {
			var el = document.getElementById( id );
			if ( ! el ) return '';
			if ( el.type === 'checkbox' ) return !!el.checked;
			return el.value || '';
		}

		function buildConfig( platform ) {
			var cfg = {};
			var prefix = platformPrefix[ platform ] || ( 'ext_' + platform + '_' );
			( platformFields[ platform ] || [] ).forEach( function ( key ) {
				var id = prefix + key;
				cfg[ key ] = getFieldValue( id );
			} );
			return cfg;
		}

		$$( '.kivor-chat-agent-external-test' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var platform = btn.dataset.platform;
				if ( ! platform ) return;

				var resultEl = document.getElementById( 'kivor-chat-agent-external-result-' + platform );
				setLoading( btn, true );

				apiFetch( 'external-platforms/test-connection', {
					method: 'POST',
					body: {
						platform: platform,
						config: buildConfig( platform ),
					},
				} ).then( function ( data ) {
					setLoading( btn, false );
					showResult( resultEl, !!data.success, formatApiMessage( data, 'Done.' ) );
				} ).catch( function ( err ) {
					setLoading( btn, false );
					showResult( resultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
				} );
			} );
		} );

		$$( '.kivor-chat-agent-external-sync' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var platform = btn.dataset.platform;
				if ( ! platform ) return;

				var resultEl = document.getElementById( 'kivor-chat-agent-external-result-' + platform );
				var prefix = platformPrefix[ platform ] || ( 'ext_' + platform + '_' );
				var mode = getFieldValue( prefix + 'sync_mode' ) || 'incremental';
				setLoading( btn, true );

				apiFetch( 'external-platforms/sync', {
					method: 'POST',
					body: {
						platform: platform,
						mode: mode,
					},
				} ).then( function ( data ) {
					setLoading( btn, false );
					showResult( resultEl, !!data.success, formatApiMessage( data, 'Done.' ) );
				} ).catch( function ( err ) {
					setLoading( btn, false );
					showResult( resultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
				} );
			} );
		} );
	}

	// =========================================================================
	// General tab helpers (avatar upload)
	// =========================================================================

	function initAvatarUploader() {
		var uploadBtn = $( '#kivor-chat-agent-avatar-upload' );
		var removeBtn = $( '#kivor-chat-agent-avatar-remove' );
		var input = $( '#bot_avatar' );
		var preview = $( '#kivor-chat-agent-bot-avatar-preview' );
		var logoUploadBtn = $( '#kivor-chat-agent-widget-logo-upload' );
		var logoRemoveBtn = $( '#kivor-chat-agent-widget-logo-remove' );
		var logoInput = $( '#widget_logo_id' );
		var logoPreview = $( '#kivor-chat-agent-widget-logo-preview' );

		if ( ! uploadBtn && ! logoUploadBtn ) return;
		if ( ! window.wp || ! wp.media ) return;

		function isAllowedLogo( attachment ) {
			var mime = ( attachment.mime || '' ).toLowerCase();
			if ( mime === 'image/png' || mime === 'image/svg+xml' ) {
				return true;
			}

			var url = ( attachment.url || '' ).toLowerCase();
			return /\.png($|\?)/.test( url ) || /\.svg($|\?)/.test( url );
		}

		if ( uploadBtn && input ) {
			uploadBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				var frame = wp.media( {
					title: 'Select Bot Avatar',
					button: { text: 'Use this image' },
					multiple: false,
					library: { type: 'image' },
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					if ( ! attachment || ! attachment.url ) return;

					input.value = attachment.url;
					if ( preview ) {
						preview.src = attachment.url;
						preview.style.display = '';
					}
					if ( removeBtn ) {
						removeBtn.style.display = '';
					}
				} );

				frame.open();
			} );
		}

		if ( removeBtn && input ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				input.value = '';
				if ( preview ) {
					preview.removeAttribute( 'src' );
					preview.style.display = 'none';
				}
				removeBtn.style.display = 'none';
			} );
		}

		if ( logoUploadBtn && logoInput ) {
			logoUploadBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				var logoFrame = wp.media( {
					title: 'Select Widget Logo',
					button: { text: 'Use this logo' },
					multiple: false,
					library: { type: [ 'image/png', 'image/svg+xml' ] },
				} );

				logoFrame.on( 'select', function () {
					var attachment = logoFrame.state().get( 'selection' ).first().toJSON();
					if ( ! attachment || ! attachment.url ) return;
					if ( ! isAllowedLogo( attachment ) ) {
						alert( 'Please select a PNG or SVG logo.' );
						return;
					}

					logoInput.value = String( attachment.id || 0 );
					if ( logoPreview ) {
						logoPreview.src = attachment.url;
						logoPreview.style.display = '';
					}
					if ( logoRemoveBtn ) {
						logoRemoveBtn.style.display = '';
					}
				} );

				logoFrame.open();
			} );
		}

		if ( logoRemoveBtn && logoInput ) {
			logoRemoveBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				logoInput.value = '';
				if ( logoPreview ) {
					logoPreview.removeAttribute( 'src' );
					logoPreview.style.display = 'none';
				}
				logoRemoveBtn.style.display = 'none';
			} );
		}
	}

	// =========================================================================
	// Character Counter
	// =========================================================================

	function initCharCounters() {
		$$( '[data-kivor-chat-agent-maxchars]' ).forEach( function ( textarea ) {
			var max = parseInt( textarea.dataset.kivorMaxchars, 10 );
			var counter = $( '#' + textarea.id + '-counter' );
			if ( ! counter ) return;

			function updateCount() {
				var len = textarea.value.length;
				counter.textContent = len + ' / ' + max;
				counter.className = 'kivor-chat-agent-char-counter' + ( len > max ? ' is-over' : '' );
			}

			textarea.addEventListener( 'input', updateCount );
			updateCount();
		} );
	}

	function initStylingColorInputs() {
		var colorInputs = $$( 'input[data-kivor-style-color]' );
		if ( ! colorInputs.length ) {
			$$( 'input[type="color"]' ).forEach( function ( colorInput ) {
				var textInput = colorInput.nextElementSibling;
				if ( ! textInput || textInput.tagName !== 'INPUT' || textInput.type !== 'text' ) {
					return;
				}

				function syncValue() {
					textInput.value = colorInput.value;
				}

				colorInput.addEventListener( 'input', syncValue );
				syncValue();
			} );
			return;
		}

		var preview = $( '#kivor-chat-agent-style-preview' );
		var previewIntro = $( '.kivor-chat-agent-style-preview__intro', preview );
		var previewActiveTab = $( '.kivor-chat-agent-style-preview__tab--active', preview );
		var previewVarMap = {
			widget_primary_color: '--preview-primary',
			widget_primary_hover_color: '--preview-primary-hover',
			widget_primary_text_color: '--preview-primary-text',
			widget_background_color: '--preview-bg',
			widget_background_alt_color: '--preview-bg-alt',
			widget_text_color: '--preview-text',
			widget_text_muted_color: '--preview-text-muted',
			widget_border_color: '--preview-border',
			widget_user_bubble_color: '--preview-user-bubble',
			widget_user_text_color: '--preview-user-text',
			widget_bot_bubble_color: '--preview-bot-bubble',
			widget_bot_text_color: '--preview-bot-text',
			widget_tab_background_color: '--preview-tab-bg',
			widget_tab_text_color: '--preview-tab-text',
			widget_tab_active_color: '--preview-tab-active',
			widget_tab_active_text_color: '--preview-tab-active-text',
		};

		var presetMap = {
			emerald: {
				widget_primary_color: '#10b981',
				widget_primary_hover_color: '#059669',
				widget_primary_text_color: '#ffffff',
				widget_background_color: '#ffffff',
				widget_background_alt_color: '#f3f4f6',
				widget_text_color: '#1f2937',
				widget_text_muted_color: '#6b7280',
				widget_border_color: '#e5e7eb',
				widget_user_bubble_color: '#10b981',
				widget_user_text_color: '#ffffff',
				widget_bot_bubble_color: '#f3f4f6',
				widget_bot_text_color: '#1f2937',
				widget_tab_background_color: '#ffffff',
				widget_tab_text_color: '#374151',
				widget_tab_active_color: '#10b981',
				widget_tab_active_text_color: '#10b981',
			},
			ocean: {
				widget_primary_color: '#0ea5e9',
				widget_primary_hover_color: '#0284c7',
				widget_primary_text_color: '#ffffff',
				widget_background_color: '#ffffff',
				widget_background_alt_color: '#f0f9ff',
				widget_text_color: '#0f172a',
				widget_text_muted_color: '#475569',
				widget_border_color: '#dbeafe',
				widget_user_bubble_color: '#0ea5e9',
				widget_user_text_color: '#ffffff',
				widget_bot_bubble_color: '#e0f2fe',
				widget_bot_text_color: '#0f172a',
				widget_tab_background_color: '#ffffff',
				widget_tab_text_color: '#334155',
				widget_tab_active_color: '#0284c7',
				widget_tab_active_text_color: '#0284c7',
			},
			sunset: {
				widget_primary_color: '#f97316',
				widget_primary_hover_color: '#ea580c',
				widget_primary_text_color: '#ffffff',
				widget_background_color: '#fffdf8',
				widget_background_alt_color: '#fff7ed',
				widget_text_color: '#431407',
				widget_text_muted_color: '#7c2d12',
				widget_border_color: '#fed7aa',
				widget_user_bubble_color: '#f97316',
				widget_user_text_color: '#ffffff',
				widget_bot_bubble_color: '#ffedd5',
				widget_bot_text_color: '#431407',
				widget_tab_background_color: '#fffdf8',
				widget_tab_text_color: '#7c2d12',
				widget_tab_active_color: '#ea580c',
				widget_tab_active_text_color: '#ea580c',
			},
			slate: {
				widget_primary_color: '#334155',
				widget_primary_hover_color: '#1e293b',
				widget_primary_text_color: '#ffffff',
				widget_background_color: '#ffffff',
				widget_background_alt_color: '#f8fafc',
				widget_text_color: '#0f172a',
				widget_text_muted_color: '#475569',
				widget_border_color: '#cbd5e1',
				widget_user_bubble_color: '#334155',
				widget_user_text_color: '#ffffff',
				widget_bot_bubble_color: '#e2e8f0',
				widget_bot_text_color: '#0f172a',
				widget_tab_background_color: '#ffffff',
				widget_tab_text_color: '#334155',
				widget_tab_active_color: '#1e293b',
				widget_tab_active_text_color: '#1e293b',
			},
		};

		function toRgb( hex ) {
			if ( typeof hex !== 'string' || ! /^#[0-9a-fA-F]{6}$/.test( hex ) ) return null;
			return {
				r: parseInt( hex.substr( 1, 2 ), 16 ),
				g: parseInt( hex.substr( 3, 2 ), 16 ),
				b: parseInt( hex.substr( 5, 2 ), 16 ),
			};
		}

		function rgba( hex, alpha ) {
			var rgb = toRgb( hex );
			if ( ! rgb ) return 'rgba(0,0,0,' + alpha + ')';
			return 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
		}

		function getInputValue( key ) {
			var input = $( 'input[data-kivor-style-color="' + key + '"]' );
			return input ? input.value : '';
		}

		function toLinear( channel ) {
			var c = channel / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		}

		function luminance( hex ) {
			var rgb = toRgb( hex );
			if ( ! rgb ) return 0;
			return 0.2126 * toLinear( rgb.r ) + 0.7152 * toLinear( rgb.g ) + 0.0722 * toLinear( rgb.b );
		}

		function contrastRatio( fg, bg ) {
			var l1 = luminance( fg );
			var l2 = luminance( bg );
			var lighter = Math.max( l1, l2 );
			var darker = Math.min( l1, l2 );
			return ( lighter + 0.05 ) / ( darker + 0.05 );
		}

		function renderPreview() {
			if ( ! preview ) return;

			Object.keys( previewVarMap ).forEach( function ( key ) {
				var val = getInputValue( key );
				if ( /^#[0-9a-fA-F]{6}$/.test( val ) ) {
					preview.style.setProperty( previewVarMap[ key ], val );
				}
			} );

			var primary = getInputValue( 'widget_primary_color' );
			if ( previewIntro && /^#[0-9a-fA-F]{6}$/.test( primary ) ) {
				previewIntro.style.background = 'linear-gradient(180deg, ' + rgba( primary, 0.14 ) + ' 0%, #ffffff 100%)';
			}

			var active = getInputValue( 'widget_tab_active_color' );
			if ( previewActiveTab && /^#[0-9a-fA-F]{6}$/.test( active ) ) {
				previewActiveTab.style.background = rgba( active, 0.14 );
			}
		}

		function setContrastState( checkKey, ratio ) {
			var item = $( '[data-kivor-contrast-check="' + checkKey + '"]' );
			if ( ! item ) return;

			var rounded = Math.round( ratio * 100 ) / 100;
			var pass = ratio >= 4.5;
			item.classList.remove( 'is-ok', 'is-warn' );
			item.classList.add( pass ? 'is-ok' : 'is-warn' );
			item.setAttribute( 'data-kivor-contrast-result', ( pass ? 'AA Pass ' : 'Low ') + rounded + ':1' );
		}

		function runContrastChecks() {
			setContrastState(
				'primary_text',
				contrastRatio( getInputValue( 'widget_primary_text_color' ), getInputValue( 'widget_primary_color' ) )
			);

			setContrastState(
				'body_text',
				contrastRatio( getInputValue( 'widget_text_color' ), getInputValue( 'widget_background_color' ) )
			);

			setContrastState(
				'muted_text',
				contrastRatio( getInputValue( 'widget_text_muted_color' ), getInputValue( 'widget_background_alt_color' ) )
			);

			setContrastState(
				'user_bubble',
				contrastRatio( getInputValue( 'widget_user_text_color' ), getInputValue( 'widget_user_bubble_color' ) )
			);

			setContrastState(
				'bot_bubble',
				contrastRatio( getInputValue( 'widget_bot_text_color' ), getInputValue( 'widget_bot_bubble_color' ) )
			);
		}

		function syncTextInput( colorInput ) {
			var textInput = colorInput.nextElementSibling;
			if ( ! textInput || textInput.tagName !== 'INPUT' || textInput.type !== 'text' ) return;
			textInput.value = colorInput.value;
		}

		function renderAll() {
			renderPreview();
			runContrastChecks();
		}

		colorInputs.forEach( function ( colorInput ) {
			colorInput.addEventListener( 'input', function () {
				syncTextInput( colorInput );
				renderAll();
			} );
			syncTextInput( colorInput );
		} );

		$$( '[data-kivor-preset]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var preset = btn.getAttribute( 'data-kivor-preset' );
				var presetColors = presetMap[ preset ] || {};

				colorInputs.forEach( function ( colorInput ) {
					var key = colorInput.getAttribute( 'data-kivor-style-color' );
					if ( ! key ) return;

					if ( preset === 'default' ) {
						colorInput.value = colorInput.getAttribute( 'data-kivor-default-color' ) || colorInput.value;
					} else if ( presetColors[ key ] ) {
						colorInput.value = presetColors[ key ];
					}

					syncTextInput( colorInput );
				} );

				renderAll();
			} );
		} );

		renderAll();
	}

	// =========================================================================
	// KB Delete via AJAX
	// =========================================================================

	function initKBDelete() {
		$$( '.kivor-chat-agent-kb-delete' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var id = btn.dataset.id;

				if ( ! confirm( 'Delete this article?' ) ) return;

				setLoading( btn, true );

				apiFetch( 'knowledge-base/' + id, { method: 'DELETE' } ).then( function ( data ) {
					if ( data.success ) {
						window.location.reload();
					} else {
						setLoading( btn, false );
						alert( formatApiMessage( data, 'Delete failed.' ) );
					}
				} ).catch( function ( err ) {
					setLoading( btn, false );
					alert( 'Request failed: ' + formatErrorMessage( err ) );
				} );
			} );
		} );
	}

	function initKnowledgeComposer() {
		var modal = $( '#kivor-chat-agent-kb-modal' );
		var composer = $( '#kivor-chat-agent-kb-composer' );
		var toggleBtn = $( '#kivor-chat-agent-new-knowledge-toggle' );
		var closeBtn = $( '#kivor-chat-agent-kb-modal-close' );
		var cancelBtn = $( '#kivor-chat-agent-kb-modal-cancel' );
		var modalTitle = $( '#kivor-chat-agent-kb-modal-title' );
		var editorTitle = $( '#kivor-chat-agent-kb-editor-title' );
		var sourceSelector = $( '#kivor-chat-agent-source-selector' );
		if ( !modal || !composer || !toggleBtn || !sourceSelector ) return;

		var manualPane = $( '#kivor-chat-agent-manual-pane' );
		var externalPane = $( '#kivor-chat-agent-external-pane' );
		var sourceTypeInput = $( '#kb_source_type' );
		var sourceIdInput = $( '#kb_source_id' );
		var sourceUrlInput = $( '#kb_source_url' );
		var importMethodInput = $( '#kb_import_method' );
		var syncIntervalInput = $( '#kb_sync_interval' );
		var idInput = $( '#kb_id' );
		var titleInput = $( '#kb_title' );
		var contentInput = $( '#kb_content' );
		var saveBtn = $( '#kivor-chat-agent-kb-save' );

		var scanBtn = $( '#kivor-chat-agent-source-scan-btn' );
		var importSelectedBtn = $( '#kivor-chat-agent-source-import-selected-btn' );
		var importAllBtn = $( '#kivor-chat-agent-source-import-all-btn' );
		var scanResultEl = $( '#kivor-chat-agent-source-result' );
		var jobStatusEl = $( '#kivor-chat-agent-source-job-status' );
		var scanWrap = $( '#kivor-chat-agent-scan-list-wrap' );
		var scanBody = $( '#kivor-chat-agent-scan-list-body' );
		var checkAll = $( '#kivor-chat-agent-scan-check-all' );
		var syncIntervalSelect = $( '#kivor-chat-agent-source-sync-interval' );
		var manualReviewWrap = $( '#kivor-chat-agent-manual-review-wrap' );
		var manualReviewList = $( '#kivor-chat-agent-manual-review-list' );

		var zendeskOverride = $( '#kb_zendesk_override_credentials' );
		var notionOverride = $( '#kb_notion_override_credentials' );

		var scanItems = [];
		var currentJobPoll = null;
		var isEditMode = false;

		function clearJobPoll() {
			if ( currentJobPoll ) {
				window.clearInterval( currentJobPoll );
				currentJobPoll = null;
			}
		}

		function openModal() {
			modal.style.display = 'block';
			document.body.classList.add( 'kivor-chat-agent-modal-open' );
			toggleBtn.setAttribute( 'aria-expanded', 'true' );
		}

		function closeModal() {
			modal.style.display = 'none';
			document.body.classList.remove( 'kivor-chat-agent-modal-open' );
			toggleBtn.setAttribute( 'aria-expanded', 'false' );
			resetEditorForm();
		}

		function mapUiSourceToKbSource( source ) {
			if ( source === 'wordpress_posts' ) return 'wp_post';
			if ( source === 'wordpress_pages' ) return 'wp_page';
			return source || 'manual';
		}

		function mapKbSourceToUiSource( source ) {
			if ( source === 'wp_post' ) return 'wordpress_posts';
			if ( source === 'wp_page' ) return 'wordpress_pages';
			return source || 'manual';
		}

		function resetEditorForm() {
			isEditMode = false;
			if ( modalTitle ) modalTitle.textContent = 'New Knowledge';
			if ( editorTitle ) editorTitle.textContent = 'Manual Knowledge';
			if ( saveBtn ) saveBtn.textContent = 'Save Knowledge';
			if ( idInput ) idInput.value = '';
			if ( titleInput ) titleInput.value = '';
			if ( contentInput ) {
				contentInput.value = '';
				contentInput.dispatchEvent( new Event( 'input' ) );
			}
			if ( sourceTypeInput ) sourceTypeInput.value = 'manual';
			if ( sourceIdInput ) sourceIdInput.value = '';
			if ( sourceUrlInput ) sourceUrlInput.value = '';
			if ( importMethodInput ) importMethodInput.value = 'manual';
			if ( syncIntervalInput ) syncIntervalInput.value = 'manual';
			if ( sourceSelector ) {
				sourceSelector.disabled = false;
				sourceSelector.value = 'manual';
			}
			if ( zendeskOverride ) zendeskOverride.checked = false;
			if ( notionOverride ) notionOverride.checked = false;
			if ( syncIntervalSelect ) syncIntervalSelect.value = 'manual';
			applySourceUI();
		}

		function getSelectedSource() {
			return sourceSelector.value || 'manual';
		}

		function isSelectedSourceLocked() {
			if ( !sourceSelector ) return false;
			var selected = sourceSelector.options[ sourceSelector.selectedIndex ];
			return !!( selected && selected.disabled );
		}

		function isManualSource( source ) {
			return source === 'manual';
		}

		function updateOverrideFieldsVisibility() {
			var source = getSelectedSource();
			$$( '[data-source-override-fields]' ).forEach( function (el) {
				var target = el.getAttribute( 'data-source-override-fields' );
				if ( target === 'zendesk' ) {
					el.style.display = source === 'zendesk' && zendeskOverride && zendeskOverride.checked ? '' : 'none';
					return;
				}
				if ( target === 'notion' ) {
					el.style.display = source === 'notion' && notionOverride && notionOverride.checked ? '' : 'none';
					return;
				}
			} );
		}

		function getCredentials( source ) {
			if ( source === 'zendesk' ) {
				var useSavedZendesk = !( zendeskOverride && zendeskOverride.checked );
				if ( useSavedZendesk ) {
					return { use_saved_credentials: true };
				}

				return {
					use_saved_credentials: false,
					subdomain: ( $( '#kb_zendesk_subdomain' ) || {} ).value || '',
					email: ( $( '#kb_zendesk_email' ) || {} ).value || '',
					api_token: ( $( '#kb_zendesk_api_token' ) || {} ).value || '',
				};
			}

			if ( source === 'notion' ) {
				var useSavedNotion = !( notionOverride && notionOverride.checked );
				if ( useSavedNotion ) {
					return { use_saved_credentials: true };
				}

				return {
					use_saved_credentials: false,
					api_key: ( $( '#kb_notion_api_key' ) || {} ).value || '',
					database_id: ( $( '#kb_notion_database_id' ) || {} ).value || '',
				};
			}


			return {};
		}

		function updateImportButtons() {
			var checked = $$( 'input.kivor-chat-agent-scan-item:checked', scanBody ).length;
			importSelectedBtn.disabled = checked === 0;
			importAllBtn.disabled = scanItems.length === 0;
		}

		function statusLabel( item ) {
			if ( item.new_data ) return 'New Data';
			if ( item.imported ) return 'Imported';
			return 'Not Imported';
		}

		function renderScanRows( items ) {
			scanItems = items || [];
			scanBody.innerHTML = '';

			if ( !scanItems.length ) {
				scanWrap.style.display = 'none';
				importSelectedBtn.disabled = true;
				importAllBtn.disabled = true;
				return;
			}

			scanItems.forEach( function ( item, idx ) {
				var selectable = !item.imported || !!item.new_data;
				var tr = document.createElement( 'tr' );
				tr.innerHTML =
					'<th class="check-column"><input type="checkbox" class="kivor-chat-agent-scan-item" data-index="' + idx + '"' + ( selectable ? '' : ' disabled' ) + '></th>' +
					'<td>' + escapeHtml( item.title || 'Untitled' ) + '</td>' +
					'<td>' + escapeHtml( statusLabel( item ) ) + '</td>' +
					'<td>' + escapeHtml( item.source_url || item.source_id || '-' ) + '</td>';
				scanBody.appendChild( tr );
			} );

			scanWrap.style.display = '';
			updateImportButtons();
		}

		function getSelectedItems() {
			var indexes = $$( 'input.kivor-chat-agent-scan-item:checked', scanBody ).map( function ( el ) {
				return parseInt( el.dataset.index || '-1', 10 );
			} );

			return indexes
				.filter( function ( i ) { return i >= 0 && i < scanItems.length; } )
				.map( function ( i ) { return scanItems[ i ]; } );
		}

		function pollJobStatus( jobId ) {
			clearJobPoll();
			currentJobPoll = window.setInterval( function () {
				apiFetch( 'knowledge-base/import-status?job_id=' + encodeURIComponent( jobId ) )
					.then( function ( data ) {
						if ( !data.success || !data.status ) return;
						var status = data.status;
						showResult( jobStatusEl, true, 'Background import: ' + status.processed + '/' + status.total + ' processed, ' + status.success + ' success, ' + status.failed + ' failed.' );

						if ( status.status === 'completed' || status.status === 'completed_with_errors' || status.status === 'failed' ) {
							clearJobPoll();
							if ( status.status === 'completed_with_errors' ) {
								showResult( jobStatusEl, false, 'Import finished with some failures. Failed items moved to Manual Review Queue.' );
							}
							loadManualReviewQueue();
						}
					} )
					.catch( function () {
						clearJobPoll();
					} );
			}, 3000 );
		}

		function runImport( items, method ) {
			if ( !items.length ) {
				showResult( scanResultEl, false, 'No items selected.' );
				return;
			}

			var hasNewData = items.some( function ( item ) { return !!item.new_data; } );
			if ( hasNewData ) {
				var confirmed = window.confirm( 'Some selected items have new data and will overwrite existing knowledge content. Continue?' );
				if ( !confirmed ) {
					showResult( scanResultEl, false, 'Import cancelled. Overwrite not confirmed.' );
					return;
				}
			}

			setLoading( method === 'bulk' ? importAllBtn : importSelectedBtn, true );
			apiFetch( 'knowledge-base/import-source', {
				method: 'POST',
				body: {
					source_type: getSelectedSource(),
					items: items,
					import_method: method,
					sync_interval: syncIntervalSelect ? syncIntervalSelect.value : 'manual',
					confirm_overwrite: hasNewData,
				},
			} ).then( function ( data ) {
				setLoading( importSelectedBtn, false );
				setLoading( importAllBtn, false );

				if ( !data.success || !data.job ) {
					showResult( scanResultEl, false, formatApiMessage( data, 'Failed to queue import.' ) );
					return;
				}

				showResult( scanResultEl, true, 'Import queued for ' + data.job.total + ' item(s).' );
				pollJobStatus( data.job.job_id );
			} ).catch( function ( err ) {
				setLoading( importSelectedBtn, false );
				setLoading( importAllBtn, false );
				showResult( scanResultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
			} );
		}

		function renderManualReview(items) {
			manualReviewList.innerHTML = '';
			if ( !items || !items.length ) {
				manualReviewWrap.style.display = 'none';
				return;
			}

			manualReviewWrap.style.display = '';
			items.forEach( function (item) {
				var row = document.createElement( 'div' );
				row.className = 'kivor-chat-agent-manual-review-item';
				row.innerHTML = '<strong>' + escapeHtml( item.title || 'Untitled' ) + '</strong> <span>(' + escapeHtml( item.source_type || '' ) + ' / ' + escapeHtml( item.source_id || '' ) + ')</span>';
				manualReviewList.appendChild( row );
			} );
		}

		function loadManualReviewQueue() {
			apiFetch( 'knowledge-base/manual-review' )
				.then( function ( data ) {
					if ( data.success ) {
						renderManualReview( data.items || [] );
					}
				} )
				.catch( function () {} );
		}

		function showCredentialsForSource( source ) {
			$$( '[data-source-credentials]' ).forEach( function (el) {
				el.style.display = el.getAttribute( 'data-source-credentials' ) === source ? '' : 'none';
			} );
			updateOverrideFieldsVisibility();
		}

		function applySourceUI() {
			var source = getSelectedSource();
			var sourceLocked = isSelectedSourceLocked();

			clearJobPoll();
			if ( jobStatusEl ) {
				jobStatusEl.style.display = 'none';
				jobStatusEl.textContent = '';
			}
			renderScanRows( [] );

			if ( isEditMode ) {
				manualPane.style.display = '';
				externalPane.style.display = 'none';
				return;
			}

			if ( sourceTypeInput ) {
				sourceTypeInput.value = mapUiSourceToKbSource( source );
			}

			if ( isManualSource( source ) ) {
				if ( sourceIdInput ) sourceIdInput.value = '';
				if ( sourceUrlInput ) sourceUrlInput.value = '';
				if ( importMethodInput ) importMethodInput.value = 'manual';
				if ( syncIntervalInput ) syncIntervalInput.value = 'manual';
				manualPane.style.display = '';
				externalPane.style.display = 'none';
				return;
			}

			manualPane.style.display = 'none';
			externalPane.style.display = '';
			if ( scanBtn ) {
				scanBtn.disabled = sourceLocked;
			}
			showCredentialsForSource( source );
			loadManualReviewQueue();
		}

		function populateForEdit( article ) {
			isEditMode = true;
			if ( modalTitle ) modalTitle.textContent = 'Edit Knowledge';
			if ( editorTitle ) editorTitle.textContent = 'Edit Article';
			if ( saveBtn ) saveBtn.textContent = 'Update Knowledge';
			if ( idInput ) idInput.value = String( article.id || '' );
			if ( titleInput ) titleInput.value = article.title || '';
			if ( contentInput ) {
				contentInput.value = article.content || '';
				contentInput.dispatchEvent( new Event( 'input' ) );
			}
			if ( sourceTypeInput ) sourceTypeInput.value = article.source_type || 'manual';
			if ( sourceIdInput ) sourceIdInput.value = article.source_id || '';
			if ( sourceUrlInput ) sourceUrlInput.value = article.source_url || '';
			if ( importMethodInput ) importMethodInput.value = article.import_method || 'manual';
			if ( syncIntervalInput ) syncIntervalInput.value = article.sync_interval || 'manual';

			if ( sourceSelector ) {
				sourceSelector.value = mapKbSourceToUiSource( article.source_type || 'manual' );
				sourceSelector.disabled = true;
			}

			applySourceUI();
			openModal();
		}

		toggleBtn.addEventListener( 'click', function () {
			resetEditorForm();
			openModal();
		} );

		if ( closeBtn ) closeBtn.addEventListener( 'click', closeModal );
		if ( cancelBtn ) cancelBtn.addEventListener( 'click', closeModal );

		var backdrop = $( '.kivor-chat-agent-kb-modal__backdrop', modal );
		if ( backdrop ) {
			backdrop.addEventListener( 'click', closeModal );
		}

		document.addEventListener( 'keydown', function (e) {
			if ( e.key === 'Escape' && modal.style.display === 'block' ) {
				closeModal();
			}
		} );

		sourceSelector.addEventListener( 'change', applySourceUI );

		if ( zendeskOverride ) zendeskOverride.addEventListener( 'change', updateOverrideFieldsVisibility );
		if ( notionOverride ) notionOverride.addEventListener( 'change', updateOverrideFieldsVisibility );

		scanBtn.addEventListener( 'click', function (e) {
			e.preventDefault();
			if ( isSelectedSourceLocked() ) {
				showResult( scanResultEl, false, 'This source is available in Pro.' );
				return;
			}
			setLoading( scanBtn, true );
			showResult( scanResultEl, true, 'Scanning source...' );

			var source = getSelectedSource();
			var body = { source_type: source };
			var credentials = getCredentials( source );
			Object.keys( credentials ).forEach( function ( key ) {
				body[ key ] = credentials[ key ];
			} );

			apiFetch( 'knowledge-base/scan-source', {
				method: 'POST',
				body: body,
			} ).then( function ( data ) {
				setLoading( scanBtn, false );
				if ( !data.success ) {
					renderScanRows( [] );
					showResult( scanResultEl, false, formatApiMessage( data, 'Failed to scan source.' ) );
					return;
				}

				renderScanRows( data.items || [] );
				showResult( scanResultEl, true, 'Scan complete: ' + ( data.count || 0 ) + ' item(s) found.' );
			} ).catch( function ( err ) {
				setLoading( scanBtn, false );
				renderScanRows( [] );
				showResult( scanResultEl, false, 'Request failed: ' + formatErrorMessage( err ) );
			} );
		} );

		importSelectedBtn.addEventListener( 'click', function (e) {
			e.preventDefault();
			runImport( getSelectedItems(), 'individual' );
		} );

		importAllBtn.addEventListener( 'click', function (e) {
			e.preventDefault();
			runImport( scanItems.slice(), 'bulk' );
		} );

		if ( checkAll ) {
			checkAll.addEventListener( 'change', function () {
				$$( 'input.kivor-chat-agent-scan-item', scanBody ).forEach( function ( cb ) {
					if ( cb.disabled ) return;
					cb.checked = !!checkAll.checked;
				} );
				updateImportButtons();
			} );
		}

		scanBody.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'kivor-chat-agent-scan-item' ) ) {
				updateImportButtons();
			}
		} );

		$$( '.kivor-chat-agent-kb-edit' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var id = parseInt( btn.dataset.id || '0', 10 );
				if ( !id ) return;

				setLoading( btn, true );
				apiFetch( 'knowledge-base/' + id, { method: 'GET' } )
					.then( function ( data ) {
						setLoading( btn, false );
						if ( !data.success || !data.article ) {
							alert( formatApiMessage( data, 'Failed to load article.' ) );
							return;
						}
						populateForEdit( data.article );
					} )
					.catch( function ( err ) {
						setLoading( btn, false );
						alert( 'Request failed: ' + formatErrorMessage( err ) );
					} );
			} );
		} );

		function escapeHtml( text ) {
			return String( text )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#39;' );
		}

		resetEditorForm();
	}

	// =========================================================================
	// Forms Tab
	// =========================================================================

	function initFormsTab() {
		var modal = $( '#kivor-chat-agent-form-modal' );
		var createBtn = $( '#kivor-chat-agent-create-form' );

		var modalTitle = $( '#kivor-chat-agent-form-modal-title' );
		var closeBtn = $( '#kivor-chat-agent-form-modal-close' );
		var cancelBtn = $( '#kivor-chat-agent-form-modal-cancel' );
		var saveBtn = $( '#kivor-chat-agent-form-modal-save' );
		var addFieldBtn = $( '#kivor_form_add_field' );
		var fieldsWrap = $( '#kivor_form_fields' );
		var nameInput = $( '#kivor_form_name' );
		var triggerInstructionsInput = $( '#kivor_form_trigger_instructions' );
		var aiEligibleInput = $( '#kivor_form_is_ai_eligible' );
		var triggerInstructionsPanel = $( '#kivor-chat-agent-trigger-instructions-panel' );
		var triggerTemplateButtons = $$( '[data-trigger-template]' );

		var rowsWrap = $( '#kivor-chat-agent-forms-rows' );
		var table = $( '#kivor-chat-agent-forms-table' );
		var empty = $( '#kivor-chat-agent-forms-empty' );
		var exportBtn = $( '#kivor-chat-agent-export-form-submissions' );
		var submissionsRows = $( '#kivor-chat-agent-form-submissions-rows' );

		var editingId = 0;

		function openModal() {
			if ( ! modal ) return;
			modal.style.display = 'block';
			document.body.classList.add( 'kivor-chat-agent-modal-open' );
		}

		function closeModal() {
			if ( ! modal ) return;
			modal.style.display = 'none';
			document.body.classList.remove( 'kivor-chat-agent-modal-open' );
		}

		function getTriggerTemplate( key ) {
			if ( key === 'refund' ) {
				return [
					'Show this form only when the user explicitly asks for a refund, return, cancellation, or damaged item resolution.',
					'Do not show this form for order tracking, delivery date questions, or product recommendations.',
					'If user intent is unclear, ask one clarifying question before showing the form.',
				].join( '\n' );
			}
			if ( key === 'support' ) {
				return [
					'Show this form only for technical support issues, account-access problems, or troubleshooting requests.',
					'Do not show this form for sales inquiries, pricing questions, or basic product availability questions.',
					'If the user asks a simple factual question, answer directly instead of showing the form.',
				].join( '\n' );
			}
			if ( key === 'lead' ) {
				return [
					'Show this form only when the user is ready to request a quote, demo, consultation, or direct follow-up from sales.',
					'Do not show this form during early browsing, casual questions, or general support conversations.',
					'Only trigger after clear buying intent is expressed.',
				].join( '\n' );
			}
			return '';
		}

		function toggleTriggerInstructionsUI() {
			var enabled = !!( aiEligibleInput && aiEligibleInput.checked );
			if ( triggerInstructionsPanel ) {
				triggerInstructionsPanel.style.display = enabled ? '' : 'none';
				triggerInstructionsPanel.classList.toggle( 'is-disabled', !enabled );
			}
			if ( triggerInstructionsInput ) {
				triggerInstructionsInput.disabled = !enabled;
			}
			if ( triggerTemplateButtons && triggerTemplateButtons.length ) {
				triggerTemplateButtons.forEach( function (btn) {
					btn.disabled = !enabled;
				} );
			}
		}

		function resetFormBuilder() {
			editingId = 0;
			if ( modalTitle ) modalTitle.textContent = 'Create Form';
			if ( nameInput ) nameInput.value = '';
			if ( triggerInstructionsInput ) triggerInstructionsInput.value = '';
			if ( aiEligibleInput ) aiEligibleInput.checked = true;
			toggleTriggerInstructionsUI();
			if ( fieldsWrap ) fieldsWrap.innerHTML = '';
			addFieldRow();
		}

		function slugifyFieldName( value ) {
			return String( value || '' )
				.toLowerCase()
				.trim()
				.replace( /[^a-z0-9\s_]/g, '' )
				.replace( /\s+/g, '_' )
				.replace( /_+/g, '_' )
				.replace( /^_+|_+$/g, '' );
		}

		function getDefaultPlaceholder( type, label ) {
			if ( type === 'email' ) {
				return 'name@example.com';
			}
			if ( type === 'phone' ) {
				return '+1 (555) 123-4567';
			}
			if ( type === 'textarea' ) {
				return label ? 'Type your ' + label.toLowerCase() : 'Type your message';
			}
			if ( type === 'select' ) {
				return '';
			}
			if ( type === 'checkbox' ) {
				return '';
			}
			return label ? 'Enter ' + label.toLowerCase() : 'Enter value';
		}

		function getTypeDefaults( type ) {
			switch ( type ) {
				case 'email':
					return { label: 'Email address', name: 'email_address', placeholder: 'name@example.com', min: 0, max: 254, options: '' };
				case 'phone':
					return { label: 'Phone number', name: 'phone_number', placeholder: '+1 (555) 123-4567', min: 7, max: 24, options: '' };
				case 'textarea':
					return { label: 'Message', name: 'message', placeholder: 'Type your message', min: 0, max: 2000, options: '' };
				case 'select':
					return { label: 'Select option', name: 'select_option', placeholder: '', min: 0, max: 255, options: 'Option 1, Option 2' };
				case 'checkbox':
					return { label: 'I agree to terms', name: 'agree_to_terms', placeholder: '', min: 0, max: 0, options: '' };
				default:
					return { label: 'Full name', name: 'full_name', placeholder: 'Enter full name', min: 0, max: 255, options: '' };
			}
		}

		function getTypeValidationHint( type ) {
			switch ( type ) {
				case 'email':
					return 'Validation: checks a valid email format (example@domain.com).';
				case 'phone':
					return 'Validation: checks phone input contains at least 7 digits.';
				case 'select':
					return 'Validation: requires one option from your select list.';
				case 'checkbox':
					return 'Validation: when required, user must check this box.';
				case 'textarea':
					return 'Validation: supports min/max length with multiline text.';
				default:
					return 'Validation: supports min/max length text input.';
			}
		}

		function setRowValidation( row, message ) {
			var note = $( '.kivor-field-validation-note', row );
			if ( !note ) {
				return;
			}

			note.textContent = message || '';
			note.classList.toggle( 'is-error', !!message );
		}

		function addFieldRow( field ) {
			if ( ! fieldsWrap ) return;

			field = field || {
				type: 'text',
				label: '',
				name: '',
				required: false,
				placeholder: '',
				options: [],
				min_length: 0,
				max_length: 255,
			};

			var row = document.createElement( 'div' );
			row.className = 'kivor-chat-agent-form-field-row';
			row.innerHTML =
				'<div class="kivor-chat-agent-form-field-head">' +
				'<strong class="kivor-chat-agent-form-field-title">Field</strong>' +
				'<button type="button" class="button button-link-delete kivor-remove-field">Remove field</button>' +
				'</div>' +
				'<div class="kivor-chat-agent-form-field-grid">' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--type"><label>Type</label><select class="kivor-field-type">' +
				'<option value="text">text</option>' +
				'<option value="email">email</option>' +
				'<option value="phone">phone</option>' +
				'<option value="textarea">textarea</option>' +
				'<option value="select">select</option>' +
				'<option value="checkbox">checkbox</option>' +
				'</select></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--label"><label>Label</label><input type="text" class="kivor-field-label regular-text" placeholder="Full name"></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--name"><label>Name</label><input type="text" class="kivor-field-name regular-text" placeholder="full_name"></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--placeholder"><label>Placeholder</label><input type="text" class="kivor-field-placeholder regular-text" placeholder="Enter full name"></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--min kivor-field-min-wrap"><label>Min length</label><input type="number" min="0" class="kivor-field-min small-text"></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--max kivor-field-max-wrap"><label>Max length</label><input type="number" min="0" class="kivor-field-max small-text"></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--options kivor-field-options-wrap"><label>Select options</label><input type="text" class="kivor-field-options regular-text" placeholder="Option 1, Option 2"></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--required"><label><input type="checkbox" class="kivor-field-required"> Required</label></div>' +
				'<div class="kivor-chat-agent-form-field-item kivor-chat-agent-form-field-item--validation"><span class="kivor-field-validation-note"></span></div>' +
				'</div>';

			fieldsWrap.appendChild( row );

			var typeEl = $( '.kivor-field-type', row );
			var labelEl = $( '.kivor-field-label', row );
			var nameEl = $( '.kivor-field-name', row );
			var placeholderEl = $( '.kivor-field-placeholder', row );
			var optionsWrap = $( '.kivor-field-options-wrap', row );
			var optionsEl = $( '.kivor-field-options', row );
			var minWrap = $( '.kivor-field-min-wrap', row );
			var minEl = $( '.kivor-field-min', row );
			var maxWrap = $( '.kivor-field-max-wrap', row );
			var maxEl = $( '.kivor-field-max', row );
			var requiredEl = $( '.kivor-field-required', row );
			var nameTouched = false;
			var placeholderTouched = false;
			var labelTouched = false;
			var typeContext = {};

			typeEl.value = field.type || 'text';
			labelEl.value = field.label || '';
			nameEl.value = field.name || '';
			placeholderEl.value = field.placeholder || '';
			requiredEl.checked = !!field.required;
			minEl.value = field.min_length || 0;
			maxEl.value = field.max_length || 255;
			optionsEl.value = ( field.options || [] ).join( ', ' );
			nameTouched = !!nameEl.value;
			placeholderTouched = !!placeholderEl.value;
			labelTouched = !!labelEl.value;

			if ( !field || !field.type ) {
				var initialDefaults = getTypeDefaults( typeEl.value );
				labelEl.value = initialDefaults.label;
				nameEl.value = initialDefaults.name;
				placeholderEl.value = initialDefaults.placeholder;
				minEl.value = initialDefaults.min;
				maxEl.value = initialDefaults.max;
				if ( initialDefaults.options ) {
					optionsEl.value = initialDefaults.options;
				}
				nameTouched = false;
				placeholderTouched = false;
				labelTouched = false;
			}

			function applySmartDefaults() {
				var currentType = typeEl.value;
				if ( !nameTouched ) {
					nameEl.value = slugifyFieldName( labelEl.value );
				}
				if ( !placeholderTouched ) {
					placeholderEl.value = getDefaultPlaceholder( currentType, labelEl.value );
				}

				if ( currentType === 'email' && ( !maxEl.value || parseInt( maxEl.value, 10 ) > 254 ) ) {
					maxEl.value = 254;
				}
				if ( currentType === 'phone' ) {
					if ( !maxEl.value || parseInt( maxEl.value, 10 ) > 24 ) {
						maxEl.value = 24;
					}
					if ( parseInt( minEl.value || '0', 10 ) < 7 ) {
						minEl.value = 7;
					}
				}
				if ( currentType === 'textarea' && ( !maxEl.value || parseInt( maxEl.value, 10 ) < 200 ) ) {
					maxEl.value = 2000;
				}
			}

			function saveTypeContext( type ) {
				typeContext[ type ] = {
					label: labelEl.value,
					name: nameEl.value,
					placeholder: placeholderEl.value,
					min: minEl.value,
					max: maxEl.value,
					options: optionsEl.value,
				};
			}

			function loadTypeContext( type ) {
				if ( typeContext[ type ] ) {
					var snapshot = typeContext[ type ];
					labelEl.value = snapshot.label || '';
					nameEl.value = snapshot.name || '';
					placeholderEl.value = snapshot.placeholder || '';
					minEl.value = snapshot.min || 0;
					maxEl.value = snapshot.max || 255;
					optionsEl.value = snapshot.options || '';
					return;
				}

				var defaults = getTypeDefaults( type );
				labelEl.value = defaults.label;
				nameEl.value = defaults.name;
				placeholderEl.value = defaults.placeholder;
				minEl.value = defaults.min;
				maxEl.value = defaults.max;
				optionsEl.value = defaults.options;
			}

			function toggleTypeUI() {
				var t = typeEl.value;
				var isSelect = t === 'select';
				var isCheckbox = t === 'checkbox';

				if ( optionsWrap ) optionsWrap.style.display = isSelect ? '' : 'none';
				if ( minWrap ) minWrap.style.display = isCheckbox ? 'none' : '';
				if ( maxWrap ) maxWrap.style.display = isCheckbox ? 'none' : '';
				if ( placeholderEl ) placeholderEl.disabled = isCheckbox;
				setRowValidation( row, '' );
				var note = $( '.kivor-field-validation-note', row );
				if ( note ) {
					note.textContent = getTypeValidationHint( t );
					note.classList.remove( 'is-error' );
				}
				applySmartDefaults();
			}

			var previousType = typeEl.value;
			typeEl.addEventListener( 'change', function () {
				saveTypeContext( previousType );
				loadTypeContext( typeEl.value );
				nameTouched = false;
				placeholderTouched = false;
				labelTouched = false;
				previousType = typeEl.value;
				toggleTypeUI();
			} );
			labelEl.addEventListener( 'input', function () {
				labelTouched = labelEl.value.trim() !== '';
				if ( !nameTouched ) {
					nameEl.value = slugifyFieldName( labelEl.value );
				}
				if ( !placeholderTouched ) {
					placeholderEl.value = getDefaultPlaceholder( typeEl.value, labelEl.value );
				}
			} );
			nameEl.addEventListener( 'input', function () {
				nameTouched = nameEl.value.trim() !== '';
			} );
			placeholderEl.addEventListener( 'input', function () {
				placeholderTouched = placeholderEl.value.trim() !== '';
			} );
			saveTypeContext( typeEl.value );
			toggleTypeUI();

			var removeBtn = $( '.kivor-remove-field', row );
			removeBtn.addEventListener( 'click', function () {
				var rows = $$( '.kivor-chat-agent-form-field-row', fieldsWrap );
				if ( rows.length <= 1 ) return;
				row.remove();
			} );
		}

		function collectFields() {
			var rows = $$( '.kivor-chat-agent-form-field-row', fieldsWrap );
			var fields = [];
			var seenNames = {};
			var hasErrors = false;

			rows.forEach( function (row) {
				setRowValidation( row, '' );

				var type = ( $( '.kivor-field-type', row ) || {} ).value || 'text';
				var label = ( ( $( '.kivor-field-label', row ) || {} ).value || '' ).trim();
				var name = slugifyFieldName( ( $( '.kivor-field-name', row ) || {} ).value || '' );
				var placeholder = ( ( $( '.kivor-field-placeholder', row ) || {} ).value || '' ).trim();
				var required = !!( $( '.kivor-field-required', row ) || {} ).checked;
				var minLength = parseInt( ( $( '.kivor-field-min', row ) || {} ).value || '0', 10 );
				var maxLength = parseInt( ( $( '.kivor-field-max', row ) || {} ).value || '255', 10 );
				var optionsText = ( $( '.kivor-field-options', row ) || {} ).value || '';
				var options = optionsText
					.split( ',' )
					.map( function (v) { return v.trim(); } )
					.filter( function (v) { return !!v; } );

				if ( !label ) {
					hasErrors = true;
					setRowValidation( row, 'Label is required.' );
					return;
				}

				if ( !name ) {
					hasErrors = true;
					setRowValidation( row, 'Name is required and should use letters, numbers, or underscores.' );
					return;
				}

				if ( seenNames[ name ] ) {
					hasErrors = true;
					setRowValidation( row, 'Field names must be unique. "' + name + '" is duplicated.' );
					return;
				}
				seenNames[ name ] = true;
				var nameInputEl = $( '.kivor-field-name', row );
				if ( nameInputEl ) {
					nameInputEl.value = name;
				}

				if ( type === 'select' && !options.length ) {
					hasErrors = true;
					setRowValidation( row, 'Select fields require at least one option.' );
					return;
				}

				if ( type !== 'checkbox' && !isNaN( minLength ) && !isNaN( maxLength ) && maxLength > 0 && minLength > maxLength ) {
					hasErrors = true;
					setRowValidation( row, 'Min length cannot be greater than max length.' );
					return;
				}

				if ( type === 'phone' && !isNaN( minLength ) && minLength > 0 && minLength < 7 ) {
					hasErrors = true;
					setRowValidation( row, 'Phone fields should have min length 7 or greater.' );
					return;
				}

				if ( type === 'email' && !isNaN( maxLength ) && maxLength > 254 ) {
					hasErrors = true;
					setRowValidation( row, 'Email fields should not exceed max length of 254.' );
					return;
				}

				fields.push( {
					type: type,
					label: label,
					name: name,
					required: required,
					placeholder: placeholder,
					options: options,
					min_length: isNaN( minLength ) ? 0 : minLength,
					max_length: isNaN( maxLength ) ? 255 : maxLength,
				} );
			} );

			return {
				fields: fields,
				hasErrors: hasErrors,
			};
		}

		function loadForEdit( form ) {
			editingId = form.id;
			if ( modalTitle ) modalTitle.textContent = 'Edit Form';
			if ( nameInput ) nameInput.value = form.name || '';
			if ( triggerInstructionsInput ) triggerInstructionsInput.value = form.trigger_instructions || '';
			if ( aiEligibleInput ) aiEligibleInput.checked = !!form.is_ai_eligible;
			toggleTriggerInstructionsUI();
			if ( fieldsWrap ) fieldsWrap.innerHTML = '';
			( form.fields || [] ).forEach( function (field) { addFieldRow( field ); } );
			if ( !( form.fields || [] ).length ) addFieldRow();
			openModal();
		}

		function saveForm() {
			var instructions = ( triggerInstructionsInput && triggerInstructionsInput.value || '' ).trim();
			var aiEligible = !!( aiEligibleInput && aiEligibleInput.checked );

			var fieldsResult = collectFields();

			var payload = {
				name: ( nameInput && nameInput.value || '' ).trim(),
				trigger_instructions: instructions,
				is_ai_eligible: aiEligible,
				fields: fieldsResult.fields,
			};

			if ( !payload.name ) {
				alert( 'Form name is required.' );
				return;
			}

			if ( fieldsResult.hasErrors ) {
				alert( 'Please fix the field validation issues before saving.' );
				return;
			}

			if ( !payload.fields.length ) {
				alert( 'At least one field is required.' );
				return;
			}

			if ( aiEligible && !instructions ) {
				alert( 'Trigger instructions are required when AI eligible is enabled.' );
				return;
			}

			setLoading( saveBtn, true );

			var endpoint = editingId ? 'forms/' + editingId : 'forms';
			var method = editingId ? 'PUT' : 'POST';

			apiFetch( endpoint, {
				method: method,
				body: payload,
			} ).then( function () {
				window.location.reload();
			} ).catch( function (err) {
				setLoading( saveBtn, false );
				alert( 'Failed to save form: ' + formatErrorMessage( err, 'Unknown error' ) );
			} );
		}

		function bindListActions() {
			$$( '.kivor-chat-agent-edit-form' ).forEach( function (btn) {
				btn.addEventListener( 'click', function () {
					var row = btn.closest( 'tr' );
					if ( !row ) return;
					var encoded = row.getAttribute( 'data-form' );
					if ( !encoded ) return;
					var form = {};
					try {
						form = JSON.parse( encoded );
					} catch ( e ) {
						return;
					}
					loadForEdit( form );
				} );
			} );

			$$( '.kivor-chat-agent-delete-form' ).forEach( function (btn) {
				btn.addEventListener( 'click', function () {
					var row = btn.closest( 'tr' );
					if ( !row ) return;
					var formId = row.getAttribute( 'data-form-id' );
					if ( !formId ) return;

					if ( !confirm( 'Delete this form?' ) ) return;

					setLoading( btn, true );
					apiFetch( 'forms/' + formId, { method: 'DELETE' } )
						.then( function () {
							row.remove();
							if ( rowsWrap && !rowsWrap.children.length ) {
								if ( table ) table.style.display = 'none';
								if ( empty ) empty.style.display = '';
							}
						} )
					.catch( function (err) {
						setLoading( btn, false );
						alert( 'Failed to delete form: ' + formatErrorMessage( err, 'Unknown error' ) );
					} );
				} );
			} );
		}

		function loadSubmissions() {
			if ( !submissionsRows ) return;

			apiFetch( 'forms/submissions?page=1&per_page=20' )
				.then( function (data) {
					var items = data.items || [];
					submissionsRows.innerHTML = '';

					if ( !items.length ) {
						submissionsRows.innerHTML = '<tr><td colspan="4">No submissions yet.</td></tr>';
						return;
					}

					items.forEach( function (item) {
						var tr = document.createElement( 'tr' );
						tr.innerHTML =
							'<td>' + ( item.created_at || '' ) + '</td>' +
							'<td>' + escapeHtml( item.form_name || ( '#' + item.form_id ) ) + '</td>' +
							'<td><code>' + escapeHtml( item.session_id || '' ) + '</code></td>' +
							'<td><pre class="kivor-chat-agent-form-submission-pre">' + escapeHtml( JSON.stringify( item.data || {}, null, 2 ) ) + '</pre></td>';
						submissionsRows.appendChild( tr );
					} );
				} )
				.catch( function () {
					submissionsRows.innerHTML = '<tr><td colspan="4">Failed to load submissions.</td></tr>';
				} );
		}

		function bindExport() {
			if ( !exportBtn ) return;
			exportBtn.addEventListener( 'click', function (e) {
				e.preventDefault();
				setLoading( exportBtn, true );
				apiFetch( 'forms/submissions/export', { method: 'GET' } )
					.then( function (data) {
						setLoading( exportBtn, false );
						if ( !data.success || !data.data ) {
							alert( formatApiMessage( data, 'No submissions to export.' ) );
							return;
						}
						var csv = atob( data.data );
						var blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
						var link = document.createElement( 'a' );
						link.href = URL.createObjectURL( blob );
						link.download = data.filename || 'kivor-chat-agent-form-submissions.csv';
						document.body.appendChild( link );
						link.click();
						document.body.removeChild( link );
					} )
					.catch( function (err) {
						setLoading( exportBtn, false );
						alert( 'Export failed: ' + formatErrorMessage( err, 'Unknown error' ) );
					} );
			} );
		}

		function escapeHtml( text ) {
			return String( text )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#39;' );
		}

		if ( createBtn && modal ) {
			createBtn.addEventListener( 'click', function () {
				resetFormBuilder();
				openModal();
				if ( nameInput ) nameInput.focus();
			} );
		}

		if ( modal ) {
			if ( closeBtn ) closeBtn.addEventListener( 'click', closeModal );
			if ( cancelBtn ) cancelBtn.addEventListener( 'click', closeModal );

			var backdrop = $( '.kivor-chat-agent-form-modal__backdrop', modal );
			if ( backdrop ) backdrop.addEventListener( 'click', closeModal );
		}

		if ( addFieldBtn ) {
			addFieldBtn.addEventListener( 'click', function () {
				addFieldRow();
			} );
		}

		if ( aiEligibleInput ) {
			aiEligibleInput.addEventListener( 'change', toggleTriggerInstructionsUI );
		}

		if ( triggerTemplateButtons && triggerTemplateButtons.length ) {
			triggerTemplateButtons.forEach( function (btn) {
				btn.addEventListener( 'click', function () {
					if ( !triggerInstructionsInput || triggerInstructionsInput.disabled ) {
						return;
					}
					var key = btn.getAttribute( 'data-trigger-template' ) || '';
					var template = getTriggerTemplate( key );
					if ( !template ) {
						return;
					}
					triggerInstructionsInput.value = template;
					triggerInstructionsInput.dispatchEvent( new Event( 'input' ) );
					triggerInstructionsInput.focus();
				} );
			} );
		}

		toggleTriggerInstructionsUI();

		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', saveForm );
		}

		document.addEventListener( 'keydown', function (e) {
			if ( modal && e.key === 'Escape' && modal.style.display === 'block' ) {
				closeModal();
			}
		} );

		bindListActions();
		bindExport();
		loadSubmissions();
	}

	// =========================================================================
	// Init
	// =========================================================================

		document.addEventListener( 'DOMContentLoaded', function () {
		initTestConnection();
		initTestVectorStoreConnection();
		initTestEmbeddingProviderConnection();
		initScraper();
		initExportCSV();
		initClearLogs();
		initSyncEmbeddings();
		initConditionalFields();
		initAvatarUploader();
		initCharCounters();
		initStylingColorInputs();
		initKBDelete();
		initKnowledgeComposer();
			initFormsTab();
			initExternalPlatforms();
		} );
})();
