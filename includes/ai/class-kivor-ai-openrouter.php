<?php
/**
 * OpenRouter provider.
 *
 * Handles chat completions via the OpenRouter API (OpenAI-compatible).
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_AI_OpenRouter extends Kivor_AI_Provider {

    /**
     * OpenRouter API base URL.
     *
     * @var string
     */
    const API_BASE = 'https://openrouter.ai/api/v1';

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function get_provider_name(): string {
        return 'OpenRouter';
    }

    /**
     * Send a chat completion request.
     *
     * OpenRouter uses the same format as OpenAI.
     *
     * @param array $messages Array of message objects.
     * @param array $options  Additional options.
     * @return array|WP_Error
     */
    public function chat( array $messages, array $options = array() ) {
        $valid = $this->validate_config();
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $opts = $this->build_options( $options );

        $body = array(
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $opts['temperature'],
            'max_tokens'  => $opts['max_tokens'],
        );

        if ( ! empty( $opts['tools'] ) ) {
            $body['tools']       = $opts['tools'];
            $body['tool_choice'] = $opts['tool_choice'] ?? 'auto';
        }

        $response = $this->make_request(
            self::API_BASE . '/chat/completions',
            $body,
            $this->get_auth_headers()
        );

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

        $opts = $this->build_options( $options );

        $body = array(
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $opts['temperature'],
            'max_tokens'  => $opts['max_tokens'],
            'stream'      => true,
        );

        if ( ! empty( $opts['tools'] ) ) {
            $body['tools']       = $opts['tools'];
            $body['tool_choice'] = $opts['tool_choice'] ?? 'auto';
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

                if ( '[DONE]' === $json_str ) {
                    $callback( '', true );
                    return;
                }

                $chunk = json_decode( $json_str, true );
                if ( ! is_array( $chunk ) || empty( $chunk['choices'][0] ) ) {
                    continue;
                }

                $delta = $chunk['choices'][0]['delta'] ?? array();

                if ( ! empty( $delta['content'] ) ) {
                    $full_content .= $delta['content'];
                    $callback( $delta['content'], false );
                }

                if ( ! empty( $delta['tool_calls'] ) ) {
                    foreach ( $delta['tool_calls'] as $tc ) {
                        $idx = $tc['index'] ?? 0;
                        if ( ! isset( $tool_calls[ $idx ] ) ) {
                            $tool_calls[ $idx ] = array(
                                'id'       => $tc['id'] ?? '',
                                'type'     => $tc['type'] ?? 'function',
                                'function' => array(
                                    'name'      => $tc['function']['name'] ?? '',
                                    'arguments' => '',
                                ),
                            );
                        }
                        if ( ! empty( $tc['function']['arguments'] ) ) {
                            $tool_calls[ $idx ]['function']['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }

                if ( ! empty( $chunk['choices'][0]['finish_reason'] ) ) {
                    $finish_reason = $chunk['choices'][0]['finish_reason'];
                }
            }
        };

        $result = $this->make_stream_request(
            self::API_BASE . '/chat/completions',
            $body,
            $stream_callback,
            $this->get_auth_headers()
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'content'       => $full_content,
            'tool_calls'    => array_values( $tool_calls ),
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
     * Get authorization headers.
     *
     * @return array
     */
    private function get_auth_headers(): array {
        return array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'HTTP-Referer'  => home_url(),
            'X-Title'       => get_bloginfo( 'name' ),
        );
    }

    /**
     * Parse OpenRouter response (same as OpenAI format).
     *
     * @param array $response Raw API response.
     * @return array
     */
    private function parse_response( array $response ): array {
        $choice  = $response['choices'][0] ?? array();
        $message = $choice['message'] ?? array();

        $result = array(
            'content'       => $message['content'] ?? '',
            'finish_reason' => $choice['finish_reason'] ?? '',
            'tool_calls'    => array(),
            'usage'         => $response['usage'] ?? array(),
        );

        if ( ! empty( $message['tool_calls'] ) ) {
            $result['tool_calls'] = $message['tool_calls'];
        }

        return $result;
    }
}
