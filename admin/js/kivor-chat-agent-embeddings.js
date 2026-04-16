/**
 * Embeddings admin page actions.
 *
 * @package KivorAgent
 * @since   1.0.0
 */
(function () {
	'use strict';

	var config = window.kivorChatAgentAdmin || {};
	if ( !config.restUrl || !config.nonce ) {
		return;
	}

	function apiFetch( endpoint, options ) {
		options = options || {};
		return fetch( config.restUrl + endpoint, {
			method: options.method || 'GET',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json',
			},
			credentials: 'same-origin',
			body: options.body ? JSON.stringify( options.body ) : undefined,
		} ).then( function ( res ) {
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

	function formatApiMessage( data, fallback ) {
		var message = data && data.message ? data.message : ( fallback || 'Done.' );
		if ( data && data.upgrade_url ) {
			message += ' Upgrade: ' + data.upgrade_url;
		}
		return message;
	}

	function formatErrorMessage( err, fallback ) {
		var message = err && err.message ? err.message : ( fallback || 'Request failed.' );
		if ( err && err.payload && err.payload.upgrade_url ) {
			message += ' Upgrade: ' + err.payload.upgrade_url;
		}
		return message;
	}

	document.querySelectorAll( '.kivor-embeddings-sync-one' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var id = parseInt( btn.getAttribute( 'data-id' ) || '0', 10 );
			var type = btn.getAttribute( 'data-type' ) || 'product';

			if ( !id ) {
				return;
			}

			btn.disabled = true;
			apiFetch( 'embeddings/sync', {
				method: 'POST',
				body: {
					object_type: type,
					object_id: id,
				},
			} ).then( function ( data ) {
				btn.disabled = false;
				alert( formatApiMessage( data, 'Done.' ) );
				if ( data && data.success ) {
					window.location.reload();
				}
			} ).catch( function ( err ) {
				btn.disabled = false;
				alert( formatErrorMessage( err, 'Request failed.' ) );
			} );
		} );
	} );

	document.querySelectorAll( '.kivor-embeddings-delete-one' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var id = parseInt( btn.getAttribute( 'data-id' ) || '0', 10 );
			var type = btn.getAttribute( 'data-type' ) || 'product';

			if ( !id ) {
				return;
			}

			if ( !window.confirm( 'Delete this embedding?' ) ) {
				return;
			}

			btn.disabled = true;
			apiFetch( 'embeddings/delete', {
				method: 'POST',
				body: {
					object_type: type,
					object_id: id,
				},
			} ).then( function ( data ) {
				btn.disabled = false;
				alert( formatApiMessage( data, 'Done.' ) );
				if ( data && data.success ) {
					window.location.reload();
				}
			} ).catch( function ( err ) {
				btn.disabled = false;
				alert( formatErrorMessage( err, 'Request failed.' ) );
			} );
		} );
	} );

	function getSyncStatus() {
		return apiFetch( 'sync-status', { method: 'GET' } );
	}

	function setProgressUI( visible, progress, total, message, syncType ) {
		var wrap = document.getElementById( 'kivor-embeddings-progress-wrap' );
		var bar = document.getElementById( 'kivor-embeddings-progress-bar' );
		var label = document.getElementById( 'kivor-embeddings-progress-label' );
		var meta = document.getElementById( 'kivor-embeddings-progress-meta' );
		var badge = document.getElementById( 'kivor-embeddings-progress-type' );
		if ( !wrap || !bar || !label || !meta ) {
			return;
		}

		wrap.style.display = visible ? 'block' : 'none';

		var totalNum = Math.max( 0, parseInt( total || 0, 10 ) );
		var progressNum = Math.max( 0, parseInt( progress || 0, 10 ) );
		var percent = totalNum > 0 ? Math.min( 100, Math.round( progressNum * 100 / totalNum ) ) : ( visible ? 10 : 0 );
		bar.style.width = percent + '%';

		label.textContent = message || 'Working...';
		meta.textContent = totalNum > 0 ? ( progressNum + ' / ' + totalNum + ' (' + percent + '%)' ) : '';

		if ( badge ) {
			var typeText = 'Sync';
			var bg = '#f6f7f7';
			var border = '#dcdcde';
			var color = '#3c434a';

			if ( syncType === 'products' ) {
				typeText = 'Products';
				bg = '#e7f1ff';
				border = '#c5ddff';
				color = '#0a4b78';
			} else if ( syncType === 'kb' ) {
				typeText = 'Knowledge Base';
				bg = '#e7f7ed';
				border = '#b8e6c6';
				color = '#115c35';
			}

			badge.textContent = typeText;
			badge.style.backgroundColor = bg;
			badge.style.borderColor = border;
			badge.style.color = color;
		}
	}

	function bindManualSyncButton( buttonId, resultId, endpoint, loadingText ) {
		var button = document.getElementById( buttonId );
		var result = document.getElementById( resultId );
		if ( !button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var originalText = button.textContent;
			var pollTimer = null;

			button.disabled = true;
			button.textContent = loadingText;
			setProgressUI( true, 0, 0, loadingText, endpoint === 'embeddings/sync-kb' ? 'kb' : 'products' );

			pollTimer = window.setInterval( function () {
				getSyncStatus().then( function ( statusData ) {
					if ( !statusData ) {
						return;
					}
					setProgressUI( true, statusData.progress || 0, statusData.total || 0, statusData.message || 'Syncing...', statusData.type || '' );
				} ).catch( function () {} );
			}, 1200 );

			apiFetch( endpoint, { method: 'POST' } ).then( function ( data ) {
				if ( pollTimer ) {
					window.clearInterval( pollTimer );
				}
				button.disabled = false;
				button.textContent = originalText;
				setProgressUI(
					true,
					data && data.details && typeof data.details.synced !== 'undefined' ? data.details.synced : 0,
					data && data.details && typeof data.details.total !== 'undefined' ? data.details.total : ( data && data.details && typeof data.details.synced !== 'undefined' ? data.details.synced : 0 ),
					formatApiMessage( data, 'Done.' ),
					endpoint === 'embeddings/sync-kb' ? 'kb' : 'products'
				);
				if ( result ) {
					result.className = 'kivor-chat-agent-test-result ' + ( data && data.success ? 'is-success' : 'is-error' );
					result.textContent = formatApiMessage( data, 'Done.' );
					result.style.display = 'block';
				}
			} ).catch( function ( error ) {
				if ( pollTimer ) {
					window.clearInterval( pollTimer );
				}
				button.disabled = false;
				button.textContent = originalText;
				setProgressUI( false, 0, 0, '' );
				if ( result ) {
					result.className = 'kivor-chat-agent-test-result is-error';
					result.textContent = 'Request failed: ' + formatErrorMessage( error, 'Unknown error' );
					result.style.display = 'block';
				}
			} );
		} );
	}

	bindManualSyncButton( 'kivor-embeddings-sync-products', 'kivor-embeddings-sync-products-result', 'embeddings/sync-products', 'Syncing products...' );
	bindManualSyncButton( 'kivor-embeddings-sync-kb', 'kivor-embeddings-sync-kb-result', 'embeddings/sync-kb', 'Syncing knowledge base...' );
})();
