<?php
/**
 * Abstract AI provider.
 *
 * Base class for all AI chat providers (OpenAI, Gemini, OpenRouter).
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

abstract class Kivor_AI_Provider {

    /**
     * API key.
     *
     * @var string
     */
    protected string $api_key;

    /**
     * Model identifier.
     *
     * @var string
     */
    protected string $model;

    /**
     * Constructor.
     *
     * @param string $api_key API key for the provider.
     * @param string $model   Model identifier.
     */
    public function __construct( string $api_key, string $model ) {
        $this->api_key = $api_key;
        $this->model   = $model;
    }

    /**
     * Send a chat completion request.
     *
     * @param array $messages   Array of message objects: [ ['role' => 'user', 'content' => '...'], ... ]
     * @param array $options    Additional options (temperature, max_tokens, tools, etc.).
     * @return array|WP_Error   Response array with 'content', 'usage', 'finish_reason' or WP_Error.
     */
    abstract public function chat( array $messages, array $options = array() );

    /**
     * Send a streaming chat completion request.
     *
     * Calls the $callback for each chunk of the response.
     *
     * @param array    $messages Array of message objects.
     * @param callable $callback Callback receiving each text chunk: function( string $text, bool $done )
     * @param array    $options  Additional options.
     * @return array|WP_Error   Final response metadata or WP_Error.
     */
    abstract public function chat_stream( array $messages, callable $callback, array $options = array() );

    /**
     * Test the API connection.
     *
     * @return array|WP_Error Array with 'success' => true and 'model' info, or WP_Error.
     */
    abstract public function test_connection();

    /**
     * Get the provider name.
     *
     * @return string
     */
    abstract public function get_provider_name(): string;

    /**
     * Make an HTTP request to the provider API.
     *
     * @param string $url     API endpoint URL.
     * @param array  $body    Request body (will be JSON-encoded).
     * @param array  $headers Additional headers.
     * @param int    $timeout Request timeout in seconds.
     * @return array|WP_Error Decoded response body or WP_Error.
     */
    protected function make_request( string $url, array $body, array $headers = array(), int $timeout = 60 ) {
        $default_headers = array(
            'Content-Type' => 'application/json',
        );

        $response = wp_remote_post( $url, array(
            'headers' => array_merge( $default_headers, $headers ),
            'body'    => wp_json_encode( $body ),
            'timeout' => $timeout,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body_raw, true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_message = $this->extract_error_message( $decoded, $body_raw );
            return new \WP_Error(
                'kivor_chat_agent_ai_api_error',
                sprintf(
                    /* translators: 1: Provider name, 2: HTTP status code, 3: Error message */
                    __( '%1$s API error (%2$d): %3$s', 'kivor-chat-agent' ),
                    $this->get_provider_name(),
                    $status_code,
                    $error_message
                ),
                array(
                    'status'        => $status_code,
                    'provider_body' => $body_raw,
                )
            );
        }

        if ( null === $decoded ) {
            return new \WP_Error(
                'kivor_chat_agent_ai_parse_error',
                __( 'Failed to parse AI provider response.', 'kivor-chat-agent' )
            );
        }

        return $decoded;
    }

    /**
     * Make a streaming HTTP request.
     *
     * Uses WordPress HTTP API with a custom stream callback.
     *
     * @param string   $url      API endpoint URL.
     * @param array    $body     Request body.
     * @param callable $callback Chunk callback: function( string $chunk )
     * @param array    $headers  Additional headers.
     * @param int      $timeout  Request timeout in seconds.
     * @return true|WP_Error
     */
    protected function make_stream_request( string $url, array $body, callable $callback, array $headers = array(), int $timeout = 120 ) {
        $default_headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'text/event-stream',
        );

        // WordPress HTTP API doesn't natively support streaming.
        // We use a custom transport via cURL directly.
        $ch = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Streaming requires cURL WRITEFUNCTION callback.

        if ( false === $ch ) {
            return new \WP_Error( 'kivor_chat_agent_curl_error', __( 'Failed to initialize cURL.', 'kivor-chat-agent' ) );
        }

        $all_headers = array_merge( $default_headers, $headers );
        $header_arr  = array();
        foreach ( $all_headers as $key => $value ) {
            $header_arr[] = "{$key}: {$value}";
        }

        $raw_response   = '';
        $max_raw_length = 8000;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
        curl_setopt_array( $ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_HTTPHEADER     => $header_arr,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, // VULN-016 fix: explicit host verification.
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( $callback, &$raw_response, $max_raw_length ) {
                if ( strlen( $raw_response ) < $max_raw_length ) {
                    $remaining = $max_raw_length - strlen( $raw_response );
                    $raw_response .= substr( $data, 0, $remaining );
                }
                $callback( $data );
                return strlen( $data );
            },
        ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
        $result = curl_exec( $ch );
        $error  = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo;
        $code   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close;

        if ( false === $result && ! empty( $error ) ) {
            return new \WP_Error( 'kivor_chat_agent_curl_error', $error );
        }

        if ( $code < 200 || $code >= 300 ) {
            $decoded       = json_decode( $raw_response, true );
            $error_message = $this->extract_error_message( is_array( $decoded ) ? $decoded : null, $raw_response );

            return new \WP_Error(
                'kivor_chat_agent_ai_stream_error',
                sprintf(
                    /* translators: 1: Provider name, 2: HTTP status code, 3: Error message */
                    __( '%1$s streaming error (HTTP %2$d): %3$s', 'kivor-chat-agent' ),
                    $this->get_provider_name(),
                    $code,
                    $error_message
                ),
                array(
                    'status'        => $code,
                    'provider_body' => $raw_response,
                )
            );
        }

        return true;
    }

    /**
     * Extract a human-readable error message from an API error response.
     *
     * @param array|null $decoded  Decoded JSON response.
     * @param string     $raw_body Raw response body.
     * @return string
     */
    protected function extract_error_message( ?array $decoded, string $raw_body ): string {
        if ( is_array( $decoded ) ) {
            // OpenAI / OpenRouter format.
            if ( ! empty( $decoded['error']['message'] ) ) {
                return $decoded['error']['message'];
            }
            // Gemini format.
            if ( ! empty( $decoded['error']['status'] ) ) {
                return $decoded['error']['status'] . ': ' . ( $decoded['error']['message'] ?? 'Unknown error' );
            }
            // Generic.
            if ( ! empty( $decoded['message'] ) ) {
                return $decoded['message'];
            }
        }

        return mb_substr( $raw_body, 0, 200 );
    }

    /**
     * Build the default chat options.
     *
     * @param array $options User-provided options.
     * @return array Merged options with defaults.
     */
    protected function build_options( array $options ): array {
        return array_merge( array(
            'temperature' => 0.7,
            'max_tokens'  => 1024,
            'tools'       => array(),
        ), $options );
    }

    /**
     * Validate that API key and model are configured.
     *
     * @return true|WP_Error
     */
    protected function validate_config() {
        if ( empty( $this->api_key ) ) {
            return new \WP_Error(
                'kivor_chat_agent_no_api_key',
                sprintf(
                    /* translators: %s: Provider name */
                    __( '%s API key is not configured.', 'kivor-chat-agent' ),
                    $this->get_provider_name()
                )
            );
        }

        if ( empty( $this->model ) ) {
            return new \WP_Error(
                'kivor_chat_agent_no_model',
                sprintf(
                    /* translators: %s: Provider name */
                    __( '%s model is not configured.', 'kivor-chat-agent' ),
                    $this->get_provider_name()
                )
            );
        }

        return true;
    }

    /**
     * Validate the structure of the messages array before sending to the API.
     *
     * Ensures each message has the required 'role' and 'content' keys with
     * valid types, preventing malformed payloads from reaching the provider
     * API (VULN-015 fix).
     *
     * @param array $messages Array of message objects.
     * @return true|WP_Error
     */
    protected function validate_messages( array $messages ) {
        if ( empty( $messages ) ) {
            return new \WP_Error(
                'kivor_chat_agent_empty_messages',
                __( 'Messages array is empty.', 'kivor-chat-agent' )
            );
        }

        $allowed_roles = array( 'system', 'user', 'assistant', 'tool', 'function' );

        foreach ( $messages as $i => $msg ) {
            if ( ! is_array( $msg ) ) {
                return new \WP_Error(
                    'kivor_chat_agent_invalid_message',
                    sprintf(
                        /* translators: %d: Message index */
                        __( 'Message at index %d is not an array.', 'kivor-chat-agent' ),
                        $i
                    )
                );
            }

            if ( empty( $msg['role'] ) || ! in_array( $msg['role'], $allowed_roles, true ) ) {
                return new \WP_Error(
                    'kivor_chat_agent_invalid_message_role',
                    sprintf(
                        /* translators: %d: Message index */
                        __( 'Message at index %d has an invalid or missing role.', 'kivor-chat-agent' ),
                        $i
                    )
                );
            }

            // 'content' can be null for assistant messages with tool_calls.
            if ( ! array_key_exists( 'content', $msg ) && empty( $msg['tool_calls'] ) ) {
                return new \WP_Error(
                    'kivor_chat_agent_invalid_message_content',
                    sprintf(
                        /* translators: %d: Message index */
                        __( 'Message at index %d is missing content.', 'kivor-chat-agent' ),
                        $i
                    )
                );
            }
        }

        return true;
    }
}