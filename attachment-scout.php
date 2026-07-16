<?php
/*
Plugin Name: Attachment Scout
Plugin URI: https://github.com/ext237/wp-attachment-scout
Description: Scan WordPress media attachments and review which ones appear to be unused or orphaned.
Version: 1.0.0
Author: 24Moves
Author URI: https://24moves.com/
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: attachment-scout
Domain Path: /languages
*/


// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Add the settings page to the admin menu
function attachment_scout_add_admin_menu()
{
	add_menu_page('Attachment Scout', 'Attachment Scout', 'manage_options', 'attachment-scout', 'attachment_scout_settings_page');
}
add_action('admin_menu', 'attachment_scout_add_admin_menu');


// Display the settings page
function attachment_scout_settings_page()
{
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'attachment-scout'));
	}
?>
	<div class="wrap">
		<h1>Attachment Scout</h1>
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


function attachment_scout_display_images() {
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


// Function to check if image is used in any database record
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

// Handle form submission and delete selected images
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
				// Delete the image and all its versions
				wp_delete_attachment($image_id, true);
			}

			echo '<div class="updated"><p>Selected images have been deleted.</p></div>';
		}
	}
}
add_action('admin_init', 'attachment_scout_delete_selected_images');
