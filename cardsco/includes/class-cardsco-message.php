<?php
/**
 * Message library.
 *
 * The wording that gets handwritten inside a card. Stored as plain text in
 * post meta rather than in the editor, because a handwritten card has no
 * paragraphs, headings or bold, and running it through the block editor would
 * only introduce markup that has to be stripped out again before writing.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Message {

	const POST_TYPE = 'cardsco_message';
	const OCCASION  = '_cardsco_occasion';
	const COMPANY   = '_cardsco_company_id';
	const TEXT      = '_cardsco_text';
	const NONCE     = 'cardsco_message_meta';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'carry_warning' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_cardsco_seed_messages', array( __CLASS__, 'handle_seed' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Messages', 'cardsco' ),
					'singular_name'      => __( 'Message', 'cardsco' ),
					'add_new'            => __( 'Add message', 'cardsco' ),
					'add_new_item'       => __( 'Add message', 'cardsco' ),
					'edit_item'          => __( 'Edit message', 'cardsco' ),
					'new_item'           => __( 'New message', 'cardsco' ),
					'search_items'       => __( 'Search messages', 'cardsco' ),
					'not_found'          => __( 'No messages yet.', 'cardsco' ),
					'not_found_in_trash' => __( 'No messages in the bin.', 'cardsco' ),
					'all_items'          => __( 'Messages', 'cardsco' ),
					'menu_name'          => __( 'Messages', 'cardsco' ),
				),
				'public'          => false,
				'show_ui'         => true,
				// Attached to the CardsCo menu by hand in CardsCo_Admin::menu();
				// see the note there for why show_in_menu cannot do it.
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'page-attributes' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
				'map_meta_cap'    => true,
				'capability_type' => array( 'cardsco_message', 'cardsco_messages' ),
				'capabilities'    => CardsCo_Card::capabilities(),
			)
		);
	}

	/**
	 * Add the wording box.
	 *
	 * @return void
	 */
	public static function meta_boxes() {
		add_meta_box(
			'cardsco-message-text',
			__( 'Wording', 'cardsco' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Wording box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$text     = (string) get_post_meta( $post->ID, self::TEXT, true );
		$tone     = (string) get_post_meta( $post->ID, '_cardsco_tone', true );
		$selected = self::occasions( $post->ID );
		$types    = CardsCo_Occasion_Type::all( 0, array( 'active_only' => false ) );
		$limit    = (int) CardsCo_Settings::get( 'message_max_chars' );
		$preview  = CardsCo_Merge::render( $text, CardsCo_Merge::sample_context() );
		?>
		<style>
			.cardsco-msg textarea{width:100%;font-size:15px;line-height:1.6;font-family:Georgia,serif}
			.cardsco-msg .cardsco-occ{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px}
			.cardsco-msg .cardsco-occ label{display:flex;gap:6px;align-items:center;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:4px 10px}
			.cardsco-preview{background:#fffdf7;border:1px solid #e3dfd2;border-radius:4px;padding:16px 18px;font-family:Georgia,serif;font-size:15px;line-height:1.7;white-space:pre-wrap}
			.cardsco-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
			.cardsco-chips code{background:#f0f0f1;border-radius:3px;padding:2px 7px;font-size:12px}
		</style>

		<div class="cardsco-msg">
			<p>
				<label for="cardsco-text"><strong><?php esc_html_e( 'Message', 'cardsco' ); ?></strong></label>
				<textarea id="cardsco-text" name="cardsco_text" rows="5" maxlength="2000"><?php echo esc_textarea( $text ); ?></textarea>
			</p>

			<p class="description">
				<?php
				printf(
					/* translators: %d: character limit. */
					esc_html__( 'Up to %d characters once the names are filled in. Plain text only — this is written by hand.', 'cardsco' ),
					(int) $limit
				);
				?>
			</p>

			<p style="margin-bottom:4px"><strong><?php esc_html_e( 'You can drop these in', 'cardsco' ); ?></strong></p>
			<div class="cardsco-chips">
				<?php foreach ( CardsCo_Merge::fields() as $key => $field ) : ?>
					<code><?php echo esc_html( '{' . str_replace( '_', ' ', $key ) . '}' ); ?></code>
				<?php endforeach; ?>
			</div>
			<p class="description">
				<?php esc_html_e( 'Anything with no value for a particular person holds that card back rather than leaving a gap, so the card is never written with a hole in it.', 'cardsco' ); ?>
			</p>

			<?php if ( '' !== trim( $text ) ) : ?>
				<p style="margin-bottom:6px"><strong><?php esc_html_e( 'How it reads', 'cardsco' ); ?></strong></p>
				<div class="cardsco-preview"><?php echo esc_html( $preview['text'] ); ?></div>
				<p class="description">
					<?php
					printf(
						/* translators: 1: character count, 2: the limit. */
						esc_html__( '%1$d of %2$d characters with example names filled in.', 'cardsco' ),
						(int) $preview['length'],
						(int) $limit
					);
					?>
					<?php if ( $preview['length'] > $limit ) : ?>
						<strong style="color:#d63638"><?php esc_html_e( 'Too long.', 'cardsco' ); ?></strong>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<hr />

			<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px">
				<div>
					<label for="cardsco-tone"><strong><?php esc_html_e( 'Tone', 'cardsco' ); ?></strong></label>
					<select id="cardsco-tone" name="cardsco_tone" style="width:100%">
						<?php foreach ( self::tones() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tone, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<p style="margin-top:16px;margin-bottom:2px"><strong><?php esc_html_e( 'Suits these occasions', 'cardsco' ); ?></strong></p>
			<p class="description" style="margin-top:0"><?php esc_html_e( 'Tick none and the message is offered for every occasion.', 'cardsco' ); ?></p>
			<div class="cardsco-occ">
				<?php foreach ( $types as $type ) : ?>
					<label>
						<input type="checkbox" name="cardsco_occasions[]" value="<?php echo esc_attr( $type['id'] ); ?>" <?php checked( in_array( (int) $type['id'], $selected, true ) ); ?> />
						<?php echo esc_html( $type['label'] ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Available tones.
	 *
	 * @return array<string, string>
	 */
	public static function tones() {
		return array(
			'warm'     => __( 'Warm', 'cardsco' ),
			'sincere'  => __( 'Sincere', 'cardsco' ),
			'formal'   => __( 'Formal', 'cardsco' ),
			'playful'  => __( 'Playful', 'cardsco' ),
			'thankful' => __( 'Thankful', 'cardsco' ),
		);
	}

	/**
	 * Save the wording box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'cardsco_manage_catalogue' ) ) {
			return;
		}

		$text = isset( $_POST['cardsco_text'] ) ? wp_unslash( $_POST['cardsco_text'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$text = self::clean_text( $text );

		update_post_meta( $post_id, self::TEXT, $text );

		$tone = sanitize_key( $_POST['cardsco_tone'] ?? 'warm' );
		update_post_meta( $post_id, '_cardsco_tone', array_key_exists( $tone, self::tones() ) ? $tone : 'warm' );

		if ( '' === get_post_meta( $post_id, self::COMPANY, true ) ) {
			update_post_meta( $post_id, self::COMPANY, 0 );
		}

		CardsCo_Card::set_occasions( $post_id, (array) ( $_POST['cardsco_occasions'] ?? array() ) );

		// Keep whatever was wrong with it so a notice can be shown after the
		// redirect. Catching it here is the difference between fixing a
		// message now and discovering it when a campaign will not send.
		$problems = self::problems( $text );

		if ( $problems ) {
			set_transient( 'cardsco_msg_warn_' . $post_id, $problems, 60 );
		} else {
			delete_transient( 'cardsco_msg_warn_' . $post_id );
		}
	}

	/**
	 * Tidy message text without mangling it.
	 *
	 * Tags are stripped because none of them can be handwritten, and line
	 * endings are normalised so a count is the same whichever machine typed it.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( (string) $text );
	}

	/**
	 * What is wrong with a message, if anything.
	 *
	 * @param string $text Message text.
	 * @return string[]
	 */
	public static function problems( $text ) {
		if ( '' === trim( (string) $text ) ) {
			return array();
		}

		$limit    = (int) CardsCo_Settings::get( 'message_max_chars' );
		$rendered = CardsCo_Merge::render( $text, CardsCo_Merge::sample_context() );
		$check    = CardsCo_Merge::check( $rendered, $limit );
		$problems = is_wp_error( $check ) ? $check->get_error_messages() : array();

		// Flag a field whose data source is still an open question, so nobody
		// builds a campaign on it and finds out later.
		$fields = CardsCo_Merge::fields();

		foreach ( CardsCo_Merge::tokens( $text ) as $key ) {
			if ( ! empty( $fields[ $key ]['needs_data'] ) ) {
				$problems[] = sprintf(
					/* translators: 1: field name, 2: explanation. */
					__( '%1$s: %2$s', 'cardsco' ),
					$fields[ $key ]['label'],
					$fields[ $key ]['note']
				);
			}
		}

		return $problems;
	}

	/**
	 * Carry the warning through the save redirect.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function carry_warning( $location, $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return $location;
		}

		return get_transient( 'cardsco_msg_warn_' . $post_id )
			? add_query_arg( 'cardsco_msg_warn', 1, $location )
			: remove_query_arg( 'cardsco_msg_warn', $location );
	}

	/**
	 * Show the saved-with-problems notice, and the starter offer on an empty
	 * library.
	 *
	 * @return void
	 */
	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( ! empty( $_GET['cardsco_msg_warn'] ) && ! empty( $_GET['post'] ) ) {
			$problems = get_transient( 'cardsco_msg_warn_' . absint( $_GET['post'] ) );

			if ( $problems ) {
				echo '<div class="notice notice-warning"><p><strong>' .
					esc_html__( 'Saved, but worth a look:', 'cardsco' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';

				foreach ( (array) $problems as $problem ) {
					echo '<li>' . esc_html( $problem ) . '</li>';
				}

				echo '</ul></div>';
			}
		}

		if ( 'edit' === $screen->base && ! self::count() && current_user_can( 'cardsco_manage_catalogue' ) ) {
			$url = wp_nonce_url(
				add_query_arg( 'action', 'cardsco_seed_messages', admin_url( 'admin-post.php' ) ),
				'cardsco_seed_messages'
			);

			echo '<div class="notice notice-info"><p>' .
				esc_html__( 'The library is empty. The client\'s writer will supply the real wording, but you can drop in a few examples to build and test campaigns against.', 'cardsco' ) .
				'</p><p><a class="button" href="' . esc_url( $url ) . '">' .
				esc_html__( 'Add 8 example messages', 'cardsco' ) . '</a></p></div>';
		}
	}

	/**
	 * How many messages exist.
	 *
	 * @return int
	 */
	public static function count() {
		$counts = wp_count_posts( self::POST_TYPE );

		return (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 );
	}

	/**
	 * Create the example messages.
	 *
	 * @return void
	 */
	public static function handle_seed() {
		if ( ! current_user_can( 'cardsco_manage_catalogue' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'cardsco' ) );
		}

		check_admin_referer( 'cardsco_seed_messages' );

		$created = self::seed();

		wp_safe_redirect(
			add_query_arg(
				'cardsco_seeded',
				$created,
				admin_url( 'edit.php?post_type=' . self::POST_TYPE )
			)
		);
		exit;
	}

	/**
	 * Example wording, clearly ordinary enough to edit or bin.
	 *
	 * @return int Number created.
	 */
	public static function seed() {
		$birthday    = CardsCo_Occasion_Type::get_by_slug( 'birthday' );
		$work        = CardsCo_Occasion_Type::get_by_slug( 'work-anniversary' );
		$thanks      = CardsCo_Occasion_Type::get_by_slug( 'thank-you' );
		$christmas   = CardsCo_Occasion_Type::get_by_slug( 'christmas' );
		$anniversary = CardsCo_Occasion_Type::get_by_slug( 'customer-anniversary' );

		$examples = array(
			array( 'Birthday — warm', 'warm', $birthday, "Happy birthday {first name}!\n\nHope the day treats you well." ),
			array( 'Birthday — short', 'playful', $birthday, 'Many happy returns, {first name}. Have a good one.' ),
			array( 'Work anniversary', 'sincere', $work, "{first name}, another year with us.\n\nThank you for everything you bring to the team." ),
			array( 'Thank you — customer', 'thankful', $thanks, 'Thank you for your custom, {first name}. It genuinely makes a difference to us.' ),
			array( 'Thank you — formal', 'formal', $thanks, 'Dear {full name}, thank you for your continued support of our work this year.' ),
			array( 'Christmas', 'warm', $christmas, "Wishing you a peaceful Christmas, {first name}, and a good year ahead." ),
			array( 'Customer anniversary', 'thankful', $anniversary, "A year of working together, {first name}. Here's to the next one." ),
			array( 'General — no occasion', 'warm', null, 'Thinking of you, {first name}. Hope all is well.' ),
		);

		$created = 0;

		foreach ( $examples as $i => $example ) {
			list( $title, $tone, $type, $text ) = $example;

			$post_id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => $title,
					'post_status' => 'publish',
					'menu_order'  => ( $i + 1 ) * 10,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, self::TEXT, $text );
			update_post_meta( $post_id, '_cardsco_tone', $tone );
			update_post_meta( $post_id, self::COMPANY, 0 );

			if ( $type ) {
				CardsCo_Card::set_occasions( $post_id, array( (int) $type['id'] ) );
			}

			$created++;
		}

		return $created;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Occasion type IDs a message suits.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function occasions( $post_id ) {
		return array_map( 'intval', (array) get_post_meta( $post_id, self::OCCASION, false ) );
	}

	/**
	 * The wording.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function text( $post_id ) {
		return (string) get_post_meta( $post_id, self::TEXT, true );
	}

	/**
	 * Messages available for an occasion.
	 *
	 * @param int $type_id Occasion type ID, or 0 for all.
	 * @param int $company_id Company ID.
	 * @return WP_Post[]
	 */
	public static function for_occasion( $type_id = 0, $company_id = 0 ) {
		$args = array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 200,
			'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'suppress_filters' => false,
			'no_found_rows'    => true,
			'meta_query'       => array( CardsCo_Card::company_clause( $company_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);

		$type_id = absint( $type_id );

		if ( $type_id ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'   => self::OCCASION,
					'value' => $type_id,
				),
				array(
					'key'     => self::OCCASION,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		return get_posts( $args );
	}

	/* ---------------------------------------------------------------------
	 * List table
	 * ------------------------------------------------------------------ */

	/**
	 * Columns on the messages list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['cardsco_text']      = __( 'Wording', 'cardsco' );
				$out['cardsco_occasions'] = __( 'Occasions', 'cardsco' );
				$out['cardsco_tone']      = __( 'Tone', 'cardsco' );
				$out['cardsco_length']    = __( 'Length', 'cardsco' );
			}
		}

		return $out;
	}

	/**
	 * Cell contents.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function column( $column, $post_id ) {
		$text = self::text( $post_id );

		switch ( $column ) {
			case 'cardsco_text':
				echo '<span style="color:#50575e">' . esc_html( wp_trim_words( str_replace( "\n", ' ', $text ), 14, '…' ) ) . '</span>';
				break;

			case 'cardsco_occasions':
				$labels = array();

				foreach ( self::occasions( $post_id ) as $type_id ) {
					$type = CardsCo_Occasion_Type::get( $type_id );

					if ( $type ) {
						$labels[] = $type['label'];
					}
				}

				echo $labels
					? esc_html( implode( ', ', $labels ) )
					: '<em>' . esc_html__( 'Any occasion', 'cardsco' ) . '</em>';
				break;

			case 'cardsco_tone':
				$tone  = get_post_meta( $post_id, '_cardsco_tone', true );
				$tones = self::tones();
				echo esc_html( $tones[ $tone ] ?? '—' );
				break;

			case 'cardsco_length':
				$limit    = (int) CardsCo_Settings::get( 'message_max_chars' );
				$rendered = CardsCo_Merge::render( $text, CardsCo_Merge::sample_context() );
				$over     = $rendered['length'] > $limit;

				printf(
					'<span style="color:%s">%d</span>',
					$over ? '#d63638' : '#50575e',
					(int) $rendered['length']
				);

				if ( ! empty( $rendered['unknown'] ) ) {
					echo '<div style="color:#d63638;font-size:12px">' . esc_html__( 'Unknown field', 'cardsco' ) . '</div>';
				}
				break;
		}
	}
}
