<?php
/**
 * Forms manager.
 *
 * Handles form CRUD, trigger matching, validation, submissions, and notifications.
 *
 * @package KivorAgent
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Form_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var Kivor_Form_Manager|null
	 */
	private static ?Kivor_Form_Manager $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Forms table name.
	 *
	 * @var string
	 */
	private string $forms_table;

	/**
	 * Submissions table name.
	 *
	 * @var string
	 */
	private string $submissions_table;

	/**
	 * Whether schema was checked for this request.
	 *
	 * @var bool
	 */
	private static bool $schema_checked = false;

	/**
	 * Allowed field types.
	 *
	 * @var array
	 */
	private array $allowed_field_types = array( 'text', 'email', 'phone', 'textarea', 'select', 'checkbox' );

	/**
	 * Get singleton instance.
	 *
	 * @param Kivor_Settings|null $settings Optional settings instance.
	 * @return Kivor_Form_Manager
	 */
	public static function instance( ?Kivor_Settings $settings = null ): Kivor_Form_Manager {
		if ( null === self::$instance ) {
			if ( null === $settings ) {
				$settings = new Kivor_Settings();
			}
			self::$instance = new self( $settings );
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		global $wpdb;

		$this->settings          = $settings;
		$this->forms_table       = $wpdb->prefix . 'kivor_forms';
		$this->submissions_table = $wpdb->prefix . 'kivor_form_submissions';
		$this->ensure_schema();
	}

	/**
	 * Ensure required form table columns exist.
	 *
	 * @return void
	 */
	private function ensure_schema(): void {
		if ( self::$schema_checked ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$this->forms_table} LIKE %s", 'trigger_instructions' ) );
		if ( null === $column ) {
			$wpdb->query( "ALTER TABLE {$this->forms_table} ADD COLUMN trigger_instructions text AFTER fields" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$legacy_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$this->forms_table} LIKE %s", 'keyword_triggers' ) );
		if ( null !== $legacy_column ) {
			$wpdb->query( "ALTER TABLE {$this->forms_table} DROP COLUMN keyword_triggers" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		self::$schema_checked = true;
	}

	/**
	 * Check whether forms feature is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$forms_settings = $this->settings->get( 'forms', array() );
		return ! empty( $forms_settings['enabled'] );
	}

	/**
	 * Get all forms.
	 *
	 * @return array
	 */
	public function get_forms(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$this->forms_table} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_form_row' ), $rows );
	}

	/**
	 * Get single form.
	 *
	 * @param int $id Form ID.
	 * @return array|null
	 */
	public function get_form( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->forms_table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate_form_row( $row );
	}

	/**
	 * Create form.
	 *
	 * @param array $data Form data.
	 * @return array|WP_Error
	 */
	public function create_form( array $data ) {
		global $wpdb;

		$sanitized = $this->sanitize_form_payload( $data );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$inserted = $wpdb->insert(
			$this->forms_table,
			array(
				'name'                 => $sanitized['name'],
				'fields'               => wp_json_encode( $sanitized['fields'] ),
				'trigger_instructions' => $sanitized['trigger_instructions'],
				'is_ai_eligible'       => $sanitized['is_ai_eligible'] ? 1 : 0,
				'is_primary'           => 0,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'kivor_chat_agent_form_create_failed',
				__( 'Failed to create form.', 'kivor-chat-agent' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		return $this->get_form( (int) $wpdb->insert_id );
	}

	/**
	 * Update form.
	 *
	 * @param int   $id   Form ID.
	 * @param array $data Form data.
	 * @return array|WP_Error
	 */
	public function update_form( int $id, array $data ) {
		global $wpdb;

		if ( ! $this->get_form( $id ) ) {
			return new \WP_Error(
				'kivor_chat_agent_form_not_found',
				__( 'Form not found.', 'kivor-chat-agent' ),
				array( 'status' => 404 )
			);
		}

		$sanitized = $this->sanitize_form_payload( $data );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$updated = $wpdb->update(
			$this->forms_table,
			array(
				'name'                 => $sanitized['name'],
				'fields'               => wp_json_encode( $sanitized['fields'] ),
				'trigger_instructions' => $sanitized['trigger_instructions'],
				'is_ai_eligible'       => $sanitized['is_ai_eligible'] ? 1 : 0,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'kivor_chat_agent_form_update_failed',
				__( 'Failed to update form.', 'kivor-chat-agent' ),
				array( 'status' => 500 )
			);
		}

		return $this->get_form( $id );
	}

	/**
	 * Delete form.
	 *
	 * @param int $id Form ID.
	 * @return bool|WP_Error
	 */
	public function delete_form( int $id ) {
		global $wpdb;

		if ( ! $this->get_form( $id ) ) {
			return new \WP_Error(
				'kivor_chat_agent_form_not_found',
				__( 'Form not found.', 'kivor-chat-agent' ),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$wpdb->delete( $this->submissions_table, array( 'form_id' => $id ), array( '%d' ) );
		$deleted = $wpdb->delete( $this->forms_table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $deleted ) {
			return new \WP_Error(
				'kivor_chat_agent_form_delete_failed',
				__( 'Failed to delete form.', 'kivor-chat-agent' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Get primary form from settings.
	 *
	 * @return array|null
	 */
	public function get_primary_form(): ?array {
		$form_id = absint( $this->settings->get( 'forms.primary_form_id', 0 ) );
		if ( $form_id <= 0 ) {
			return null;
		}

		return $this->get_form( $form_id );
	}

	/**
	 * Get configured tab form from settings.
	 *
	 * @return array|null
	 */
	public function get_tab_form(): ?array {
		$form_id = absint( $this->settings->get( 'forms.tab_form_id', 0 ) );
		if ( $form_id <= 0 ) {
			return null;
		}

		return $this->get_form( $form_id );
	}

	/**
	 * Get AI-eligible form by ID.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null
	 */
	public function get_form_for_ai( int $form_id ): ?array {
		$form = $this->get_form( $form_id );
		if ( ! $form ) {
			return null;
		}

		if ( empty( $form['is_ai_eligible'] ) ) {
			return null;
		}

		return $form;
	}

	/**
	 * Get AI-eligible forms.
	 *
	 * @return array
	 */
	public function get_ai_eligible_forms(): array {
		return array_values(
			array_filter(
				$this->get_forms(),
				static function ( array $form ) {
					return ! empty( $form['is_ai_eligible'] );
				}
			)
		);
	}

	/**
	 * Validate and submit a form.
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $session_id Session ID.
	 * @param array  $data       Submission payload.
	 * @return array|WP_Error
	 */
	public function submit_form( int $form_id, string $session_id, array $data ) {
		$form = $this->get_form( $form_id );
		if ( ! $form ) {
			return new \WP_Error(
				'kivor_chat_agent_form_not_found',
				__( 'Form not found.', 'kivor-chat-agent' ),
				array( 'status' => 404 )
			);
		}

		$validation = $this->validate_submission_data( $form, $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$submission_id = $this->store_submission( $form_id, $session_id, $validation );
		if ( is_wp_error( $submission_id ) ) {
			return $submission_id;
		}

		$this->send_submission_notification( $form, $validation, $session_id );

		return array(
			'success'       => true,
			'submission_id' => $submission_id,
			'form_id'       => $form_id,
		);
	}

	/**
	 * Get submissions.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function get_submissions( array $args = array() ): array {
		global $wpdb;

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( max( 1, absint( $args['per_page'] ?? 20 ) ), 200 );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array();
		$params = array();

		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 's.form_id = %d';
			$params[] = absint( $args['form_id'] );
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$this->submissions_table} s {$where_sql}";
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
			$count_sql = $wpdb->prepare( $count_sql, ...$params );
		}
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$total = (int) $wpdb->get_var( $count_sql );

		$query = "SELECT s.*, f.name AS form_name FROM {$this->submissions_table} s LEFT JOIN {$this->forms_table} f ON f.id = s.form_id {$where_sql} ORDER BY s.created_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );

		if ( empty( $rows ) ) {
			$rows = array();
		}

		foreach ( $rows as &$row ) {
			$row['id']         = (int) $row['id'];
			$row['form_id']    = (int) $row['form_id'];
			$row['data']       = json_decode( (string) ( $row['data'] ?? '{}' ), true ) ?: array();
			$row['session_id'] = (string) ( $row['session_id'] ?? '' );
		}

		return array(
			'items'       => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Build frontend-safe form payload.
	 *
	 * @param array $form       Form definition.
	 * @param bool  $is_primary Is primary form.
	 * @param bool  $block      Whether to block chat input.
	 * @return array
	 */
	public function build_form_payload( array $form, bool $is_primary = false, bool $block = false ): array {
		return array(
			'form_id'    => (int) $form['id'],
			'form_data'  => array(
				'id'                   => (int) $form['id'],
				'name'                 => (string) $form['name'],
				'fields'               => is_array( $form['fields'] ) ? $form['fields'] : array(),
				'trigger_instructions' => (string) ( $form['trigger_instructions'] ?? '' ),
				'is_ai_eligible'       => ! empty( $form['is_ai_eligible'] ),
			),
			'is_primary' => $is_primary,
			'block_input' => $block,
		);
	}

	/**
	 * Hydrate form DB row into normalized array.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private function hydrate_form_row( array $row ): array {
		$fields = json_decode( (string) ( $row['fields'] ?? '[]' ), true );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		return array(
			'id'                   => (int) $row['id'],
			'name'                 => (string) ( $row['name'] ?? '' ),
			'fields'               => $fields,
			'trigger_instructions' => (string) ( $row['trigger_instructions'] ?? '' ),
			'is_ai_eligible'       => isset( $row['is_ai_eligible'] ) ? ( 1 === (int) $row['is_ai_eligible'] ) : true,
			'is_primary'           => isset( $row['is_primary'] ) ? ( 1 === (int) $row['is_primary'] ) : false,
			'created_at'           => (string) ( $row['created_at'] ?? '' ),
			'updated_at'           => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Sanitize form payload for create/update.
	 *
	 * @param array $data Raw input.
	 * @return array|WP_Error
	 */
	private function sanitize_form_payload( array $data ) {
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_form_name',
				__( 'Form name is required.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$raw_fields = $data['fields'] ?? array();
		if ( ! is_array( $raw_fields ) || empty( $raw_fields ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_form_fields',
				__( 'At least one field is required.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$fields = array();
		$seen_names = array();

		foreach ( $raw_fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = sanitize_key( $field['type'] ?? 'text' );
			if ( ! in_array( $type, $this->allowed_field_types, true ) ) {
				return new \WP_Error(
					'kivor_chat_agent_invalid_field_type',
					sprintf(
						/* translators: %d: field index */
						__( 'Field #%d has an invalid type.', 'kivor-chat-agent' ),
						(int) $index + 1
					),
					array( 'status' => 400 )
				);
			}

			$label = sanitize_text_field( $field['label'] ?? '' );
			$name_key = sanitize_key( $field['name'] ?? '' );

			if ( '' === $label || '' === $name_key ) {
				return new \WP_Error(
					'kivor_chat_agent_invalid_field_definition',
					sprintf(
						/* translators: %d: field index */
						__( 'Field #%d must include label and name.', 'kivor-chat-agent' ),
						(int) $index + 1
					),
					array( 'status' => 400 )
				);
			}

			if ( in_array( $name_key, $seen_names, true ) ) {
				return new \WP_Error(
					'kivor_chat_agent_duplicate_field_name',
					sprintf(
						/* translators: %s: field name */
						__( 'Duplicate field name: %s', 'kivor-chat-agent' ),
						$name_key
					),
					array( 'status' => 400 )
				);
			}
			$seen_names[] = $name_key;

			$normalized = array(
				'type'        => $type,
				'label'       => $label,
				'name'        => $name_key,
				'required'    => ! empty( $field['required'] ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'min_length'  => isset( $field['min_length'] ) ? max( 0, absint( $field['min_length'] ) ) : 0,
				'max_length'  => isset( $field['max_length'] ) ? min( 4000, max( 0, absint( $field['max_length'] ) ) ) : 255,
			);

			if ( 'checkbox' === $type ) {
				$normalized['min_length'] = 0;
				$normalized['max_length'] = 0;
			}

			if ( 'select' === $type ) {
				$options = $field['options'] ?? array();
				if ( ! is_array( $options ) ) {
					$options = array();
				}

				$options = array_values(
					array_filter(
						array_map( 'sanitize_text_field', $options ),
						static function ( string $value ) {
							return '' !== trim( $value );
						}
					)
				);

				if ( empty( $options ) ) {
					return new \WP_Error(
						'kivor_chat_agent_invalid_select_options',
						sprintf(
							/* translators: %d: field index */
							__( 'Select field #%d requires at least one option.', 'kivor-chat-agent' ),
							(int) $index + 1
						),
						array( 'status' => 400 )
					);
				}

				$normalized['options'] = $options;
			} else {
				$normalized['options'] = array();
			}

			if ( $normalized['max_length'] > 0 && $normalized['min_length'] > $normalized['max_length'] ) {
				$normalized['min_length'] = $normalized['max_length'];
			}

			$fields[] = $normalized;
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'kivor_chat_agent_invalid_form_fields',
				__( 'At least one valid field is required.', 'kivor-chat-agent' ),
				array( 'status' => 400 )
			);
		}

		$is_ai_eligible = true;
		if ( array_key_exists( 'is_ai_eligible', $data ) ) {
			$is_ai_eligible = ! empty( $data['is_ai_eligible'] );
		}

		$trigger_instructions = '';
		if ( array_key_exists( 'trigger_instructions', $data ) ) {
			$trigger_instructions = sanitize_textarea_field( (string) $data['trigger_instructions'] );
		}

		return array(
			'name'                 => $name,
			'fields'               => $fields,
			'trigger_instructions' => $trigger_instructions,
			'is_ai_eligible'       => $is_ai_eligible,
		);
	}

	/**
	 * Validate form submission data.
	 *
	 * @param array $form Form schema.
	 * @param array $data Raw data.
	 * @return array|WP_Error
	 */
	private function validate_submission_data( array $form, array $data ) {
		$sanitized = array();
		$errors    = array();

		foreach ( $form['fields'] as $field ) {
			$name     = $field['name'];
			$type     = $field['type'];
			$required = ! empty( $field['required'] );

			$value = $data[ $name ] ?? null;

			if ( 'checkbox' === $type ) {
				$checked = ! empty( $value ) && ! in_array( $value, array( '0', 'false', 'off' ), true );
				if ( $required && ! $checked ) {
					$errors[ $name ] = __( 'This checkbox must be checked.', 'kivor-chat-agent' );
				}
				$sanitized[ $name ] = $checked;
				continue;
			}

			$string_value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( $required && '' === $string_value ) {
				$errors[ $name ] = __( 'This field is required.', 'kivor-chat-agent' );
				continue;
			}

			if ( '' === $string_value ) {
				$sanitized[ $name ] = '';
				continue;
			}

			switch ( $type ) {
				case 'email':
					if ( ! is_email( $string_value ) ) {
						$errors[ $name ] = __( 'Please enter a valid email address.', 'kivor-chat-agent' );
					} else {
						$sanitized[ $name ] = sanitize_email( $string_value );
					}
					break;

				case 'phone':
					$clean_phone = preg_replace( '/[^\d\+\-\(\)\s]/', '', $string_value );
					if ( strlen( preg_replace( '/\D/', '', $clean_phone ) ) < 7 ) {
						$errors[ $name ] = __( 'Please enter a valid phone number.', 'kivor-chat-agent' );
					} else {
						$sanitized[ $name ] = $clean_phone;
					}
					break;

				case 'select':
					$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
					if ( ! in_array( $string_value, $options, true ) ) {
						$errors[ $name ] = __( 'Please choose a valid option.', 'kivor-chat-agent' );
					} else {
						$sanitized[ $name ] = sanitize_text_field( $string_value );
					}
					break;

				default:
					$min = absint( $field['min_length'] ?? 0 );
					$max = absint( $field['max_length'] ?? 0 );

					$length = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $string_value, 'UTF-8' ) : strlen( $string_value );
					if ( $min > 0 && $length < $min ) {
						$errors[ $name ] = sprintf(
							/* translators: %d: minimum length */
							__( 'Minimum length is %d characters.', 'kivor-chat-agent' ),
							$min
						);
						break;
					}

					if ( $max > 0 && $length > $max ) {
						$errors[ $name ] = sprintf(
							/* translators: %d: maximum length */
							__( 'Maximum length is %d characters.', 'kivor-chat-agent' ),
							$max
						);
						break;
					}

					$sanitized[ $name ] = 'textarea' === $type
						? sanitize_textarea_field( $string_value )
						: sanitize_text_field( $string_value );
					break;
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'kivor_chat_agent_form_validation_failed',
				__( 'Please correct the highlighted fields.', 'kivor-chat-agent' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		return $sanitized;
	}

	/**
	 * Store form submission.
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $session_id Session ID.
	 * @param array  $data       Validated submission data.
	 * @return int|WP_Error
	 */
	private function store_submission( int $form_id, string $session_id, array $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		$inserted = $wpdb->insert(
			$this->submissions_table,
			array(
				'form_id'    => $form_id,
				'session_id' => sanitize_text_field( $session_id ),
				'data'       => wp_json_encode( $data ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'kivor_chat_agent_form_submission_failed',
				__( 'Failed to save form submission.', 'kivor-chat-agent' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB
		return (int) $wpdb->insert_id;
	}

	/**
	 * Send optional admin notification email.
	 *
	 * @param array  $form       Form definition.
	 * @param array  $data       Submitted values.
	 * @param string $session_id Session ID.
	 * @return void
	 */
	private function send_submission_notification( array $form, array $data, string $session_id ): void {
		$forms_settings = $this->settings->get( 'forms', array() );
		if ( empty( $forms_settings['notify_email_enabled'] ) ) {
			return;
		}

		$emails_raw = (string) ( $forms_settings['notify_email_to'] ?? '' );
		if ( '' === trim( $emails_raw ) ) {
			$emails_raw = (string) get_option( 'admin_email', '' );
		}

		$emails = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $emails_raw ) ),
				static function ( string $email ) {
					return (bool) is_email( $email );
				}
			)
		);

		if ( empty( $emails ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: form name */
			__( '[Kivor Chat Agent] New form submission: %s', 'kivor-chat-agent' ),
			$form['name']
		);

		$lines = array();
		// translators: %s: form name.
		$lines[] = sprintf( __( 'Form: %s', 'kivor-chat-agent' ), $form['name'] );
		// translators: %s: session ID.
		$lines[] = sprintf( __( 'Session: %s', 'kivor-chat-agent' ), $session_id );
		$lines[] = '';
		$lines[] = __( 'Submission:', 'kivor-chat-agent' );

		foreach ( $form['fields'] as $field ) {
			$name  = $field['name'];
			$label = $field['label'];
			$value = $data[ $name ] ?? '';
			if ( is_bool( $value ) ) {
				$value = $value ? 'Yes' : 'No';
			}
			$lines[] = '- ' . $label . ': ' . $value;
		}

		wp_mail( $emails, $subject, implode( "\n", $lines ) );
	}
}
