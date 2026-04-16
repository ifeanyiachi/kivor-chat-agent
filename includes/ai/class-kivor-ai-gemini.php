<?php
/**
 * Google Gemini provider.
 *
 * Handles chat completions via the Google Gemini API.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_AI_Gemini extends Kivor_AI_Provider {

    /**
     * Preferred default Gemini chat model.
     *
     * @var string
     */
    const DEFAULT_CHAT_MODEL = 'gemini-2.5-flash';

    /**
     * Runtime fallback Gemini chat models.
     *
     * @var array
     */
    const CHAT_FALLBACK_MODELS = array(
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
    );

    /**
     * Gemini API base URL.
     *
     * @var string
     */
    const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function get_provider_name(): string {
        return 'Gemini';
    }

    /**
     * Send a chat completion request.
     *
     * @param array $messages Array of message objects (OpenAI format, will be converted).
     * @param array $options  Additional options.
     * @return array|WP_Error
     */
    public function chat( array $messages, array $options = array() ) {
        $valid = $this->validate_config();
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $opts             = $this->build_options( $options );
        $gemini_messages  = $this->convert_messages( $messages );
        $system_instruction = $this->extract_system_instruction( $messages );

        $body = array(
            'contents'         => $gemini_messages,
            'generationConfig' => array(
                'temperature'     => $opts['temperature'],
                'maxOutputTokens' => $opts['max_tokens'],
            ),
        );

        // Add system instruction if present.
        if ( ! empty( $system_instruction ) ) {
            $body['systemInstruction'] = array(
                'parts' => array(
                    array( 'text' => $system_instruction ),
                ),
            );
        }

        // Add tools if provided (function calling).
        if ( ! empty( $opts['tools'] ) ) {
            $body['tools'] = $this->convert_tools( $opts['tools'] );
        }

        $response = $this->make_request_with_model_fallback( $body, $this->model );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_response( $response );
    }

    /**
     * Send a streaming chat completion request.
     *
     * @param array    $messages Array of message objects.
     * @param callable $callback Callback for each text chunk.
     * @param array    $options  Additional options.
     * @return array|WP_Error
     */
    public function chat_stream( array $messages, callable $callback, array $options = array() ) {
        $valid = $this->validate_config();
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $opts             = $this->build_options( $options );
        $gemini_messages  = $this->convert_messages( $messages );
        $system_instruction = $this->extract_system_instruction( $messages );

        $body = array(
            'contents'         => $gemini_messages,
            'generationConfig' => array(
                'temperature'     => $opts['temperature'],
                'maxOutputTokens' => $opts['max_tokens'],
            ),
        );

        if ( ! empty( $system_instruction ) ) {
            $body['systemInstruction'] = array(
                'parts' => array(
                    array( 'text' => $system_instruction ),
                ),
            );
        }

        if ( ! empty( $opts['tools'] ) ) {
            $body['tools'] = $this->convert_tools( $opts['tools'] );
        }

        $full_content  = '';
        $tool_calls    = array();
        $finish_reason = '';
        $buffer        = '';

        $stream_callback = function ( string $data ) use ( $callback, &$full_content, &$tool_calls, &$finish_reason, &$buffer ) {
            $buffer .= $data;

            while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
                $line   = trim( substr( $buffer, 0, $pos ) );
                $buffer = substr( $buffer, $pos + 1 );

                if ( empty( $line ) || 0 !== strpos( $line, 'data: ' ) ) {
                    continue;
                }

                $json_str = substr( $line, 6 );
                $chunk    = json_decode( $json_str, true );

                if ( ! is_array( $chunk ) ) {
                    continue;
                }

                $candidate = $chunk['candidates'][0] ?? array();
                $parts     = $candidate['content']['parts'] ?? array();

                foreach ( $parts as $part ) {
                    if ( ! empty( $part['text'] ) ) {
                        $full_content .= $part['text'];
                        $callback( $part['text'], false );
                    }

                    if ( ! empty( $part['functionCall'] ) ) {
                        $tool_calls[] = array(
                            'id'       => 'call_' . wp_generate_uuid4(),
                            'type'     => 'function',
                            'function' => array(
                                'name'      => $part['functionCall']['name'],
                                'arguments' => wp_json_encode( $part['functionCall']['args'] ?? array() ),
                            ),
                        );
                    }
                }

                if ( ! empty( $candidate['finishReason'] ) ) {
                    $finish_reason = $this->map_finish_reason( $candidate['finishReason'] );
                }
            }
        };

        $result = $this->make_stream_request_with_model_fallback( $body, $stream_callback, $this->model );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $callback( '', true );

        return array(
            'content'       => $full_content,
            'tool_calls'    => $tool_calls,
            'finish_reason' => $finish_reason,
        );
    }

    /**
     * Test the API connection.
     *
     * @return array|WP_Error
     */
    public function test_connection() {
        $valid = $this->validate_config();
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $response = $this->chat(
            array(
                array( 'role' => 'user', 'content' => 'Say "connected" and nothing else.' ),
            ),
            array( 'max_tokens' => 10, 'temperature' => 0 )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return array(
            'success'  => true,
            'provider' => $this->get_provider_name(),
            'model'    => $this->model,
            'message'  => $response['content'] ?? 'Connected',
        );
    }

    /**
     * Convert OpenAI-format messages to Gemini format.
     *
     * Gemini uses 'user' and 'model' roles with 'parts' arrays.
     * System messages are extracted separately.
     *
     * @param array $messages OpenAI-format messages.
     * @return array Gemini-format contents.
     */
    private function convert_messages( array $messages ): array {
        $contents = array();

        foreach ( $messages as $msg ) {
            $role = $msg['role'] ?? 'user';

            // Skip system messages (handled separately).
            if ( 'system' === $role ) {
                continue;
            }

            // Map roles.
            $gemini_role = 'assistant' === $role ? 'model' : 'user';

            $parts = array();

            if ( ! empty( $msg['content'] ) ) {
                $parts[] = array( 'text' => $msg['content'] );
            }

            // Handle tool call results.
            if ( 'tool' === $role && ! empty( $msg['tool_call_id'] ) ) {
                $parts[] = array(
                    'functionResponse' => array(
                        'name'     => $msg['name'] ?? '',
                        'response' => json_decode( $msg['content'] ?? '{}', true ) ?? array(),
                    ),
                );
                $gemini_role = 'user';
            }

            if ( ! empty( $parts ) ) {
                $contents[] = array(
                    'role'  => $gemini_role,
                    'parts' => $parts,
                );
            }
        }

        return $contents;
    }

    /**
     * Extract system instruction from messages.
     *
     * @param array $messages Message array.
     * @return string System instruction text.
     */
    private function extract_system_instruction( array $messages ): string {
        $system_parts = array();

        foreach ( $messages as $msg ) {
            if ( 'system' === ( $msg['role'] ?? '' ) && ! empty( $msg['content'] ) ) {
                $system_parts[] = $msg['content'];
            }
        }

        return implode( "\n\n", $system_parts );
    }

    /**
     * Convert OpenAI-format tools to Gemini format.
     *
     * @param array $tools OpenAI tools array.
     * @return array Gemini tools format.
     */
    private function convert_tools( array $tools ): array {
        $function_declarations = array();

        foreach ( $tools as $tool ) {
            if ( 'function' !== ( $tool['type'] ?? '' ) ) {
                continue;
            }

            $func = $tool['function'] ?? array();
            $declaration = array(
                'name'        => $func['name'] ?? '',
                'description' => $func['description'] ?? '',
            );

            if ( ! empty( $func['parameters'] ) ) {
                $declaration['parameters'] = $func['parameters'];
            }

            $function_declarations[] = $declaration;
        }

        return array(
            array( 'functionDeclarations' => $function_declarations ),
        );
    }

    /**
     * Parse Gemini response into normalized format.
     *
     * @param array $response Raw Gemini API response.
     * @return array
     */
    private function parse_response( array $response ): array {
        $candidate = $response['candidates'][0] ?? array();
        $parts     = $candidate['content']['parts'] ?? array();

        $content    = '';
        $tool_calls = array();

        foreach ( $parts as $part ) {
            if ( ! empty( $part['text'] ) ) {
                $content .= $part['text'];
            }

            if ( ! empty( $part['functionCall'] ) ) {
                $tool_calls[] = array(
                    'id'       => 'call_' . wp_generate_uuid4(),
                    'type'     => 'function',
                    'function' => array(
                        'name'      => $part['functionCall']['name'],
                        'arguments' => wp_json_encode( $part['functionCall']['args'] ?? array() ),
                    ),
                );
            }
        }

        $finish_reason = $this->map_finish_reason( $candidate['finishReason'] ?? 'STOP' );

        return array(
            'content'       => $content,
            'finish_reason' => $finish_reason,
            'tool_calls'    => $tool_calls,
            'usage'         => $response['usageMetadata'] ?? array(),
        );
    }

    /**
     * Map Gemini finish reasons to OpenAI-compatible values.
     *
     * @param string $reason Gemini finish reason.
     * @return string
     */
    private function map_finish_reason( string $reason ): string {
        $map = array(
            'STOP'          => 'stop',
            'MAX_TOKENS'    => 'length',
            'SAFETY'        => 'content_filter',
            'RECITATION'    => 'content_filter',
            'OTHER'         => 'stop',
            'FINISH_REASON_UNSPECIFIED' => 'stop',
        );

        return $map[ $reason ] ?? 'stop';
    }

    /**
     * Build generateContent endpoint URL for a model.
     *
     * @param string $model Model ID.
     * @return string
     */
    private function build_generate_content_url( string $model ): string {
        return sprintf(
            '%s/models/%s:generateContent?key=%s',
            self::API_BASE,
            $model,
            $this->api_key
        );
    }

    /**
     * Build streamGenerateContent endpoint URL for a model.
     *
     * @param string $model Model ID.
     * @return string
     */
    private function build_stream_generate_content_url( string $model ): string {
        return sprintf(
            '%s/models/%s:streamGenerateContent?alt=sse&key=%s',
            self::API_BASE,
            $model,
            $this->api_key
        );
    }

    /**
     * Request Gemini chat with model fallback on 404 unsupported/deprecated model errors.
     *
     * @param array  $body            Request body.
     * @param string $requested_model Initially requested model.
     * @return array|WP_Error
     */
    private function make_request_with_model_fallback( array $body, string $requested_model ) {
        $models_to_try = array_merge( array( $requested_model ), $this->get_fallback_models( $requested_model ) );
        $last_error    = null;

        foreach ( $models_to_try as $index => $model ) {
            $response = $this->make_request( $this->build_generate_content_url( $model ), $body );
            if ( ! is_wp_error( $response ) ) {
                return $response;
            }

            $last_error = $response;

            if ( $index === count( $models_to_try ) - 1 || ! $this->is_model_not_found_error( $response ) ) {
                return $response;
            }
        }

        return $last_error;
    }

    /**
     * Request Gemini streaming chat with model fallback on 404 errors.
     *
     * @param array    $body            Request body.
     * @param callable $callback        Stream callback.
     * @param string   $requested_model Initially requested model.
     * @return true|WP_Error
     */
    private function make_stream_request_with_model_fallback( array $body, callable $callback, string $requested_model ) {
        $models_to_try = array_merge( array( $requested_model ), $this->get_fallback_models( $requested_model ) );
        $last_error    = null;

        foreach ( $models_to_try as $index => $model ) {
            $result = $this->make_stream_request( $this->build_stream_generate_content_url( $model ), $body, $callback );
            if ( ! is_wp_error( $result ) ) {
                return $result;
            }

            $last_error = $result;

            if ( $index === count( $models_to_try ) - 1 || ! $this->is_model_not_found_error( $result ) ) {
                return $result;
            }
        }

        return $last_error;
    }

    /**
     * Get fallback model candidates, excluding current model.
     *
     * @param string $requested_model Requested model.
     * @return array
     */
    private function get_fallback_models( string $requested_model ): array {
        $requested_model = trim( $requested_model );
        $candidates      = array();

        foreach ( self::CHAT_FALLBACK_MODELS as $fallback_model ) {
            if ( $fallback_model !== $requested_model ) {
                $candidates[] = $fallback_model;
            }
        }

        return $candidates;
    }

    /**
     * Check whether a WP_Error indicates a missing/unsupported Gemini model.
     *
     * @param WP_Error $error Provider error.
     * @return bool
     */
    private function is_model_not_found_error( \WP_Error $error ): bool {
        $status = (int) ( $error->get_error_data()['status'] ?? 0 );
        if ( 404 !== $status ) {
            return false;
        }

        $message = strtolower( $error->get_error_message() );
        return false !== strpos( $message, 'model' )
            || false !== strpos( $message, 'unsupported' )
            || false !== strpos( $message, 'not found' )
            || false !== strpos( $message, 'not available' );
    }

    /**
     * Extract provider-specific error messages.
     *
     * @param array|null $decoded  Decoded JSON response.
     * @param string     $raw_body Raw response body.
     * @return string
     */
    protected function extract_error_message( ?array $decoded, string $raw_body ): string {
        $code             = (int) ( $decoded['error']['code'] ?? 0 );
        $provider_message = (string) ( $decoded['error']['message'] ?? '' );

        if ( 404 === $code ) {
            return sprintf(
                /* translators: %1$s: fallback model, %2$s: provider error details */
                __( 'Requested Gemini model may be deprecated or unavailable. Switch to %1$s. Provider details: %2$s', 'kivor-chat-agent' ),
                self::DEFAULT_CHAT_MODEL,
                '' !== trim( $provider_message ) ? $provider_message : __( 'Model not found or unsupported.', 'kivor-chat-agent' )
            );
        }

        if ( 429 === $code ) {
            return sprintf(
                /* translators: %s: provider error details */
                __( 'Gemini quota or rate limit exceeded for this project/model. Check AI Studio limits and billing. Provider details: %s', 'kivor-chat-agent' ),
                '' !== trim( $provider_message ) ? $provider_message : __( 'Resource exhausted.', 'kivor-chat-agent' )
            );
        }

        return parent::extract_error_message( $decoded, $raw_body );
    }
}