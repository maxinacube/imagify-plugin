<?php
defined( 'ABSPATH' ) || die( 'Cheatin\' uh?' );

/**
 * Class that handles the plugin settings.
 *
 * @since 1.7
 */
class Imagify_Settings {

	/**
	 * Class version.
	 *
	 * @var   string
	 * @since 1.7
	 */
	const VERSION = '1.0';

	/**
	 * The settings group.
	 *
	 * @var   string
	 * @since 1.7
	 */
	protected $settings_group;

	/**
	 * The option name.
	 *
	 * @var   string
	 * @since 1.7
	 */
	protected $option_name;

	/**
	 * The options instance.
	 *
	 * @var   object
	 * @since 1.7
	 */
	protected $options;

	/**
	 * The single instance of the class.
	 *
	 * @var    object
	 * @since  1.7
	 * @access protected
	 */
	protected static $_instance;

	/**
	 * The constructor.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access protected
	 */
	protected function __construct() {
		$this->options        = Imagify_Options::get_instance();
		$this->option_name    = $this->options->get_option_name();
		$this->settings_group = IMAGIFY_SLUG;
	}

	/**
	 * Get the main Instance.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @return object Main instance.
	 */
	public static function get_instance() {
		if ( ! isset( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Launch the hooks.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 */
	public function init() {
		add_filter( 'sanitize_option_' . $this->option_name,           array( $this, 'populate_values_on_save' ), 5 );
		add_action( 'admin_init',                                      array( $this, 'register' ) );
		add_filter( 'option_page_capability_' . $this->settings_group, array( $this, 'get_capability' ) );

		if ( imagify_is_active_for_network() ) {
			add_filter( 'pre_update_site_option_' . $this->option_name, array( $this, 'maybe_set_redirection' ), 10, 2 );
			add_action( 'update_site_option_' . $this->option_name,     array( $this, 'after_save_network_options' ), 10, 3 );
			add_action( 'admin_post_update',                            array( $this, 'update_site_option_on_network' ) );
		} else {
			add_filter( 'pre_update_option_' . $this->option_name,      array( $this, 'maybe_set_redirection' ), 10, 2 );
			add_action( 'update_option_' . $this->option_name,          array( $this, 'after_save_options' ), 10, 2 );
		}
	}


	/** ----------------------------------------------------------------------------------------- */
	/** VARIOUS HELPERS ========================================================================= */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Get the name of the settings group.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @return string
	 */
	public function get_settings_group() {
		return $this->settings_group;
	}

	/**
	 * Get the URL to use as form action.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @return string
	 */
	public function get_form_action() {
		return imagify_is_active_for_network() ? admin_url( 'admin-post.php' ) : admin_url( 'options.php' );
	}

	/**
	 * Tell if we're submitting the settings form.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @return bool
	 */
	public function is_form_submit() {
		return filter_input( INPUT_POST, 'option_page', FILTER_SANITIZE_STRING ) === $this->settings_group && filter_input( INPUT_POST, 'action', FILTER_SANITIZE_STRING ) === 'update';
	}


	/** ----------------------------------------------------------------------------------------- */
	/** ON FORM SUBMIT ========================================================================== */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * On form submit, handle values that are not part of the form.
	 * This must be hooked before Imagify_Options::sanitize_and_validate_on_update().
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @param  string $values The option value.
	 * @return array
	 */
	public function populate_values_on_save( $values ) {
		global $wpdb;

		$values = is_array( $values ) ? $values : array();

		// Version.
		if ( empty( $values['version'] ) ) {
			$values['version'] = IMAGIFY_VERSION;
		}

		if ( ! $this->is_form_submit() ) {
			return $values;
		}

		// Disabled thumbnail sizes.
		$values['disallowed-sizes'] = array();

		if ( isset( $values['disallowed-sizes-reversed'] ) && is_array( $values['disallowed-sizes-reversed'] ) ) {
			$checked = ! empty( $values['disallowed-sizes-checked'] ) && is_array( $values['disallowed-sizes-checked'] ) ? array_flip( $values['disallowed-sizes-checked'] ) : array();

			if ( ! empty( $values['disallowed-sizes-reversed'] ) ) {
				foreach ( $values['disallowed-sizes-reversed'] as $size_key ) {
					if ( ! isset( $checked[ $size_key ] ) ) {
						// The checkbox is not checked: the size is disabled.
						$values['disallowed-sizes'][ $size_key ] = 1;
					}
				}
			}
		}

		unset( $values['disallowed-sizes-reversed'], $values['disallowed-sizes-checked'] );

		// Custom folders.
		if ( isset( $values['custom_folders'] ) && is_array( $values['custom_folders'] ) ) {
			$selected_raw   = $values['custom_folders'];
			$selected_paths = Imagify_DB::prepare_values_list( $selected_raw );
			$selected_raw   = array_flip( $selected_raw );
			unset( $values['custom_folders'] );

			// Selected folders that already are in the DB.
			$results = $wpdb->get_results( "SELECT * FROM $wpdb->imagify_folders WHERE path IN ( $selected_paths );", ARRAY_A ); // WPCS: unprepared SQL ok.

			if ( $results ) {
				// Set active.
				foreach ( $results as $i => $result ) {
					if ( empty( $result['active'] ) && Imagify_Files_Scan::placeholder_path_exists( $result['path'] ) ) {
						// Add the optimization level only if not already set and if the file exists.
						$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->imagify_folders SET active = 1 WHERE folder_id = %d", $result['folder_id'] ) );
					}

					// Remove the path from the selected list, so the remaining will be created.
					unset( $selected_raw[ $result['path'] ] );
				}
			}

			// Not selected folders that are in the DB, and that are active.
			$results = $wpdb->get_col( "SELECT folder_id FROM $wpdb->imagify_folders WHERE path NOT IN ( $selected_paths ) AND active = 1" ); // WPCS: unprepared SQL ok.

			if ( $results ) {
				// Remove the active status.
				$results = Imagify_DB::prepare_values_list( $results );
				$wpdb->query( "UPDATE $wpdb->imagify_folders SET active = 0 WHERE folder_id IN ( $results )" ); // WPCS: unprepared SQL ok.
			}

			if ( $selected_raw ) {
				// If we still have paths here, they need to be added to the DB.
				$filesystem = imagify_get_filesystem();

				foreach ( $selected_raw as $path => $meh ) {
					$path = sanitize_text_field( $path );
					$path = Imagify_Files_Scan::remove_placeholder( $path );
					$path = realpath( $path );

					if ( ! $path || ! $filesystem->is_dir( $path ) ) {
						continue;
					}

					if ( Imagify_Files_Scan::is_path_forbidden( $path ) ) {
						continue;
					}

					Imagify_Folders_DB::get_instance()->insert( array(
						'path'   => Imagify_Files_Scan::add_placeholder( trailingslashit( $path ) ),
						'active' => 1,
					) );
				}
			}
		} else {
			unset( $values['custom_folders'] );
			$wpdb->query( "UPDATE $wpdb->imagify_folders SET active = 0 WHERE active = 1" );
		}

		return $values;
	}


	/** ----------------------------------------------------------------------------------------- */
	/** SETTINGS API ============================================================================ */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Add Imagify' settings to the settings API whitelist.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 */
	public function register() {
		register_setting( $this->settings_group, $this->option_name );
	}

	/**
	 * Set the user capacity needed to save Imagify's main options from the settings page.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 */
	public function get_capability() {
		return imagify_get_capacity();
	}

	/**
	 * If the user clicked the "Save & Go to Bulk Optimizer" button, set a redirection to the bulk optimizer.
	 * We use this hook because it can be triggered even if the option value hasn't changed.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @param  mixed $value     The new, unserialized option value.
	 * @param  mixed $old_value The old option value.
	 * @return mixed            The option value.
	 */
	public function maybe_set_redirection( $value, $old_value ) {
		if ( isset( $_POST['submit-goto-bulk'] ) ) { // WPCS: CSRF ok.
			$_REQUEST['_wp_http_referer'] = esc_url_raw( get_admin_url( get_current_blog_id(), 'upload.php?page=imagify-bulk-optimization' ) );
		}

		return $value;
	}

	/**
	 * Used to launch some actions after saving the network options.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @param string $option     Name of the network option.
	 * @param mixed  $value      Current value of the network option.
	 * @param mixed  $old_value  Old value of the network option.
	 */
	public function after_save_network_options( $option, $value, $old_value ) {
		$this->after_save_options( $old_value, $value );
	}

	/**
	 * Used to launch some actions after saving the options.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $value     The new option value.
	 */
	public function after_save_options( $old_value, $value ) {
		if ( ! $value || isset( $old_value['api_key'], $value['api_key'] ) && $old_value['api_key'] === $value['api_key'] ) {
			return;
		}

		if ( is_wp_error( get_imagify_user() ) ) {
			Imagify_Notices::renew_notice( 'wrong-api-key' );
			delete_site_transient( 'imagify_check_licence_1' );
		} else {
			Imagify_Notices::dismiss_notice( 'wrong-api-key' );
		}
	}

	/**
	 * `options.php` does not handle network options. Let's use `admin-post.php` for multisite installations.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 */
	public function update_site_option_on_network() {
		if ( empty( $_POST['option_page'] ) || $_POST['option_page'] !== $this->settings_group ) { // WPCS: CSRF ok.
			return;
		}

		$capability = apply_filters( 'option_page_capability_' . $this->settings_group, 'manage_network_options' );

		if ( ! current_user_can( $capability ) ) {
			imagify_die();
		}

		imagify_check_nonce( $this->settings_group . '-options' );

		$whitelist_options = apply_filters( 'whitelist_options', array() );

		if ( ! isset( $whitelist_options[ $this->settings_group ] ) ) {
			imagify_die( __( '<strong>ERROR</strong>: options page not found.' ) );
		}

		$options = $whitelist_options[ $this->settings_group ];

		if ( $options ) {
			foreach ( $options as $option ) {
				$option = trim( $option );
				$value  = null;

				if ( isset( $_POST[ $option ] ) ) {
					$value = $_POST[ $option ];
					if ( ! is_array( $value ) ) {
						$value = trim( $value );
					}
					$value = wp_unslash( $value );
				}

				update_site_option( $option, $value );
			}
		}

		/**
		 * Redirect back to the settings page that was submitted.
		 */
		imagify_maybe_redirect( false, array( 'settings-updated' => 'true' ) );
	}


	/** ----------------------------------------------------------------------------------------- */
	/** FIELDS ================================================================================== */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Display a single checkbox.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @param array $args Arguments:
	 *                    {option_name}   string   The option name. E.g. 'disallowed-sizes'. Mandatory.
	 *                    {label}         string   The label to use.
	 *                    {info}          string   Text to display in an "Info box" after the field. A 'aria-describedby' attribute will automatically be created.
	 *                    {attributes}    array    A list of HTML attributes, as 'attribute' => 'value'.
	 *                    {current_value} int|bool USE ONLY WHEN DEALING WITH DATA THAT IS NOT SAVED IN THE PLUGIN OPTIONS. If not provided, the field will automatically get the value from the options.
	 */
	public function field_checkbox( $args ) {
		$args = array_merge( array(
			'option_name'   => '',
			'label'         => '',
			'info'          => '',
			'attributes'    => array(),
			// To not use the plugin settings: use an integer.
			'current_value' => null,
		), $args );

		if ( ! $args['option_name'] || ! $args['label'] ) {
			return;
		}

		if ( is_numeric( $args['current_value'] ) || is_bool( $args['current_value'] ) ) {
			// We don't use the plugin settings.
			$current_value = (int) (bool) $args['current_values'];
		} else {
			// This is a normal plugin setting.
			$current_value = $this->options->get( $args['option_name'] );
		}

		$option_name_class = sanitize_html_class( $args['option_name'] );
		$attributes        = array(
			'name' => $this->option_name . '[' . $args['option_name'] . ']',
			'id'   => 'imagify_' . $option_name_class,
		);

		if ( $args['info'] && empty( $attributes['aria-describedby'] ) ) {
			$attributes['aria-describedby'] = 'describe-' . $option_name_class;
		}

		$attributes         = array_merge( $attributes, $args['attributes'] );
		$args['attributes'] = self::build_attributes( $attributes );
		?>
		<input type="checkbox" value="1" <?php checked( $current_value, 1 ); ?><?php echo $args['attributes']; ?> />
		<!-- Empty onclick attribute to make clickable labels on iTruc & Mac -->
		<label for="<?php echo $attributes['id']; ?>" onclick=""><?php echo $args['label']; ?></label>
		<?php
		if ( ! $args['info'] ) {
			return;
		}
		?>
		<span id="<?php echo $attributes['aria-describedby']; ?>" class="imagify-info">
			<span class="dashicons dashicons-info"></span>
			<?php echo $args['info']; ?>
		</span>
		<?php
	}

	/**
	 * Display a checkbox group.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @param array $args Arguments:
	 *                    {option_name}     string The option name. E.g. 'disallowed-sizes'. Mandatory.
	 *                    {legend}          string Label to use for the <legend> tag.
	 *                    {values}          array  List of values to display, in the form of 'value' => 'Label'. Mandatory.
	 *                    {disabled_values} array  Values to be disabled. Values are the array keys.
	 *                    {reverse_check}   bool   If true, the values that will be stored in the option are the ones that are unchecked. It requires special treatment when saving (detect what values are unchecked).
	 *                    {attributes}      array  A list of HTML attributes, as 'attribute' => 'value'.
	 *                    {current_values}  array  USE ONLY WHEN DEALING WITH DATA THAT IS NOT SAVED IN THE PLUGIN OPTIONS. If not provided, the field will automatically get the value from the options.
	 */
	public function field_checkbox_list( $args ) {

		$args = array_merge( array(
			'option_name'     => '',
			'legend'          => '',
			'values'          => array(),
			'disabled_values' => array(),
			'reverse_check'   => false,
			'attributes'      => array(),
			// To not use the plugin settings: use an array.
			'current_values'  => false,
		), $args );

		if ( ! $args['option_name'] || ! $args['values'] ) {
			return;
		}

		if ( is_array( $args['current_values'] ) ) {
			// We don't use the plugin settings.
			$current_values = $args['current_values'];
		} else {
			// This is a normal plugin setting.
			$current_values = $this->options->get( $args['option_name'] );
		}

		$option_name_class = sanitize_html_class( $args['option_name'] );
		$attributes        = array_merge( array(
			'name'  => $this->option_name . '[' . $args['option_name'] . ( $args['reverse_check'] ? '-checked' : '' ) . '][]',
			'id'    => 'imagify_' . $option_name_class . '_%s',
			'class' => 'imagify-row-check',
		), $args['attributes'] );

		$id_attribute = $attributes['id'];
		unset( $attributes['id'] );
		$args['attributes'] = self::build_attributes( $attributes );

		$current_values    = array_diff_key( $current_values, $args['disabled_values'] );
		$nb_of_values      = count( $args['values'] );
		$display_check_all = $nb_of_values > 3;
		$nb_of_checked     = 0;
		?>
		<fieldset class="imagify-check-group<?php echo $nb_of_values > 5 ? ' imagify-is-scrollable' : ''; ?>">
			<?php
			if ( $args['legend'] ) {
				?>
				<legend class="screen-reader-text"><?php echo $args['legend']; ?></legend>
				<?php
			}

			foreach ( $args['values'] as $value => $label ) {
				$input_id = sprintf( $id_attribute, sanitize_html_class( $value ) );
				$disabled = isset( $args['disabled_values'][ $value ] );

				if ( $args['reverse_check'] ) {
					$checked = ! $disabled && ! isset( $current_values[ $value ] );
				} else {
					$checked = ! $disabled && isset( $current_values[ $value ] );
				}

				$nb_of_checked = $checked ? $nb_of_checked + 1 : $nb_of_checked;

				if ( $args['reverse_check'] ) {
					echo '<input type="hidden" name="' . $this->option_name . '[' . $args['option_name'] . '-reversed][]" value="' . esc_attr( $value ) . '" />';
				}
				?>
				<p>
					<input type="checkbox" value="<?php echo esc_attr( $value ); ?>" id="<?php echo $input_id; ?>"<?php echo $args['attributes']; ?> <?php checked( $checked ); ?> <?php disabled( $disabled ); ?>/>
					<label for="<?php echo $input_id; ?>" onclick=""><?php echo $label; ?></label>
				</p>
				<?php
			}
			?>
		</fieldset>
		<?php
		if ( $display_check_all ) {
			if ( $args['reverse_check'] ) {
				$all_checked = ! array_intersect_key( $args['values'], $current_values );
			} else {
				$all_checked = ! array_diff_key( $args['values'], $current_values );
			}
			?>
			<p class="hide-if-no-js imagify-select-all-buttons">
				<button type="button" class="imagify-link-like imagify-select-all<?php echo $all_checked ? ' imagify-is-inactive" aria-disabled="true' : ''; ?>" data-action="select"><?php _e( 'Select All', 'imagify' ); ?></button>

				<span class="imagify-pipe"></span>

				<button type="button" class="imagify-link-like imagify-select-all<?php echo $nb_of_checked ? '' : ' imagify-is-inactive" aria-disabled="true'; ?>" data-action="unselect"><?php _e( 'Unselect All', 'imagify' ); ?></button>
			</p>
			<?php
		}
	}


	/** ----------------------------------------------------------------------------------------- */
	/** FIELD VALUES ============================================================================ */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Get the thumbnail sizes.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @return array A list of thumbnail sizes in the form of 'medium' => 'medium - 300 × 300'.
	 */
	public static function get_thumbnail_sizes() {
		static $sizes;

		if ( isset( $sizes ) ) {
			return $sizes;
		}

		$sizes = get_imagify_thumbnail_sizes();

		foreach ( $sizes as $size_key => $size_data ) {
			$sizes[ $size_key ] = sprintf( '%s - %d &times; %d',  esc_html( stripslashes( $size_data['name'] ) ), $size_data['width'], $size_data['height'] );
		}

		return $sizes;
	}

	/**
	 * Get installed theme names.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @return array A list of installed themes in the form of '{{THEMES}}/twentyseventeen/' => 'Twenty Seventeen'.
	 */
	public static function get_themes() {
		static $themes;

		if ( isset( $themes ) ) {
			return $themes;
		}

		$all_themes = wp_get_themes();
		$themes     = array();

		if ( $all_themes ) {
			foreach ( $all_themes as $stylesheet => $theme ) {
				if ( ! $theme->exists() ) {
					continue;
				}

				$path = trailingslashit( $theme->get_stylesheet_directory() );

				if ( imagify_file_is_symlinked( $path ) ) {
					continue;
				}

				$path = Imagify_Files_Scan::add_placeholder( $path );

				$themes[ $path ] = $theme->display( 'Name', false );
			}
		}

		return $themes;
	}

	/**
	 * Get installed plugin names.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @return array A list of installed plugins in the form of '{{PLUGINS}}/imagify/' => 'Imagify'.
	 */
	public static function get_plugins() {
		static $plugins, $plugins_path;

		if ( isset( $plugins ) ) {
			return $plugins;
		}

		if ( ! isset( $plugins_path ) ) {
			$plugins_path = Imagify_Files_Scan::remove_placeholder( '{{PLUGINS}}/' );
		}

		$all_plugins = get_plugins();
		$plugins     = array();

		if ( $all_plugins ) {
			$filesystem = imagify_get_filesystem();

			foreach ( $all_plugins as $plugin_file => $plugin_data ) {
				$plugin_path = $plugins_path . $plugin_file;
				$plugin_base = trailingslashit( dirname( $plugin_path ) );

				if ( $plugins_path === $plugin_base || ! $filesystem->exists( $plugin_path ) || imagify_file_is_symlinked( $plugin_path ) ) {
					continue;
				}

				// The folder name is enough.
				$plugin_data = _get_plugin_data_markup_translate( $plugin_file, $plugin_data, false );
				$plugins[ '{{PLUGINS}}/' . dirname( $plugin_file ) . '/' ] = $plugin_data['Name'];
			}
		}

		return $plugins;
	}

	/**
	 * Create HTML attributes from an array.
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 * @access public
	 *
	 * @param  array $attributes A list of attribute pairs.
	 * @return string            HTML attributes.
	 */
	public static function build_attributes( $attributes ) {
		if ( ! $attributes || ! is_array( $attributes ) ) {
			return '';
		}

		$out = '';

		foreach ( $attributes as $attribute => $value ) {
			$out .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
		}

		return $out;
	}
}
