<?php
/*
Plugin Name: Attachment Scout
Plugin URI: https://github.com/ext237/wp-attachment-scout
Description: Scan WordPress media attachments and review which ones appear to be unused or orphaned.
Version: 1.1.0
Author: 24Moves
Author URI: https://24moves.com/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: attachment-scout
Domain Path: /languages
Tested up to: 7.0.1
*/


// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Attachment Scout
 *
 * Overview:
 * - Adds a WordPress admin page for reviewing media attachments.
 * - Scans attachment records (currently JPEG/PNG) and renders a table of candidates.
 * - Shows simple heuristic indicators for whether an attachment appears to be featured
 *   or referenced elsewhere in the database.
 * - Allows an administrator to delete selected attachments and their generated
 *   intermediate image sizes from the server.
 *
 * Important:
 * - This plugin is destructive by design. It can permanently remove files.
 * - The "used" and "featured" flags are informational heuristics and should not be
 *   treated as guaranteed-safe decisions.
 */

/**
 * Register the admin menu page for the plugin.
 *
 * The page is rendered through attachment_scout_settings_page() and is available
 * only to users with the manage_options capability.
 */
function attachment_scout_add_admin_menu()
{
	add_menu_page('Attachment Scout', 'Attachment Scout', 'manage_options', 'attachment-scout', 'attachment_scout_settings_page');
}
add_action('admin_menu', 'attachment_scout_add_admin_menu');


/**
 * Render the main Attachment Scout admin page.
 *
 * The page includes:
 * - a prominent warning about permanent deletion,
 * - a scan form that triggers a fresh attachment review,
 * - and, after scanning, a table of attachment candidates and a delete button.
 *
 * The scan request is handled on the same page and uses a nonce for protection.
 */
function attachment_scout_settings_page()
{
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'attachment-scout'));
	}
?>
	<div class="wrap">
		<h1>Attachment Scout</h1>
		<div class="notice notice-error attachment-scout-delete-warning">
			<p>
				<strong><?php esc_html_e('Permanent deletion warning', 'attachment-scout'); ?></strong>
			</p>

			<p>
				<?php esc_html_e(
					'Continuing will permanently delete the selected files from the server and remove them from the WordPress Media Library. This action cannot be undone. Only continue if you have already taken a full backup.',
					'attachment-scout'
				); ?>
			</p>
		</div>
		<form method="post" action="">
			<?php wp_nonce_field('attachment_scout_scan_images', 'attachment_scout_scan_nonce'); ?>
			<p>
				<input type="submit" name="attachment_scout_scan" class="button button-primary" value="Scan for Attachments">
			</p>
		</form>
		<?php
		if (isset($_POST['attachment_scout_scan'])) {
			if (!isset($_POST['attachment_scout_scan_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['attachment_scout_scan_nonce'])), 'attachment_scout_scan_images')) {
				wp_die(esc_html__('Invalid request.', 'attachment-scout'));
			}
			attachment_scout_display_images();
		}
		?>
	</div>
	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const getCellValue = (tr, idx) => tr.children[idx].innerText || tr.children[idx].textContent;
			const comparer = (idx, asc) => (a, b) => ((v1, v2) =>
				v1 !== '' && v2 !== '' && !isNaN(v1) && !isNaN(v2) ? v1 - v2 : v1.toString().localeCompare(v2)
			)(getCellValue(asc ? a : b, idx), getCellValue(asc ? b : a, idx));

			document.querySelectorAll('th').forEach(th => th.addEventListener('click', (() => {
				const table = th.closest('table');
				Array.from(table.querySelectorAll('tr:nth-child(n+2)'))
					.sort(comparer(Array.from(th.parentNode.children).indexOf(th), this.asc = !this.asc))
					.forEach(tr => table.appendChild(tr));
			})));
		});
	</script>
<?php
}


/**
 * Query attachment records and render the review table.
 *
 * Current behavior:
 * - selects attachment posts from the WordPress posts table,
 * - filters to JPEG/PNG mime types,
 * - collects basic metadata such as title, URL, upload date, and file size,
 * - and builds a checkbox-based delete form for each attachment.
 *
 * The "featured" and "used" columns are heuristic indicators. They are intended
 * to help a reviewer, but they are not definitive proof that the file is safe to delete.
 */
function attachment_scout_display_images()
{
	global $wpdb;

	$query = "
        SELECT ID, post_title, guid, post_date
        FROM {$wpdb->prefix}posts
        WHERE post_type = 'attachment'
        AND post_mime_type IN ('image/jpeg', 'image/png')
    ";

	$attachments = $wpdb->get_results($query);

	$total_images = count($attachments);

	if ($total_images == 0) {
		echo '<p>No images found.</p>';
		return;
	}

	echo '<h2>Total Attachments: ' . $total_images . '</h2>';
	echo '<h2>List of Attachments</h2>';
	echo '<form method="post" action="">';
	wp_nonce_field('attachment_scout_delete_images', 'attachment_scout_delete_nonce');
	echo '<table class="widefat fixed" cellspacing="0">';
	echo '<thead><tr><th>Select</th><th>ID</th><th>Title</th><th>URL</th><th>Featured</th><th>Used</th><th>Size</th><th>Date</th></tr></thead>';
	echo '<tbody>';

	foreach ($attachments as $attachment) {
		$is_featured = $wpdb->get_var($wpdb->prepare(
			"
            SELECT post_id
            FROM {$wpdb->prefix}postmeta
            WHERE meta_key = '_thumbnail_id'
            AND meta_value = %d
            LIMIT 1
            ",
			$attachment->ID
		));

		$checked_featured = $is_featured ? 'checked' : '';

		// If the image is not a featured image, check if it is used elsewhere
		$checked_used = $is_featured ? 'checked' : (attachment_scout_is_image_used($attachment->guid) ? 'checked' : '');

		// Get image size
		$meta = wp_get_attachment_metadata($attachment->ID);
		$size = isset($meta['filesize']) ? size_format($meta['filesize']) : 'Unknown';

		// Format the upload date
		$date = date('m/d/Y', strtotime($attachment->post_date));

		echo '<tr>';
		echo '<td><input type="checkbox" name="selected_images[]" value="' . esc_attr($attachment->ID) . '"></td>';
		echo '<td>' . esc_html($attachment->ID) . '</td>';
		echo '<td>' . esc_html($attachment->post_title) . '</td>';
		echo '<td><a href="' . esc_url($attachment->guid) . '" target="_blank">' . esc_html($attachment->guid) . '</a></td>';
		echo '<td><input type="checkbox" disabled ' . $checked_featured . '></td>';
		echo '<td><input type="checkbox" disabled ' . $checked_used . '></td>';
		echo '<td>' . esc_html($size) . '</td>';
		echo '<td>' . esc_html($date) . '</td>';
		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';
	echo '<p><input type="submit" name="attachment_scout_delete_selected" class="button button-primary" value="Delete Selected"></p>';
	echo '</form>';
}


/**
 * Heuristically check whether an attachment URL appears to be referenced somewhere in the database.
 *
 * This function scans many tables and text-like columns looking for the attachment URL.
 * Because it searches broadly, it can produce false positives and should be treated as a
 * cautionary signal rather than as proof that the file is still in active use.
 *
 * @param string $image_url The attachment URL to search for.
 * @return bool True when a likely match is found; otherwise false.
 */
function attachment_scout_is_image_used($image_url)
{
	global $wpdb;
	$tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);

	$excluded_tables = [
		"{$wpdb->prefix}commentmeta",
		"{$wpdb->prefix}comments",
		"{$wpdb->prefix}links",
		"{$wpdb->prefix}options",
		"{$wpdb->prefix}postmeta",
		"{$wpdb->prefix}termmeta",
		"{$wpdb->prefix}terms",
		"{$wpdb->prefix}term_relationships",
		"{$wpdb->prefix}term_taxonomy",
		"{$wpdb->prefix}usermeta",
		"{$wpdb->prefix}users",
		"{$wpdb->prefix}actionscheduler_actions",
		"{$wpdb->prefix}actionscheduler_claims",
		"{$wpdb->prefix}actionscheduler_groups",
		"{$wpdb->prefix}actionscheduler_logs",
		"{$wpdb->prefix}azonepress_log",
		"{$wpdb->prefix}azonepress_api_keys",
		"{$wpdb->prefix}enum_logs",
		"{$wpdb->prefix}meetup_groups",
		"{$wpdb->prefix}page_visit",
		"{$wpdb->prefix}page_visit_history"
	];

	foreach ($tables as $table) {
		$table_name = $table[0];
		if (in_array($table_name, $excluded_tables) || preg_match('/^' . $wpdb->prefix . 'wf.*/', $table_name)) {
			continue;
		}

		$columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name", ARRAY_N);

		foreach ($columns as $column) {
			$column_name = $column[0];
			$column_type = $column[1];

			// Skip columns that are unlikely to contain URLs
			if (preg_match('/(int|float|double|decimal|date|time|year|char|binary|blob)/i', $column_type) || strpos($column_type, '(') && intval(preg_replace('/[^\d]/', '', $column_type)) < 40) {
				continue;
			}

			$query = $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE $column_name LIKE %s", '%' . $wpdb->esc_like($image_url) . '%');
			$count = $wpdb->get_var($query);

			if ($count > 0) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Delete an attachment file and any generated intermediate sizes.
 *
 * This helper removes:
 * - the original uploaded file,
 * - each intermediate image size stored in attachment metadata,
 * - and finally the WordPress attachment record itself.
 *
 * It is intentionally destructive and should only be triggered after explicit confirmation.
 *
 * @param int $attachment_id The WordPress attachment ID to delete.
 */
function attachment_scout_delete_attachment_with_sizes($attachment_id)
{
	$attachment_id = absint($attachment_id);
	if (!$attachment_id) {
		return;
	}

	$attached_file = get_attached_file($attachment_id);
	if ($attached_file && file_exists($attached_file)) {
		wp_delete_file($attached_file);
	}

	$metadata = wp_get_attachment_metadata($attachment_id);
	if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
		$attachment_dir = $attached_file ? dirname($attached_file) : '';

		foreach ($metadata['sizes'] as $size_data) {
			if (empty($size_data['file'])) {
				continue;
			}

			$size_file = $attachment_dir ? trailingslashit($attachment_dir) . $size_data['file'] : '';
			if ($size_file && file_exists($size_file)) {
				wp_delete_file($size_file);
			}
		}
	}

	wp_delete_attachment($attachment_id, true);
}

/**
 * Handle the delete form submission from the admin page.
 *
 * This function performs capability and nonce checks before calling the destructive delete
 * helper for each selected attachment. It is the final step in the admin workflow.
 */
function attachment_scout_delete_selected_images()
{
	if (!current_user_can('manage_options')) {
		return;
	}

	if (isset($_POST['attachment_scout_delete_selected'])) {
		check_admin_referer('attachment_scout_delete_images', 'attachment_scout_delete_nonce');

		if (!empty($_POST['selected_images']) && is_array($_POST['selected_images'])) {
			$selected_images = array_map('absint', wp_unslash($_POST['selected_images']));

			foreach ($selected_images as $image_id) {
				attachment_scout_delete_attachment_with_sizes($image_id);
			}

			echo '<div class="updated"><p>Selected images have been deleted.</p></div>';
		}
	}
}
add_action('admin_init', 'attachment_scout_delete_selected_images');
