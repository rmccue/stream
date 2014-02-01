<?php

class WP_Stream_Notifications_Form
{

	function __construct() {
		// AJAX end point for form auto completion
		add_action( 'wp_ajax_stream_notification_endpoint', array( $this, 'form_ajax_ep' ) );

		// Enqueue our form scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 11 );
	}

	public function load() {

		// Control screen layout
		add_screen_option( 'layout_columns', array( 'max' => 2, 'default' => 2 ) );

		// Register metaboxes
		add_meta_box(
			'triggers',
			__( 'Triggers', 'stream-notifications' ),
			array( $this, 'metabox_triggers' ),
			WP_Stream_Notifications::$screen_id,
			'normal'
		);
		add_meta_box(
			'alerts',
			__( 'Alerts', 'stream-notifications' ),
			array( $this, 'metabox_alerts' ),
			WP_Stream_Notifications::$screen_id,
			'normal'
		);
		add_meta_box(
			'submitdiv',
			__( 'Save', 'stream-notifications' ),
			array( $this, 'metabox_save' ),
			WP_Stream_Notifications::$screen_id,
			'side'
		);
	}

	/**
	 * Enqueue our scripts, in our own page only
	 *
	 * @action admin_enqueue_scripts
	 * @param  string $hook Current admin page slug
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if (
			$hook != WP_Stream_Notifications::$screen_id
			||
			filter_input( INPUT_GET, 'view' ) != 'rule'
			) {
			return;
		}

		$view = filter_input( INPUT_GET, 'view', FILTER_DEFAULT, array( 'options' => array( 'default' => 'list' ) ) );

		if ( $view == 'rule' ) {
			wp_enqueue_script( 'dashboard' );
			wp_enqueue_style( 'select2' );
			wp_enqueue_script( 'select2' );
			wp_enqueue_script( 'underscore' );
			wp_enqueue_script( 'stream-notifications-main', WP_STREAM_NOTIFICATIONS_URL . '/ui/js/main.js', array( 'underscore', 'select2' ) );
			wp_localize_script( 'stream-notifications-main', 'stream_notifications', $this->get_js_options() );
		}
	}


	/**
	 * Callback for form AJAX operations
	 *
	 * @action wp_ajax_stream_notifications_endpoint
	 * @return void
	 */
	public function form_ajax_ep() {
		// BIG @TODO: Make the request context-aware,
		// ie: get other rules ( maybe in the same group only ? ), so an author
		// query would check if there is a author_role rule available to limit
		// the results according to it

		$type      = filter_input( INPUT_POST, 'type' );
		$is_single = filter_input( INPUT_POST, 'single' );
		$query     = filter_input( INPUT_POST, 'q' );

		if ( $is_single ) {
			switch ( $type ) {
				case 'author':
					$user_ids = explode( ',', $query );
					$user_query = new WP_User_Query(
						array(
							'include' => $user_ids,
							'fields'  => array( 'ID', 'user_email', 'display_name' ),
						)
					);
					if ( $user_query->results ) {
						$data = $this->format_json_for_select2(
							$user_query->results,
							'ID',
							'display_name'
						);
					} else {
						$data = array();
					}
					break;
				case 'action':
					$actions = WP_Stream_Connectors::$term_labels['stream_action'];
					$values  = explode( ',', $query );
					$actions = array_intersect_key( $actions, array_flip( $values ) );
					$data    = $this->format_json_for_select2( $actions );
					break;
			}
		} else {
			switch ( $type ) {
				case 'author':
					$users = get_users( array( 'search' => '*' . $query . '*' ) );
					$data  = $this->format_json_for_select2( $users, 'ID', 'display_name' );
					break;
				case 'action':
					$actions = WP_Stream_Connectors::$term_labels['stream_action'];
					$actions = preg_grep( sprintf( '/%s/i', $query ), $actions );
					$data    = $this->format_json_for_select2( $actions );
					break;
			}
		}
		if ( isset( $data ) ) {
			wp_send_json_success( $data );
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * Take an (associative) array and format it for select2 AJAX result parser
	 * @param  array  $data (associative) Data array
	 * @param  string $key  Key of the ID column, null if associative array
	 * @param  string $val  Key of the Title column, null if associative array
	 * @return array        Formatted array, [ { id: %, title: % }, .. ]
	 */
	public function format_json_for_select2( $data, $key = null, $val = null ) {
		$return = array();
		if ( is_null( $key ) && is_null( $val ) ) { // for flat associative array
			$keys = array_keys( $data );
			$vals = array_values( $data );
		} else {
			$keys = wp_list_pluck( $data, $key );
			$vals = wp_list_pluck( $data, $val );
		}
		foreach ( $keys as $idx => $key ) {
			$return[] = array(
				'id'   => $key,
				'text' => $vals[$idx],
			);
		}
		return $return;
	}

	/**
	 * Format JS options for the form, to be used with wp_localize_script
	 *
	 * @return array  Options for our form JS handling
	 */
	public function get_js_options() {
		global $wp_roles;
		$args = array();

		$roles     = $wp_roles->roles;
		$roles_arr = array_combine( array_keys( $roles ), wp_list_pluck( $roles, 'name' ) );

		$default_operators = array(
			'='   => __( 'is', 'stream-notifications' ),
			'!='  => __( 'is not', 'stream-notifications' ),
			'in'  => __( 'in', 'stream-notifications' ),
			'!in' => __( 'not in', 'stream-notifications' ),
		);

		$args['types'] = array(
			'search' => array(
				'title'     => __( 'Summary', 'stream-notifications' ),
				'type'      => 'text',
				'operators' => array(
					'='         => __( 'is', 'stream-notifications' ),
					'!='        => __( 'is not', 'stream-notifications' ),
					'contains'  => __( 'contains', 'stream-notifications' ),
					'!contains' => __( 'does not contain', 'stream-notifications' ),
					'regex'     => __( 'regex', 'stream-notifications' ),
				),
			),
			'object_id' => array(
				'title'     => __( 'Object ID', 'stream-notifications' ),
				'type'      => 'text',
				'tags'      => true,
				'operators' => $default_operators,
			),

			'author_role' => array(
				'title'     => __( 'Author Role', 'stream-notifications' ),
				'type'      => 'select',
				'multiple'  => true,
				'operators' => $default_operators,
				'options' => $roles_arr,
			),

			'author' => array(
				'title'     => __( 'Author', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'operators' => $default_operators,
			),

			'ip' => array(
				'title'     => __( 'IP', 'stream-notifications' ),
				'type'      => 'text',
				'tags'      => true,
				'operators' => $default_operators,
			),

			'date' => array(
				'title'     => __( 'Date', 'stream-notifications' ),
				'type'      => 'date',
				'operators' => array(
					'='  => __( 'is on', 'stream-notifications' ),
					'!=' => __( 'is not on', 'stream-notifications' ),
					'<'  => __( 'is before', 'stream-notifications' ),
					'<=' => __( 'is on or before', 'stream-notifications' ),
					'>'  => __( 'is after', 'stream-notifications' ),
					'>=' => __( 'is on or after', 'stream-notifications' ),
				),
			),

			// TODO: find a way to introduce meta to the rules, problem: not translatable since it is
			// generated on run time with no prior definition
			// 'meta_query'            => array(),

			'connector' => array(
				'title'     => __( 'Connector', 'stream-notifications' ),
				'type'      => 'select',
				'operators' => $default_operators,
				'options' => WP_Stream_Connectors::$term_labels['stream_connector'],
			),
			'context' => array(
				'title'     => __( 'Context', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'operators' => $default_operators,
			),
			'action' => array(
				'title'     => __( 'Action', 'stream-notifications' ),
				'type'      => 'text',
				'ajax'      => true,
				'operators' => $default_operators,
			),
		);

		$args['adapters'] = array();

		foreach ( WP_Stream_Notifications::$adapters as $name => $options ) {
			$args['adapters'][$name] = array(
				'title'  => $options['title'],
				'fields' => $options['class']::fields(),
			);
		}

		// Localization
		$args['i18n'] = array(
			'empty_triggers' => __( 'A rule must contain at least one trigger to be saved.', 'stream-notifications' ),
		);

		return apply_filters( 'stream_notification_js_args', $args );
	}

	public function metabox_triggers() {
		?>
		<a class="add-trigger button button-secondary" href="#add-trigger" data-group="0"><?php esc_html_e( '+ Add Trigger', 'stream-notifications' ) ?></a>
		<a class="add-trigger-group button button-primary" href="#add-trigger-group" data-group="0"><?php esc_html_e( '+ Add Group', 'stream-notifications' ) ?></a>
		<div class="group" rel="0"></div>
		<?php
	}

	public function metabox_alerts() {
		?>
		<a class="add-alert button button-secondary" href="#add-alert"><?php esc_html_e( '+ Add Alert', 'stream-notifications' ) ?></a>
		<?php
	}

	public function metabox_save( $rule ) {
		?>
		<div class="submitbox" id="submitpost">
			<div id="minor-publishing">
				<div id="misc-publishing-actions">
					<div class="misc-pub-section misc-pub-post-status">
						<label for="notification_visibility">
							<input type="checkbox" name="visibility" id="notification_visibility" value="active" <?php $rule->exists() && checked( $rule->visibility, 'active' ) ?>>
							<?php esc_html_e( 'Active', 'stream-notifications' ) ?>
						</label>
					</div>
				</div>
			</div>

			<div id="major-publishing-actions">
				<?php if ( $rule->exists() ) : ?>
					<div id="delete-action">
						<?php
						$delete_link = add_query_arg(
							array(
								'page'            => WP_Stream_Notifications::NOTIFICATIONS_PAGE_SLUG,
								'action'          => 'delete',
								'id'              => absint( $rule->ID ),
								'wp_stream_nonce' => wp_create_nonce( 'delete-record_' . absint( $rule->ID ) ),
							),
							admin_url( WP_Stream_Admin::ADMIN_PARENT_PAGE )
						);
						?>
						<a class="submitdelete deletion" href="<?php echo esc_url( $delete_link ) ?>">
							<?php esc_html_e( 'Delete permanently', 'stream-notifications' ) ?>
						</a>
					</div>
				<?php endif; ?>

				<div id="publishing-action">
					<span class="spinner"></span>
					<input type="submit" name="publish" id="publish" class="button button-primary button-large" value="<?php $rule->exists() ? esc_attr_e( 'Update', 'stream-notifications' ) : esc_attr_e( 'Save', 'stream-notifications' ) ?>" accesskey="p">
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<?php
	}

}