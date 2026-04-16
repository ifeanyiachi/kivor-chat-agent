<?php
/**
 * Voice proxy endpoints and provider bridge.
 *
 * @package KivorAgent
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Voice_Proxy {

	/**
	 * Settings.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Settings instance.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'kivor-chat-agent/v1',
			'/voice/stt',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stt' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'provider'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'language'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'audio_base64' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => array( $this, 'sanitize_base64_audio' ),
					),
				),
			)
		);

		// TTS route removed: voice output is no longer supported.
	}

	/**
	 * Sanitize base64 audio payload from request body.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_base64_audio( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		// Remove optional data URL prefix and any whitespace.
		$value = preg_replace( '/^data:[^,]+,/', '', $value );
		$value = preg_replace( '/\s+/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Permission callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function check_permissions( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_nonce',
				__( 'Invalid security token. Please refresh the page and try again.', 'kivor-chat-agent' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * STT proxy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stt( \WP_REST_Request $request ) {
		$voice = $this->settings->get( 'voice' );
		if ( empty( $voice['enabled'] ) ) {
			return new \WP_Error( 'kivor_chat_agent_voice_disabled', __( 'Voice is disabled.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		$provider = $request->get_param( 'provider' );
		if ( empty( $provider ) ) {
			$provider = $voice['stt_provider'] ?? 'webspeech';
		}
		if ( ! in_array( $provider, $this->allowed_stt_providers(), true ) ) {
			return new \WP_Error( 'kivor_chat_agent_voice_invalid_provider', __( 'Unsupported STT provider.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		$text       = '';
		$confidence = null;

		if ( 'webspeech' === $provider ) {
			return new \WP_Error( 'kivor_chat_agent_webspeech_client_only', __( 'Web Speech STT is handled in browser.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		$audio_base64 = (string) $request->get_param( 'audio_base64' );
		if ( empty( $audio_base64 ) ) {
			return new \WP_Error( 'kivor_chat_agent_missing_audio', __( 'Missing audio payload.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $audio_base64 ) ) {
			return new \WP_Error( 'kivor_chat_agent_invalid_audio', __( 'Invalid audio payload.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		$max_audio_bytes = $this->max_stt_audio_bytes( $voice );
		if ( strlen( $audio_base64 ) > (int) ceil( $max_audio_bytes * 1.37 ) ) {
			return new \WP_Error( 'kivor_chat_agent_invalid_audio', __( 'Invalid audio payload.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		$audio_bin = base64_decode( $audio_base64, true );
		if ( false === $audio_bin ) {
			return new \WP_Error( 'kivor_chat_agent_invalid_audio', __( 'Invalid audio payload.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}
		if ( strlen( $audio_bin ) > $max_audio_bytes ) {
			return new \WP_Error( 'kivor_chat_agent_invalid_audio', __( 'Invalid audio payload.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		$tmp_file = $this->create_temp_file( 'kivor-chat-agent-stt' );
		if ( ! $tmp_file ) {
			return new \WP_Error( 'kivor_chat_agent_tmp_failed', __( 'Failed to create temporary file.', 'kivor-chat-agent' ), array( 'status' => 500 ) );
		}

		$written = file_put_contents( $tmp_file, $audio_bin );
		if ( false === $written ) {
			wp_delete_file( $tmp_file );
			return new \WP_Error( 'kivor_chat_agent_tmp_write_failed', __( 'Failed to write audio payload.', 'kivor-chat-agent' ), array( 'status' => 500 ) );
		}

		$result = $this->stt_remote( $provider, $tmp_file, $request->get_param( 'language' ) );
		wp_delete_file( $tmp_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text       = (string) ( $result['text'] ?? '' );
		$confidence = isset( $result['confidence'] ) ? floatval( $result['confidence'] ) : null;

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'provider'   => $provider,
				'text'       => $text,
				'confidence' => $confidence,
			),
			200
		);
	}


	/**
	 * Execute STT request against selected provider.
	 *
	 * @param string      $provider Provider key.
	 * @param string      $file     Audio file path.
	 * @param string|null $language Language.
	 * @return array|\WP_Error
	 */
	private function stt_remote( string $provider, string $file, ?string $language ) {
		$voice = $this->settings->get( 'voice' );
		$providers = $voice['providers'] ?? array();

		switch ( $provider ) {
			case 'openai':
				$api_key = (string) ( $providers['openai']['api_key'] ?? '' );
				$model_name = $this->provider_stt_model( $provider, $voice, $providers, 'gpt-4o-mini-transcribe' );
				if ( '' === $api_key ) {
					return new \WP_Error( 'kivor_chat_agent_voice_openai_key', __( 'OpenAI voice API key is missing.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
				}

				$args = array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
					),
					'body'    => array(
						'model' => $model_name,
					),
					'timeout' => 60,
				);
				if ( ! empty( $language ) ) {
					$args['body']['language'] = (string) $language;
				}

				$res = $this->multipart_post( 'https://api.openai.com/v1/audio/transcriptions', $args['headers'], $args['body'], $file );
				return $this->parse_stt_json_response( $res, 'text' );

			case 'cartesia':
				$api_key = (string) ( $providers['cartesia']['api_key'] ?? '' );
				$version = (string) ( $providers['cartesia']['version'] ?? '2025-04-16' );
				$model_name = $this->provider_stt_model( $provider, $voice, $providers, 'ink-whisper' );
				if ( '' === $api_key ) {
					return new \WP_Error( 'kivor_chat_agent_voice_cartesia_key', __( 'Cartesia API key is missing.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
				}

				$args = array(
					'headers' => array(
						'X-API-Key'        => $api_key,
						'Cartesia-Version' => $version,
					),
					'body'    => array(
						'model' => $model_name,
					),
					'timeout' => 60,
				);
				if ( ! empty( $language ) ) {
					$args['body']['language'] = (string) $language;
				}

				$res = $this->multipart_post( 'https://api.cartesia.ai/stt', $args['headers'], $args['body'], $file );
				return $this->parse_stt_json_response( $res, 'text' );

			case 'deepgram':
				return $this->deepgram_stt( $providers, $voice, $file, $language, $this->provider_stt_model( $provider, $voice, $providers, 'nova-2' ) );
		}

		return new \WP_Error( 'kivor_chat_agent_voice_invalid_provider', __( 'Unsupported STT provider.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
	}

	/**
	 * Deepgram STT adapter.
	 *
	 * @param array       $providers Providers config.
	 * @param array       $voice Voice config.
	 * @param string      $file File path.
	 * @param string|null $language Language.
	 * @return array|\WP_Error
	 */
	private function deepgram_stt( array $providers, array $voice, string $file, ?string $language, string $stt_model ) {
		$api_key = (string) ( $providers['deepgram']['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new \WP_Error( 'kivor_chat_agent_voice_deepgram_key', __( 'Deepgram API key is missing.', 'kivor-chat-agent' ), array( 'status' => 400 ) );
		}

		$query = array(
			'model' => $stt_model,
		);
		if ( ! empty( $language ) ) {
			$query['language'] = (string) $language;
		}

		$audio = file_get_contents( $file );
		if ( false === $audio ) {
			return new \WP_Error( 'kivor_chat_agent_voice_audio_read_failed', __( 'Failed to read audio file.', 'kivor-chat-agent' ), array( 'status' => 500 ) );
		}

		$res = wp_remote_post(
			'https://api.deepgram.com/v1/listen?' . http_build_query( $query ),
			array(
				'headers' => array(
					'Authorization' => 'Token ' . $api_key,
					'Content-Type'  => 'audio/webm',
				),
				'body'    => $audio,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			if ( $code < 200 || $code >= 300 ) {
				return $this->build_remote_error(
					'kivor_chat_agent_voice_stt_failed',
					__( 'Deepgram STT request failed.', 'kivor-chat-agent' ),
					$res,
					500
				);
			}

			return new \WP_Error( 'kivor_chat_agent_voice_stt_failed', __( 'Deepgram STT request failed.', 'kivor-chat-agent' ), array( 'status' => 500 ) );
		}

		$text = '';
		$confidence = null;
		if ( ! empty( $body['results']['channels'][0]['alternatives'][0] ) ) {
			$alt = $body['results']['channels'][0]['alternatives'][0];
			$text = (string) ( $alt['transcript'] ?? '' );
			if ( isset( $alt['confidence'] ) ) {
				$confidence = floatval( $alt['confidence'] );
			}
		}

		return array( 'text' => $text, 'confidence' => $confidence );
	}



	/**
	 * Parse STT JSON responses with text key.
	 *
	 * @param mixed  $response HTTP response.
	 * @param string $text_key Text key.
	 * @return array|\WP_Error
	 */
	private function parse_stt_json_response( $response, string $text_key ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $this->build_remote_error(
				'kivor_chat_agent_voice_stt_failed',
				__( 'Speech-to-text request failed.', 'kivor-chat-agent' ),
				$response,
				500
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'kivor_chat_agent_voice_stt_failed', __( 'Speech-to-text request failed.', 'kivor-chat-agent' ), array( 'status' => 500 ) );
		}

		$text = (string) ( $body[ $text_key ] ?? '' );
		$confidence = null;
		if ( isset( $body['confidence'] ) ) {
			$confidence = floatval( $body['confidence'] );
		}

		return array(
			'text'       => $text,
			'confidence' => $confidence,
		);
	}


	/**
	 * Resolve provider-scoped STT model with global fallback.
	 *
	 * @param string $provider Provider key.
	 * @param array  $voice Voice settings.
	 * @param array  $providers Provider settings map.
	 * @param string $default Default model.
	 * @return string
	 */
	private function provider_stt_model( string $provider, array $voice, array $providers, string $default ): string {
		$provider_model = (string) ( $providers[ $provider ]['stt_model'] ?? '' );
		if ( '' !== trim( $provider_model ) ) {
			return $provider_model;
		}

		$global_model = (string) ( $voice['stt_model'] ?? '' );
		if ( '' !== trim( $global_model ) ) {
			return $global_model;
		}

		return $default;
	}



	/**
	 * Multibyte-safe string length with fallback.
	 *
	 * @param string $value Input string.
	 * @return int
	 */
	private function string_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		return strlen( $value );
	}

	/**
	 * Multibyte-safe substring with fallback.
	 *
	 * @param string $value  Input string.
	 * @param int    $start  Start offset.
	 * @param int    $length Length.
	 * @return string
	 */
	private function string_substr( string $value, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, $start, $length, 'UTF-8' );
		}

		return (string) substr( $value, $start, $length );
	}

	/**
	 * Allowed STT provider keys.
	 *
	 * @return array
	 */
	private function allowed_stt_providers(): array {
		return array( 'webspeech', 'openai', 'cartesia', 'deepgram' );
	}



	/**
	 * Estimate max accepted STT audio bytes from configured turn limit.
	 *
	 * Uses a conservative upper bound to avoid oversized payload abuse.
	 *
	 * @param array $voice Voice settings.
	 * @return int
	 */
	private function max_stt_audio_bytes( array $voice ): int {
		$max_seconds = absint( $voice['limits']['max_stt_seconds'] ?? 20 );
		if ( $max_seconds < 5 ) {
			$max_seconds = 20;
		}
		if ( $max_seconds > 120 ) {
			$max_seconds = 120;
		}

		// 200KB/s upper bound + small headroom.
		return ( $max_seconds * 200000 ) + 65536;
	}

	/**
	 * Create a temporary file path for voice payloads.
	 *
	 * @param string $prefix Filename prefix.
	 * @return string|false
	 */
	private function create_temp_file( string $prefix ) {
		if ( function_exists( 'wp_tempnam' ) ) {
			return wp_tempnam( $prefix );
		}

		$tmp_dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		if ( ! is_string( $tmp_dir ) || '' === trim( $tmp_dir ) ) {
			$tmp_dir = sys_get_temp_dir();
		}

		return tempnam( $tmp_dir, $prefix );
	}

	/**
	 * Send multipart/form-data using wp_remote_post for file upload.
	 *
	 * @param string $url URL.
	 * @param array  $headers Headers.
	 * @param array  $fields Form fields.
	 * @param string $file File path.
	 * @return array|\WP_Error
	 */
	private function multipart_post( string $url, array $headers, array $fields, string $file ) {
		$boundary = wp_generate_password( 24, false );
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= "{$value}\r\n";
		}

		$file_contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local temp file.
		if ( false === $file_contents ) {
			return new \WP_Error( 'kivor_chat_agent_voice_file_read_failed', __( 'Failed to read audio file.', 'kivor-chat-agent' ), array( 'status' => 500 ) );
		}

		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"speech.webm\"\r\n";
		$body .= "Content-Type: audio/webm\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= "--{$boundary}--\r\n";

		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		$response = wp_remote_post( $url, array(
			'headers' => $headers,
			'body'    => $body,
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'headers'  => array( 'content-type' => wp_remote_retrieve_header( $response, 'content-type' ) ),
			'body'     => wp_remote_retrieve_body( $response ),
			'response' => array( 'code' => (int) wp_remote_retrieve_response_code( $response ) ),
		);
	}

	/**
	 * Build WP_Error with parsed upstream provider message when available.
	 *
	 * @param string $code          Error code.
	 * @param string $fallback      Fallback translated message.
	 * @param mixed  $response      WP HTTP response array.
	 * @param int    $status        HTTP status for WP_Error data.
	 * @return \WP_Error
	 */
	private function build_remote_error( string $code, string $fallback, $response, int $status ): \WP_Error {
		$body = (string) wp_remote_retrieve_body( $response );
		$provider_message = $this->extract_remote_message( $body );

		$message = $fallback;
		if ( '' !== $provider_message && $provider_message !== $fallback ) {
			$message = $fallback . ' ' . $provider_message;
		}

		return new \WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'http_code' => (int) wp_remote_retrieve_response_code( $response ),
			)
		);
	}

	/**
	 * Extract a concise provider error message from response body.
	 *
	 * @param string $body Raw response body.
	 * @return string
	 */
	private function extract_remote_message( string $body ): string {
		$trimmed = trim( $body );
		if ( '' === $trimmed ) {
			return '';
		}

		$decoded = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			$candidates = array(
				$decoded['error']['message'] ?? null,
				$decoded['error_description'] ?? null,
				$decoded['message'] ?? null,
				$decoded['detail'] ?? null,
				$decoded['error']['details'][0]['message'] ?? null,
				$decoded['err_msg'] ?? null,
			);

			foreach ( $candidates as $candidate ) {
				if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
					return $this->normalize_remote_message( $candidate );
				}
			}
		}

		if ( 0 === strpos( $trimmed, '{' ) || 0 === strpos( $trimmed, '[' ) ) {
			return '';
		}

		return $this->normalize_remote_message( $trimmed );
	}

	/**
	 * Normalize provider error message to a short single line.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private function normalize_remote_message( string $message ): string {
		$message = wp_strip_all_tags( $message );
		$message = preg_replace( '/\s+/', ' ', $message );
		$message = trim( (string) $message );

		if ( $this->string_length( $message ) > 240 ) {
			$message = $this->string_substr( $message, 0, 240 ) . '...';
		}

		return $message;
	}
}
