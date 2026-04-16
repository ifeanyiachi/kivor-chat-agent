<?php
/**
 * Input sanitization helpers.
 *
 * Provides reusable sanitization methods for the plugin.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Sanitizer {

    /**
     * Sanitize a chat message.
     *
     * Strips HTML tags but preserves newlines. Limits length.
     * Removes potential injection attempts.
     *
     * @param string $message  Raw message.
     * @param int    $max_length Maximum allowed length.
     * @return string
     */
    public static function sanitize_message( string $message, int $max_length = 2000 ): string {
        // Strip HTML tags.
        $clean = wp_strip_all_tags( $message );

        // Remove null bytes and other control characters (except newlines and tabs)
        $clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean );

        // Normalize whitespace (collapse multiple spaces but keep newlines).
        $clean = preg_replace( '/[^\S\n]+/', ' ', $clean );

        // Trim.
        $clean = trim( $clean );

        // Enforce length limit.
        if ( mb_strlen( $clean ) > $max_length ) {
            $clean = mb_substr( $clean, 0, $max_length );
        }

        // Final trim after truncation
        $clean = trim( $clean );

        return $clean;
    }

    /**
     * Sanitize a session ID.
     *
     * Session IDs should be alphanumeric with hyphens only.
     * Limits length to prevent DoS attacks.
     *
     * @param string $session_id Raw session ID.
     * @return string
     */
    public static function sanitize_session_id( string $session_id ): string {
        // Remove all non-alphanumeric characters except hyphens
        $clean = preg_replace( '/[^a-zA-Z0-9\-]/', '', $session_id );
        
        // Limit length to 64 characters (UUID4 is 36 characters)
        if ( strlen( $clean ) > 64 ) {
            $clean = substr( $clean, 0, 64 );
        }
        
        // Ensure minimum length
        if ( strlen( $clean ) < 8 ) {
            return self::generate_session_id();
        }
        
        return $clean;
    }

    /**
     * Generate a new session ID.
     *
     * @return string
     */
    public static function generate_session_id(): string {
        return wp_generate_uuid4();
    }

    /**
     * Hash an IP address for GDPR-safe storage.
     *
     * Uses a daily rotating salt so IPs can't be reversed,
     * but same IPs on the same day produce the same hash (for rate limiting).
     *
     * @param string $ip  Raw IP address.
     * @param bool   $anonymize Whether to fully anonymize (use random salt).
     * @return string
     */
    public static function hash_ip( string $ip, bool $anonymize = false ): string {
        if ( $anonymize ) {
            // Fully anonymized: use a random salt so it can't be correlated.
            return hash( 'sha256', $ip . wp_generate_password( 32, true, true ) );
        }

        // Daily rotating salt for rate limiting correlation.
        $daily_salt = wp_salt( 'auth' ) . gmdate( 'Y-m-d' );
        return hash( 'sha256', $ip . $daily_salt );
    }

    /**
     * Get the client IP address.
     *
     * Respects proxy headers in a safe order.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $headers = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare.
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // X-Forwarded-For can contain multiple IPs; take the first.
                $ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
                $ip = trim( $ip[0] );

                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Sanitize a knowledge base article content.
     *
     * Allows safe HTML, enforces character limit.
     *
     * @param string $content Raw content.
     * @param int    $max_length Maximum characters (default 5000).
     * @return string
     */
    public static function sanitize_kb_content( string $content, int $max_length = 5000 ): string {
        // Allow safe HTML tags.
        $clean = wp_kses_post( $content );

        // Enforce character limit on the text content (not HTML).
        $text_length = mb_strlen( wp_strip_all_tags( $clean ) );
        if ( $text_length > $max_length ) {
            // Truncate the raw text and re-sanitize.
            $clean = mb_substr( wp_strip_all_tags( $clean ), 0, $max_length );
        }

        return $clean;
    }

    /**
     * Sanitize a URL for web scraping.
     *
     * @param string $url Raw URL.
     * @return string|false Sanitized URL or false if invalid.
     */
    public static function sanitize_scrape_url( string $url ) {
        $url = esc_url_raw( trim( $url ) );

        if ( empty( $url ) ) {
            return false;
        }

        // Must be HTTP or HTTPS.
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return false;
        }

        // Block localhost and private IPs.
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return false;
        }

        // Check if host is localhost or internal
        $lowercase_host = strtolower( $host );
        $blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' );
        if ( in_array( $lowercase_host, $blocked_hosts, true ) ) {
            return false;
        }

        // Block common internal hostnames
        if ( preg_match( '/\.(local|internal|private|lan|home|corp|intranet)$/i', $lowercase_host ) ) {
            return false;
        }

        $ip = gethostbyname( $host );
        if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return false;
        }

        // Additional check: prevent DNS rebinding by verifying the IP is not in private ranges
        // even if the hostname doesn't look suspicious
        $ips = gethostbynamel( $host );
        if ( is_array( $ips ) ) {
            foreach ( $ips as $check_ip ) {
                if ( filter_var( $check_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                    return false;
                }
            }
        }

        return $url;
    }

    /**
     * Sanitize a WhatsApp phone number.
     *
     * Strips everything except digits and leading +.
     *
     * @param string $number Raw phone number.
     * @return string
     */
    public static function sanitize_phone_number( string $number ): string {
        // Keep digits and leading +.
        $clean = preg_replace( '/[^\d+]/', '', trim( $number ) );

        // Ensure + is only at the start.
        if ( strlen( $clean ) > 1 ) {
            $first = substr( $clean, 0, 1 );
            $rest  = preg_replace( '/\+/', '', substr( $clean, 1 ) );
            $clean = $first . $rest;
        }

        return $clean;
    }

    /**
     * Sanitize and validate a JSON string.
     *
     * @param string $json Raw JSON string.
     * @return array|null Decoded array or null if invalid.
     */
    public static function sanitize_json( string $json ): ?array {
        $decoded = json_decode( $json, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return null;
        }

        return $decoded;
    }
}
