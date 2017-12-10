<?php
defined( 'ABSPATH' ) || die( 'Cheatin\' uh?' );

/**
 * Get a template contents.
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @param string $template The template name.
 * @param mixed  $data     Some data to pass to the template.
 */
function imagify_get_template( $template, $data = array() ) {
	$path = IMAGIFY_INC_PATH . 'views/' . $template . '.php';

	if ( ! imagify_get_filesystem()->exists( $path ) ) {
		return false;
	}

	ob_start();
	include $path;
	$contents = ob_get_clean();

	return trim( (string) $contents );
}

/**
 * Print a template.
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @param string $template The template name.
 * @param mixed  $data     Some data to pass to the template.
 */
function imagify_print_template( $template, $data = array() ) {
	echo imagify_get_template( $template, $data );
}

/**
 * The main settings page construtor using the required functions from WP
 *
 * @since 1.0
 */
function _imagify_display_options_page() {
	imagify_print_template( 'page-settings' );
}

/**
 * The main settings page construtor using the required functions from WP.
 *
 * @since 1.0
 */
function _imagify_display_bulk_page() {
	imagify_print_template( 'page-bulk' );
}

/**
 * Get the optimization data list for a specific attachment.
 *
 * @since  1.0
 * @author Jonathan Buttigieg
 *
 * @param  object $attachment The attachment object.
 * @param  string $context    A context.
 * @return string             The output to print.
 */
function get_imagify_attachment_optimization_text( $attachment, $context = 'wp' ) {
	global $pagenow, $typenow;

	$is_media_page            = 'post.php' === $pagenow && 'attachment' === $typenow;
	$is_library_page          = 'upload.php' === $pagenow;
	$output                   = $is_media_page ? '' : '<ul class="imagify-datas-list">';
	$output_before            = $is_media_page ? '<div class="misc-pub-section misc-pub-imagify imagify-data-item">' : '<li class="imagify-data-item">';
	$output_after             = $is_media_page ? '</div>' : '</li>';
	$reoptimize_link          = get_imagify_attachment_reoptimize_link( $attachment, $context );
	$reoptimize_link         .= get_imagify_attachment_optimize_missing_thumbnails_link( $attachment, $context );
	$reoptimize_output        = $reoptimize_link ? $reoptimize_link : '';
	$reoptimize_output_before = '<div class="imagify-datas-actions-links">';
	$reoptimize_output_after  = '</div><!-- .imagify-datas-actions-links -->';
	$error                    = get_imagify_attachment_error_text( $attachment, $context );

	if ( $error ) {
		if ( ! $is_media_page && $reoptimize_link && $attachment->has_backup() ) {
			$reoptimize_output .= '<span class="attachment-has-backup hidden"></span>';
		}

		$reoptimize_output = $reoptimize_output_before . $reoptimize_output . $reoptimize_output_after;

		return $is_media_page ? $output_before . $error . $reoptimize_output . $output_after : $error . $reoptimize_output;
	}

	$attachment_id      = $attachment->id;
	$data               = $attachment->get_data();
	$optimization_level = $attachment->get_optimization_level_label();

	if ( ! $is_media_page ) {
		$output .= $output_before . '<span class="data">' . __( 'New Filesize:', 'imagify' ) . '</span> <strong class="big">' . imagify_size_format( $data['sizes']['full']['optimized_size'], 2 ) . '</strong>' . $output_after;
	}

	$chart = '';

	if ( ! $is_media_page ) {
		if ( ! $is_library_page ) {
			// No need to print this on the library page, the event whould be triggered before the handler is attached (the JS file is loaded in the footer).
			$chart = '<script type="text/javascript">jQuery( window ).trigger( "canvasprinted.imagify", [ ".imagify-consumption-chart-' . $attachment_id . '" ] ); </script>';
		}

		$chart = '<span class="imagify-chart">
					<span class="imagify-chart-container">
						<canvas class="imagify-consumption-chart imagify-consumption-chart-' . $attachment_id . '" width="15" height="15"></canvas>
						' . $chart . '
					</span>
				</span>';
	}

	$output .= $output_before;
	$output .= '<span class="data">' . __( 'Original Saving:', 'imagify' ) . '</span> ';
	$output .= '<strong>' . $chart . '<span class="imagify-chart-value">' . $data['sizes']['full']['percent'] . '</span>%</strong>';
	$output .= $output_after;

	// More details section.
	if ( ! $is_media_page ) {
		// New list.
		$output .= '</ul>';
		$output .= '<p class="imagify-datas-more-action">';
			$output .= '<a href="#imagify-view-details-' . $attachment_id . '" data-close="' . __( 'Close details', 'imagify' ) . '" data-open="' . __( 'View details', 'imagify' ) . '">';
				$output .= '<span class="the-text">' . __( 'View details', 'imagify' ) . '</span>';
				$output .= '<span class="dashicons dashicons-arrow-down-alt2"></span>';
			$output .= '</a>';
		$output .= '</p>';
		$output .= '<ul id="imagify-view-details-' . $attachment_id . '" class="imagify-datas-list imagify-datas-details">';

		// Not in metabox.
		$output .= $output_before . '<span class="data">' . __( 'Original Filesize:', 'imagify' ) . '</span> <strong class="original">' . $attachment->get_original_size() . '</strong>' . $output_after;
	}

	$output .= $output_before . '<span class="data">' . __( 'Level:', 'imagify' ) . '</span> <strong>' . $optimization_level . '</strong>' . $output_after;

	$total_optimized_thumbnails = $attachment->get_optimized_sizes_count();

	if ( $total_optimized_thumbnails ) {
		$output .= $output_before . '<span class="data">' . __( 'Thumbnails Optimized:', 'imagify' ) . '</span> <strong>' . $total_optimized_thumbnails . '</strong>' . $output_after;
		$output .= $output_before . '<span class="data">' . __( 'Overall Saving:', 'imagify' ) . '</span> <strong>' . $data['stats']['percent'] . '%</strong>' . $output_after;
	}

	// End of list.
	$output .= $is_media_page ? '' : '</ul>';

	// Actions section.
	$output .= $is_media_page ? $output_before : '';
	$output .= $reoptimize_output_before;
	$output .= $reoptimize_output;

	if ( $attachment->has_backup() ) {
		$args    = array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
		);
		$class   = $is_media_page ? '' : ' class="button-imagify-restore attachment-has-backup"';
		$waiting = $is_media_page ? '' : ' data-waiting-label="' . esc_attr__( 'Restoring...', 'imagify' ) . '"';
		$output .= '<a id="imagify-restore-' . $attachment_id . '" href="' . esc_url( get_imagify_admin_url( 'restore-upload', $args ) ) . '"' . $class . $waiting . '>';
			$output .= '<span class="dashicons dashicons-image-rotate"></span>' . __( 'Restore Original', 'imagify' );
		$output .= '</a>';

		if ( ! $is_library_page ) {
			$dimensions = $attachment->get_dimensions();

			$output .= '<input id="imagify-original-src" type="hidden" value="' . esc_url( $attachment->get_backup_url() ) . '">';
			$output .= '<input id="imagify-original-size" type="hidden" value="' . $attachment->get_original_size() . '">';
			$output .= '<input id="imagify-full-src" type="hidden" value="' . esc_url( $attachment->get_original_url() ) . '">';
			$output .= '<input id="imagify-full-width" type="hidden" value="' . $dimensions['width'] . '">';
			$output .= '<input id="imagify-full-height" type="hidden" value="' . $dimensions['height'] . '">';
		}
	}

	$output .= $reoptimize_output_after;
	$output .= $is_media_page ? $output_after : '';

	return $output;
}

/**
 * Get the error message for a specific attachment.
 *
 * @since  1.0
 * @author Jonathan Buttigieg
 *
 * @param  object $attachment The attachement object.
 * @param  string $context    A context.
 * @return string             The output to print.
 */
function get_imagify_attachment_error_text( $attachment, $context = 'wp' ) {
	global $pagenow, $typenow;

	$is_media_page = 'post.php' === $pagenow && 'attachment' === $typenow;
	$attachment_id = $attachment->id;
	$data          = $attachment->get_data();
	$output        = '';
	$args          = array(
		'attachment_id' => $attachment_id,
		'context'       => $context,
	);

	if ( isset( $data['sizes']['full']['success'] ) && ! $data['sizes']['full']['success'] ) {
		$class   = $is_media_page ? '' : ' button-imagify-manual-upload';
		$waiting = $is_media_page ? '' : ' data-waiting-label="' . esc_attr__( 'Optimizing...', 'imagify' ) . '"';
		$output .= '<strong>' . imagify_translate_api_message( $data['sizes']['full']['error'] ) . '</strong><br/>';
		$output .= '<a id="imagify-upload-' . $attachment_id . '" class="button' . $class . '" href="' . esc_url( get_imagify_admin_url( 'manual-upload', $args ) ) . '"' . $waiting . '>' . __( 'Try again', 'imagify' ) . '</a>';
	}

	return $output;
}

/**
 * Get the re-optimize link for a specific attachment.
 *
 * @since  1.0
 * @author Jonathan Buttigieg
 *
 * @param  object $attachment The attachement object.
 * @param  string $context    A context.
 * @return string             The output to print.
 */
function get_imagify_attachment_reoptimize_link( $attachment, $context = 'wp' ) {
	global $pagenow, $typenow;

	// Stop the process if the API key isn't valid.
	if ( ! imagify_valid_key() ) {
		return '';
	}

	$is_already_optimized = $attachment->is_already_optimized();

	// Don't display anything if there is no backup or the image has been optimized.
	if ( ! $attachment->has_backup() && ! $is_already_optimized ) {
		return '';
	}

	$is_media_page = 'post.php' === $pagenow && 'attachment' === $typenow;
	$attachment_id = $attachment->id;
	$level         = $attachment->get_optimization_level();
	$args          = array(
		'attachment_id' => $attachment_id,
		'context'       => $context,
	);
	$output        = '';
	$class         = $is_media_page ? '' : ' class="button-imagify-manual-override-upload"';
	$waiting       = $is_media_page ? '' : ' data-waiting-label="' . esc_attr__( 'Optimizing...', 'imagify' ) . '"';

	// Re-optimize to Ultra.
	if ( 1 === $level || 0 === $level ) {
		$args['optimization_level'] = 2;
		$output .= '<a href="' . esc_url( get_imagify_admin_url( 'manual-override-upload', $args ) ) . '"' . $class . $waiting . '>';
			/* translators: %s is an optimization level. */
			$output .= '<span class="dashicons dashicons-admin-generic"></span><span class="imagify-hide-if-small">' . sprintf( __( 'Re-Optimize to %s', 'imagify' ), '</span>' . __( 'Ultra', 'imagify' ) . '<span class="imagify-hide-if-small">' ) . '</span>';
		$output .= '</a>';
	}

	// Re-optimize to Aggressive.
	if ( ( 2 === $level && ! $is_already_optimized ) || 0 === $level ) {
		$args['optimization_level'] = 1;
		$output .= '<a href="' . esc_url( get_imagify_admin_url( 'manual-override-upload', $args ) ) . '"' . $class . $waiting . '>';
			/* translators: %s is an optimization level. */
			$output .= '<span class="dashicons dashicons-admin-generic"></span><span class="imagify-hide-if-small">' . sprintf( __( 'Re-Optimize to %s', 'imagify' ), '</span>' . __( 'Aggressive', 'imagify' ) . '<span class="imagify-hide-if-small">' ) . '</span>';
		$output .= '</a>';
	}

	// Re-optimize to Normal.
	if ( ( 2 === $level || 1 === $level ) && ! $is_already_optimized ) {
		$args['optimization_level'] = 0;
		$output .= '<a href="' . esc_url( get_imagify_admin_url( 'manual-override-upload', $args ) ) . '"' . $class . $waiting . '>';
			/* translators: %s is an optimization level. */
			$output .= '<span class="dashicons dashicons-admin-generic"></span><span class="imagify-hide-if-small">' . sprintf( __( 'Re-Optimize to %s', 'imagify' ), '</span>' . __( 'Normal', 'imagify' ) . '<span class="imagify-hide-if-small">' ) . '</span>';
		$output .= '</a>';
	}

	return $output;
}

/**
 * Get the link to optimize missing thumbnail sizes for a specific attachment.
 *
 * @since  1.6.10
 * @author Grégory Viguier
 *
 * @param  object $attachment The attachement object.
 * @param  string $context    A context.
 * @return string             The output to print.
 */
function get_imagify_attachment_optimize_missing_thumbnails_link( $attachment, $context = 'wp' ) {
	/**
	 * Allow to not display the "Optimize missing thumbnails" link.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @param bool   $display    True to display the link. False to not display it.
	 * @param object $attachment The attachement object.
	 * @param string $context    The context.
	 */
	$display = apply_filters( 'imagify_display_missing_thumbnails_link', true, $attachment, $context );

	// Stop the process if the filter is false, or if the API key isn't valid, or if there is no backup file.
	if ( ! $display || ! imagify_valid_key() || ! $attachment->has_backup() ) {
		return '';
	}

	$missing_sizes = $attachment->get_unoptimized_sizes();

	if ( ! $missing_sizes ) {
		return '';
	}

	$url = get_imagify_admin_url( 'optimize-missing-sizes', array(
		'attachment_id' => $attachment->id,
		'context'       => $context,
	) );

	$output  = '<a href="' . esc_url( $url ) . '" id="imagify-optimize_missing_sizes-' . $attachment->id . '" class="button-imagify-optimize-missing-sizes" data-waiting-label="' . esc_attr__( 'Optimizing...', 'imagify' ) . '">';
		/* translators: 1 is the number of thumbnails to optimize, 2 is the opening of a HTML tag that will be hidden on small screens, 3 is the closing tag. */
		$output .= '<span class="dashicons dashicons-admin-generic"></span>' . sprintf( _n( '%2$sOptimize %3$s%1$d missing thumbnail', '%2$sOptimize %3$s%1$d missing thumbnails', count( $missing_sizes ), 'imagify' ), count( $missing_sizes ), '<span class="imagify-hide-if-small">', '</span>' );
	$output .= '</a>';

	return $output;
}

/**
 * Get all data to diplay for a specific attachment.
 *
 * @since  1.2
 * @author Jonathan Buttigieg
 *
 * @param  object $attachment  The attachement object.
 * @param  string $context     A context.
 * @return string              The output to print.
 */
function get_imagify_media_column_content( $attachment, $context = 'wp' ) {
	if ( ! imagify_current_user_can( 'manual-optimize', $attachment->id ) ) {
		return __( 'You are not allowed to optimize this file.', 'imagify' );
	}

	// Check if the attachment extension is allowed.
	if ( ! $attachment->is_mime_type_supported() ) {
		$attachment_ext = $attachment->get_extension();

		/* translators: %s is a file extension. */
		return sprintf( __( '%s can\'t be optimized', 'imagify' ), strtoupper( $attachment_ext ) );
	}

	// Check if the attachment has the required WP metadata.
	if ( ! $attachment->has_required_metadata() ) {
		return __( 'This media lacks the required metadata and can\'t be optimized.', 'imagify' );
	}

	// Check if the API key is valid.
	if ( ! imagify_valid_key() && ! $attachment->is_optimized() ) {
		$output  = __( 'Invalid API key', 'imagify' );
		$output .= '<br/>';
		$output .= '<a href="' . esc_url( get_imagify_admin_url( 'options-general' ) ) . '">' . __( 'Check your Settings', 'imagify' ) . '</a>';
		return $output;
	}

	$transient_context = ( 'wp' !== $context ) ? strtolower( $context ) . '-' : '';
	$transient_name    = 'imagify-' . $transient_context . 'async-in-progress-' . $attachment->id;

	if ( false !== get_transient( $transient_name ) ) {
		return '<div class="button"><span class="imagify-spinner"></span>' . __( 'Optimizing...', 'imagify' ) . '</div>';
	}

	// Check if the image was optimized.
	if ( ! $attachment->is_optimized() && ! $attachment->has_error() ) {
		$args = array(
			'attachment_id' => $attachment->id,
			'context'       => $context,
		);
		$output = '<a id="imagify-upload-' . $attachment->id . '" href="' . esc_url( get_imagify_admin_url( 'manual-upload', $args ) ) . '" class="button-primary button-imagify-manual-upload" data-waiting-label="' . esc_attr__( 'Optimizing...', 'imagify' ) . '">' . __( 'Optimize', 'imagify' ) . '</a>';

		if ( $attachment->has_backup() ) {
			$output .= '<span class="attachment-has-backup hidden"></span>';
		}

		return $output;
	}

	return get_imagify_attachment_optimization_text( $attachment, $context );
}
