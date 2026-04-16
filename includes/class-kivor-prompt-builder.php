<?php
/**
 * System prompt builder.
 *
 * Constructs the system prompt from base instructions, admin custom instructions,
 * store context, knowledge base context, and product search results.
 *
 * @package KivorAgent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Kivor_Prompt_Builder {

	/**
	 * Settings instance.
	 *
	 * @var Kivor_Settings
	 */
	private Kivor_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Kivor_Settings $settings Plugin settings.
	 */
	public function __construct( Kivor_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the complete system prompt.
	 *
	 * @param array $context Optional additional context to inject.
	 *                       - 'kb_articles'     => string  Relevant knowledge base excerpts.
	 *                       - 'product_results' => string  Product search results text.
	 * @return string The system prompt.
	 */
	public function build( array $context = array() ): string {
		$general  = $this->settings->get( 'general' );
		$override = ! empty( $general['override_system_instructions'] );

		if ( $override && ! empty( $general['custom_instructions'] ) ) {
			// Admin chose to fully replace the default system prompt.
			$prompt = trim( $general['custom_instructions'] );
		} else {
			$prompt = $this->get_base_instructions();

			// Append admin custom instructions.
			if ( ! empty( $general['custom_instructions'] ) ) {
				$prompt .= "\n\n" . $this->section(
					'Additional Instructions',
					trim( $general['custom_instructions'] )
				);
			}
		}

		// Store context.
		$store_context = $this->get_store_context();
		if ( ! empty( $store_context ) ) {
			$prompt .= "\n\n" . $this->section( 'Store Information', $store_context );
		}

		// Knowledge base context (injected per-query from semantic search).
		if ( ! empty( $context['kb_articles'] ) ) {
			$prompt .= "\n\n" . $this->section(
				'Knowledge Base',
				"Use the following information to answer the user's question when relevant:\n\n" . $context['kb_articles']
			);
		}

		// Product search results (injected after tool call execution).
		if ( ! empty( $context['product_results'] ) ) {
			$prompt .= "\n\n" . $this->section(
				'Product Search Results',
				"The following products were found. Present them to the user in a helpful way. "
				. "Include key details like name, price, and a brief description. "
				. "If the results don't match what the user asked for, let them know and suggest refining their search.\n\n"
				. $context['product_results']
			);
		}

		return $prompt;
	}

	/**
	 * Get the base system instructions.
	 *
	 * This is the default prompt that defines the chatbot's behavior.
	 *
	 * @return string
	 */
	private function get_base_instructions(): string {
		$bot_name  = $this->settings->get( 'general.bot_name', 'Kivor' );
		$site_name = get_bloginfo( 'name' );

		$has_wc = class_exists( 'WooCommerce' );

		$instructions = "You are {$bot_name}, a helpful AI assistant for {$site_name}.";

		if ( $has_wc ) {
			$instructions .= "\nYou are a knowledgeable shopping assistant that helps customers find products, "
				. "answers questions about the store, and provides recommendations.";
		}

		$instructions .= "\n\nCore behavior:"
			. "\n- Be helpful, accurate, and concise."
			. "\n- Answer in the same language the user writes in."
			. "\n- If you don't know something, say so honestly."
			. "\n- Never make up product information, prices, or availability."
			. "\n- Keep responses focused and relevant.";

		if ( $has_wc ) {
			$instructions .= "\n\nProduct search behavior:"
				. "\n- When a user asks about products, use the search_products function to find relevant items."
				. "\n- When presenting products, include the product name, price (with the actual currency symbol, e.g. $, ₦), and a brief description."
				. "\n- Do NOT display product links and do NOT include the text 'View Product'."
				. "\n- If no products match, let the user know and suggest broadening their search."
				. "\n- You can search by keyword, category, price range, tags, and stock availability."
				. "\n- Do NOT invent products. Only present products returned by the search function.";
		}

		$forms_settings = $this->settings->get( 'forms', array() );
		if ( ! empty( $forms_settings['enabled'] ) ) {
			$instructions .= "\n\nForms behavior:"
				. "\n- When a user needs to submit details (lead capture, refunds, support requests, bug reports), use the show_form function."
				. "\n- Use show_form only when collecting structured data is necessary."
				. "\n- Explain briefly why the form is being shown.";
		}

		$instructions .= "\n\nFormatting:"
			. "\n- Use short paragraphs and clear structure."
			. "\n- You may use simple Markdown for readability: headings (##, ###), ordered lists (1.), unordered lists (-), bold (**text**), italic (*text*), and inline code (`code`)."
			. "\n- Do not output raw HTML tags."
			. "\n- If multiple products are returned, use a numbered ordered list (1., 2., 3.), with each option starting on a new line."
			. "\n- Keep formatting clean and minimal; avoid deeply nested lists.";

		return $instructions;
	}

	/**
	 * Get store context information.
	 *
	 * Provides store metadata to the AI so it can answer store-related questions.
	 *
	 * @return string
	 */
	private function get_store_context(): string {
		$parts = array();

		$parts[] = 'Site: ' . get_bloginfo( 'name' );
		$parts[] = 'URL: ' . home_url();

		if ( class_exists( 'WooCommerce' ) ) {
			$parts[] = 'Platform: WooCommerce ' . WC_VERSION;
			$parts[] = 'Currency: ' . get_woocommerce_currency() . ' (' . get_woocommerce_currency_symbol() . ')';

			// Count published products.
			$product_count = wp_count_posts( 'product' );
			if ( $product_count && ! empty( $product_count->publish ) ) {
				$parts[] = 'Published products: ' . $product_count->publish;
			}

			// Product categories.
			$categories = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 20,
				'fields'     => 'names',
			) );
			if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
				$parts[] = 'Categories: ' . implode( ', ', $categories );
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Get the tool definitions for AI function calling.
	 *
	 * These define the search_products function that the AI can call
	 * to trigger a WooCommerce product search.
	 *
	 * @return array Tool definitions in OpenAI function calling format.
	 */
	public function get_tool_definitions(): array {
		$tools = array();

		if ( class_exists( 'WooCommerce' ) ) {
			$tools[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'search_products',
					'description' => 'Search the store\'s product catalog. Use this when the user asks about products, wants recommendations, or asks what\'s available.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'keyword'       => array(
								'type'        => 'string',
								'description' => 'Search keyword or phrase to match against product names and descriptions.',
							),
							'category'      => array(
								'type'        => 'string',
								'description' => 'Product category name to filter by.',
							),
							'min_price'     => array(
								'type'        => 'number',
								'description' => 'Minimum price filter.',
							),
							'max_price'     => array(
								'type'        => 'number',
								'description' => 'Maximum price filter.',
							),
							'tag'           => array(
								'type'        => 'string',
								'description' => 'Product tag to filter by.',
							),
							'in_stock_only' => array(
								'type'        => 'boolean',
								'description' => 'If true, only return products that are in stock.',
							),
							'sort_by'       => array(
								'type'        => 'string',
								'enum'        => array( 'relevance', 'price_low', 'price_high', 'newest', 'popularity' ),
								'description' => 'How to sort the results.',
							),
							'limit'         => array(
								'type'        => 'integer',
								'description' => 'Maximum number of products to return (1-12, default 6).',
							),
						),
						'required'   => array(),
					),
				),
			);
		}

		$forms_settings = $this->settings->get( 'forms', array() );
		if ( ! empty( $forms_settings['enabled'] ) ) {
			$form_manager = Kivor_Form_Manager::instance( $this->settings );
			$ai_forms     = $form_manager->get_ai_eligible_forms();

			if ( ! empty( $ai_forms ) ) {
				$form_ids = array_map(
					static function ( array $form ) {
						return (int) $form['id'];
					},
					$ai_forms
				);

				$form_summaries = array_map(
					static function ( array $form ) {
						return array(
							'id'                   => (int) $form['id'],
							'name'                 => (string) ( $form['name'] ?? '' ),
							'trigger_instructions' => (string) ( $form['trigger_instructions'] ?? '' ),
						);
					},
					$ai_forms
				);

				$show_form_description =
					'Show a form to the user for structured data collection. '
					. 'Trigger instructions are the primary source of truth for deciding whether to show a form. '
					. 'Only call this when the current user message clearly matches the selected form trigger instructions. '
					. 'Do not call show_form for greetings, casual conversation, or unrelated requests. '
					. 'If uncertain, ask a short clarifying question instead of showing any form. '
					. 'Form catalog (id, name, trigger_instructions): '
					. wp_json_encode( $form_summaries );

				$tools[] = array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'show_form',
						'description' => $show_form_description,
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'form_id' => array(
									'type'        => 'integer',
									'enum'        => $form_ids,
									'description' => 'The exact form ID from the form catalog that best matches the user intent and trigger instructions.',
								),
								'reason'  => array(
									'type'        => 'string',
									'description' => 'Short reason tied to the selected form trigger instructions.',
								),
							),
							'required'   => array( 'form_id' ),
						),
					),
				);
			}
		}

		return $tools;
	}

	/**
	 * Build conversation messages array from history + current message.
	 *
	 * Enforces the rolling window size from settings.
	 *
	 * @param string $system_prompt System prompt string.
	 * @param array  $history       Conversation history: [ ['role' => '...', 'content' => '...'], ... ]
	 * @param string $user_message  Current user message.
	 * @return array Messages array ready for the AI provider.
	 */
	public function build_messages( string $system_prompt, array $history, string $user_message ): array {
		$memory_size = absint( $this->settings->get( 'ai_provider.conversation_memory_size', 10 ) );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
		);

		// Apply rolling window to history.
		if ( ! empty( $history ) && $memory_size > 0 ) {
			$history = array_slice( $history, -$memory_size );

			foreach ( $history as $msg ) {
				if ( ! isset( $msg['role'], $msg['content'] ) ) {
					continue;
				}
				// Only allow user/assistant roles from history.
				if ( ! in_array( $msg['role'], array( 'user', 'assistant' ), true ) ) {
					continue;
				}
				$messages[] = array(
					'role'    => $msg['role'],
					'content' => $msg['content'],
				);
			}
		}

		// Add current user message.
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);

		return $messages;
	}

	/**
	 * Format a prompt section with a label.
	 *
	 * @param string $label   Section label.
	 * @param string $content Section content.
	 * @return string
	 */
	private function section( string $label, string $content ): string {
		return "[{$label}]\n{$content}";
	}
}
