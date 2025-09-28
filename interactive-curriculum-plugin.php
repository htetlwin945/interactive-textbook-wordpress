<?php
/**
 * Plugin Name: Interactive Student Curriculum
 * Plugin URI: https://zeagwat.com
 * Version: 3.3.0
 * Description: Enables interactive PDF viewing and annotation for students.
 * Author: Zeagwat, Inc.
 * Author URI: https://zeagwat.com
 * License: GPLv2 or later
 * Text Domain: interactive-curriculum
 *
 * == Changelog ==
 *
 * = 3.3.0 =
 * * FEAT: Implemented a "Class-Wide Analytics Dashboard" to provide an overview of student progress and activity.
 *
 * = 3.2.0 =
 * * FEAT: Implemented a "Student Annotation Viewer" for administrators to view student work in a read-only mode.
 *
 * = 3.1.0 =
 * * FEAT: Added a "Student Frontend Dashboard" accessible via the `[icp_student_dashboard]` shortcode. The dashboard includes book covers, progress bars, student's full name, and a grid/list view switcher.
 * * FEAT: Implemented a mechanism to automatically calculate and save a PDF's total page count when uploaded in the book editor.
 * * FIX: Resolved a conflict on touch devices where using the 'Select' tool would also trigger panning. Panning is now correctly restricted to the 'Pan' tool.
 *
 * = 3.0.24 =
 * * FIX: Improved LiteSpeed Cache compatibility by moving the 'do not cache' directive to a more reliable execution point during shortcode rendering.
 *
 * = 3.0.23 =
 * * TWEAK: Refactored viewer CSS into a separate file for better maintainability.
 * * TWEAK: Added plugin version to the main plugin file header to display in the admin plugins list.
 * * TWEAK: Reduced font size for the page count indicator and save status message in the viewer toolbar.
 *
 * = 3.0.22 =
 * * FEAT: Added compatibility with LiteSpeed Cache to prevent caching of dynamic viewer pages and annotation data.
 * * FIX: Replaced page tracking logic with a more robust method based on the viewport center, fixing bugs with Undo/Redo and last-page-read functionality when scrolling between pages.
 *
 * = 3.0.21 =
 * * PERF: Implemented lazy rendering for PDF pages. Now, when zooming or scrolling, only the pages currently visible in the viewport are re-rendered, significantly improving performance.
 *
 * = 3.0.20 =
 * * FIX: Undo/Redo buttons now correctly update their state when scrolling between pages.
 * * FIX: The page number indicator in the toolbar now correctly shows the current page on initial load.
 *
 * = 3.0.19 =
 * * FIX: Rolled back initial PDF rendering logic to a more stable version to prevent loading errors on some devices.
 * * FIX: Fully disabled pinch-to-zoom gesture, which was still active and conflicting with two-finger panning.
 *
 * = 3.0.6 =
 * * FEAT: Enabled smooth, one-finger scrolling on touch devices.
 * * PERF: Optimized touch gestures (two-finger pan & zoom) for a smoother experience using requestAnimationFrame.
 *
 * = 3.0.5 =
 * * FEAT: Added a dedicated "Pan" tool to the toolbar for easier navigation on all devices.
 * * FEAT: Implemented page thumbnail previews in the sidebar for quick navigation.
 * * FIX: Implemented robust touch controls for smooth pinch-to-zoom and panning on mobile devices.
 * * FIX: Made toolbar responsive and horizontally scrollable on mobile to ensure all buttons are accessible.
 *
 * = 3.0.4 =
 * * CRITICAL FIX: Corrected a typo ('unedo' instead of 'undo') that was halting script execution and preventing PDFs from loading.
 *
 * = 3.0.3 =
 * * CRITICAL FIX: Restored all data saving functions (annotations, last-read page).
 * * FIX: Resolved an issue causing the PDF to load from the last page instead of the correct one.
 * * FIX: Re-integrated all features from v2.9.0 to create a stable, complete version.
 * * = 3.0.2 =
 * * CRITICAL FIX: Resolved annotation alignment issues during zoom. Annotations now stay perfectly synchronized with the PDF content.
 * * FIX: The initial PDF view now loads at a correct "fit-to-width" scale instead of appearing shrunken.
 * * FIX: Restored the "Saving..." indicator that provides feedback when annotations are saved.
 *
 * = 3.0.1 =
 * * CRITICAL FIX: Resolved a regression where essential functions were missing, preventing PDFs from loading.
 * * = 3.0.0 =
 * * MAJOR FEAT: Implemented advanced zoom and pan functionality.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// ... The rest of this file is correct and unchanged from the previous version ...

function interactive_curriculum_activate() {
    // Add 'Student' user role
    $student_role_exists = get_role('student');
    if (!$student_role_exists) {
        add_role(
            'student',
            __('Student', 'interactive-curriculum'),
            array(
                'read' => true, // Basic capability
            )
        );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'icp_annotations';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        document_id varchar(255) NOT NULL,
        page_num int(11) NOT NULL,
        annotations_data longtext NOT NULL,
        last_updated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_doc_page (user_id, document_id, page_num)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'interactive_curriculum_activate');

function interactive_curriculum_register_rest_endpoint() {
    register_rest_route('interactive-curriculum/v2', '/annotations/(?P<document_id>[a-zA-Z0-9-._]+)/(?P<page_num>\d+)', array(
        array(
            'methods' => 'POST',
            'callback' => 'interactive_curriculum_save_annotations',
            'permission_callback' => 'is_user_logged_in',
        ),
        array(
            'methods' => 'GET',
            'callback' => 'interactive_curriculum_get_annotations',
            'permission_callback' => 'is_user_logged_in',
        ),
    ));

    register_rest_route('interactive-curriculum/v2', '/last-page', array(
        'methods' => 'POST',
        'callback' => 'interactive_curriculum_save_last_page',
        'permission_callback' => 'is_user_logged_in',
    ));

    register_rest_route('interactive-curriculum/v2', '/user-color', array(
        'methods' => 'POST',
        'callback' => 'interactive_curriculum_save_user_color',
        'permission_callback' => 'is_user_logged_in',
    ));
}
add_action('rest_api_init', 'interactive_curriculum_register_rest_endpoint');

function interactive_curriculum_save_user_color(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $user_id = get_current_user_id();
    $color = isset($params['color']) ? sanitize_hex_color($params['color']) : null;

    if ($user_id && $color) {
        update_user_meta($user_id, 'icp_last_color', $color);
        return new WP_REST_Response(array('status' => 'success'), 200);
    }

    return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid data.'), 400);
}

function interactive_curriculum_save_last_page(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $user_id = get_current_user_id();
    $document_id = isset($params['document_id']) ? sanitize_text_field($params['document_id']) : null;
    $page_num = isset($params['page_num']) ? (int) $params['page_num'] : null;

    if ($user_id && $document_id && $page_num > 0) {
        $meta_key = 'icp_last_page_' . $document_id;
        update_user_meta($user_id, $meta_key, $page_num);
        return new WP_REST_Response(array('status' => 'success'), 200);
    }

    return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid data.'), 400);
}

function interactive_curriculum_save_annotations(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $document_id = sanitize_text_field($request->get_param('document_id'));
    $page_num = (int)$request->get_param('page_num');

    $annotations_data = $request->get_body();

    if (empty($user_id) || empty($document_id) || empty($page_num)) {
        return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid data provided.'), 400);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'icp_annotations';

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND document_id = %s AND page_num = %d",
        $user_id, $document_id, $page_num
    ));

    $data = array(
        'annotations_data' => $annotations_data,
        'last_updated' => current_time('mysql'),
    );
    $data_format = array('%s', '%s');

    if ($existing) {
        $where = array(
            'user_id' => $user_id,
            'document_id' => $document_id,
            'page_num' => $page_num
        );
        $where_format = array('%d', '%s', '%d');
        $wpdb->update($table_name, $data, $where, $data_format, $where_format);
    } else {
        $insert_data = array_merge(
            array(
                'user_id' => $user_id,
                'document_id' => $document_id,
                'page_num' => $page_num,
            ),
            $data
        );
        $insert_format = array_merge(array('%d', '%s', '%d'), $data_format);
        $wpdb->insert($table_name, $insert_data, $insert_format);
    }

    return new WP_REST_Response(array('status' => 'success', 'message' => 'Annotations saved.'), 200);
}

function interactive_curriculum_get_annotations(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $document_id = sanitize_text_field($request->get_param('document_id'));
    $page_num = (int)$request->get_param('page_num');

    // Admin override logic
    if (current_user_can('manage_options') && $request->get_param('student_id')) {
        // The 'wp_rest' nonce is already checked by default for permission_callback.
        // We add an extra layer of security by checking for a specific admin nonce if a student_id is passed.
        $admin_nonce = $request->get_param('admin_nonce');
        if (wp_verify_nonce($admin_nonce, 'icp_view_student_annotations_' . $request->get_param('student_id'))) {
            $user_id = (int)$request->get_param('student_id');
        } else {
            // If a student_id is provided but the nonce is invalid, deny the request.
            return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid admin nonce.'), 403);
        }
    }

    if (empty($user_id) || empty($document_id) || empty($page_num)) {
        return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid data provided.'), 400);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'icp_annotations';

    $data = $wpdb->get_var($wpdb->prepare(
        "SELECT annotations_data FROM $table_name WHERE user_id = %d AND document_id = %s AND page_num = %d",
        $user_id, $document_id, $page_num
    ));

    $response = new WP_REST_Response();
    // Add LiteSpeed no-cache header to prevent stale annotation data
    $response->header('X-LiteSpeed-Cache-Control', 'no-cache');

    if ($data) {
        $response->set_data(json_decode($data));
        $response->set_status(200);
    } else {
        $response->set_data(array());
        $response->set_status(200);
    }

    return $response;
}

function icp_register_post_types() {
    // Register Book CPT
    register_post_type('icp_book',
        array(
            'labels'      => array(
                'name'          => __('Books', 'interactive-curriculum'),
                'singular_name' => __('Book', 'interactive-curriculum'),
            ),
            'public'      => true,
            'has_archive' => true,
            'supports'    => array('title', 'editor', 'thumbnail'),
            'show_in_rest' => true,
        )
    );

    // Register Class CPT
    register_post_type('icp_class',
        array(
            'labels'      => array(
                'name'          => __('Classes', 'interactive-curriculum'),
                'singular_name' => __('Class', 'interactive-curriculum'),
            ),
            'public'      => true,
            'has_archive' => true,
            'supports'    => array('title', 'editor'),
            'show_in_rest' => true,
        )
    );
}
add_action('init', 'icp_register_post_types');

class Interactive_Curriculum_Plugin {

    private $admin_view_student_id = null;
    private $admin_view_read_only = false;

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts_and_styles'));
        add_shortcode('interactive_curriculum_pdf', array($this, 'render_pdf_viewer_shortcode'));
        add_shortcode('icp_student_dashboard', array($this, 'render_student_dashboard_shortcode'));
        add_action('add_meta_boxes', array($this, 'add_custom_meta_boxes'));
        add_action('save_post_icp_book', array($this, 'save_book_meta_data'));
        add_action('save_post_icp_class', array($this, 'save_class_meta_data'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_icp_get_students_for_class', array($this, 'ajax_get_students_for_class'));
        add_action('wp_ajax_icp_get_books_for_class', array($this, 'ajax_get_books_for_class'));
    }

    public function ajax_get_students_for_class() {
        check_ajax_referer('icp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['class_id'])) {
            wp_send_json_error();
        }

        $class_id = intval($_POST['class_id']);
        $student_ids = get_post_meta($class_id, '_icp_enrolled_students', true);
        $students_data = array();

        if (!empty($student_ids)) {
            $students = get_users(array('include' => $student_ids, 'fields' => array('ID', 'display_name')));
            foreach ($students as $student) {
                $students_data[] = array('id' => $student->ID, 'name' => $student->display_name);
            }
        }
        wp_send_json_success($students_data);
    }

    public function ajax_get_books_for_class() {
        check_ajax_referer('icp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options') || !isset($_POST['class_id'])) {
            wp_send_json_error();
        }

        $class_id = intval($_POST['class_id']);
        $book_ids = get_post_meta($class_id, '_icp_assigned_books', true);
        $books_data = array();

        if (!empty($book_ids)) {
            $books = get_posts(array('post__in' => $book_ids, 'post_type' => 'icp_book', 'post_status' => 'publish', 'numberposts' => -1));
            foreach ($books as $book) {
                $books_data[] = array('id' => $book->ID, 'title' => $book->post_title);
            }
        }
        wp_send_json_success($books_data);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Interactive Curriculum', 'interactive-curriculum'),
            __('Interactive Curriculum', 'interactive-curriculum'),
            'manage_options',
            'interactive-curriculum',
            null, // No top-level page content
            'dashicons-book-alt',
            20
        );

        add_submenu_page(
            'interactive-curriculum',
            __('Annotation Viewer', 'interactive-curriculum'),
            __('Annotation Viewer', 'interactive-curriculum'),
            'manage_options',
            'icp-annotation-viewer',
            array($this, 'render_annotation_viewer_page')
        );

        add_submenu_page(
            'interactive-curriculum',
            __('Class Analytics', 'interactive-curriculum'),
            __('Class Analytics', 'interactive-curriculum'),
            'manage_options',
            'icp-class-analytics',
            array($this, 'render_class_analytics_page')
        );
    }

    public function render_class_analytics_page() {
        $analytics_data = array();
        $class_name = '';
        $selected_class_id = isset($_POST['icp_class_id']) ? intval($_POST['icp_class_id']) : 0;

        // Check if form was submitted and process it
        if (
            isset($_POST['_icp_nonce']) &&
            wp_verify_nonce($_POST['_icp_nonce'], 'icp_view_class_analytics_nonce') &&
            current_user_can('manage_options') &&
            $selected_class_id
        ) {
            global $wpdb;
            $class_name = get_the_title($selected_class_id);

            $student_ids = get_post_meta($selected_class_id, '_icp_enrolled_students', true);
            $book_ids = get_post_meta($selected_class_id, '_icp_assigned_books', true);

            if (!empty($student_ids) && is_array($student_ids) && !empty($book_ids) && is_array($book_ids)) {
                $students = get_users(array('include' => $student_ids, 'fields' => array('ID', 'display_name'), 'orderby' => 'display_name'));
                $books = get_posts(array('post__in' => $book_ids, 'post_type' => 'icp_book', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));

                foreach ($students as $student) {
                    foreach ($books as $book) {
                        $total_pages = (int) get_post_meta($book->ID, '_icp_total_pages', true) ?: 1;
                        $last_read_page = (int) get_user_meta($student->ID, 'icp_last_page_' . $book->ID, true) ?: 0;

                        if ($last_read_page > $total_pages) {
                            $last_read_page = $total_pages;
                        }

                        $progress_percentage = ($last_read_page / $total_pages) * 100;

                        $last_active = $wpdb->get_var($wpdb->prepare(
                            "SELECT MAX(last_updated) FROM {$wpdb->prefix}icp_annotations WHERE user_id = %d AND document_id = %s",
                            $student->ID,
                            (string)$book->ID
                        ));

                        $analytics_data[] = (object) array(
                            'student_name' => $student->display_name,
                            'book_title'   => $book->post_title,
                            'total_pages'  => $total_pages,
                            'last_read'    => $last_read_page,
                            'progress'     => round($progress_percentage),
                            'last_active'  => $last_active,
                        );
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Class Analytics Dashboard', 'interactive-curriculum'); ?></h1>
            <p><?php _e('Select a class to view student progress and activity.', 'interactive-curriculum'); ?></p>

            <form id="icp-analytics-form" method="POST" action="">
                <?php wp_nonce_field('icp_view_class_analytics_nonce', '_icp_nonce'); ?>
                <table class="form-table">
                    <tbody>
                        <tr valign="top">
                            <th scope="row">
                                <label for="icp_class_id_analytics"><?php _e('Select a Class', 'interactive-curriculum'); ?></label>
                            </th>
                            <td>
                                <select id="icp_class_id_analytics" name="icp_class_id" required style="min-width: 200px;">
                                    <option value=""><?php _e('— Select a Class —', 'interactive-curriculum'); ?></option>
                                    <?php
                                    $classes = get_posts(array('post_type' => 'icp_class', 'post_status' => 'publish', 'posts_per_page' => -1));
                                    foreach ($classes as $class) {
                                        $selected = selected($selected_class_id, $class->ID, false);
                                        echo '<option value="' . esc_attr($class->ID) . '" ' . $selected . '>' . esc_html($class->post_title) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Show Report', 'interactive-curriculum')); ?>
            </form>

            <hr/>

            <div id="icp-analytics-report-container">
                <?php
                if (!empty($analytics_data)) {
                    echo '<h2>' . sprintf(__('Analytics Report for: %s', 'interactive-curriculum'), esc_html($class_name)) . '</h2>';
                    ?>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th><?php _e('Student Name', 'interactive-curriculum'); ?></th>
                                <th><?php _e('Book Title', 'interactive-curriculum'); ?></th>
                                <th><?php _e('Progress', 'interactive-curriculum'); ?></th>
                                <th><?php _e('Last Active', 'interactive-curriculum'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analytics_data as $data) : ?>
                                <tr>
                                    <td><?php echo esc_html($data->student_name); ?></td>
                                    <td><?php echo esc_html($data->book_title); ?></td>
                                    <td>
                                        <div style="width: 100%; background-color: #e0e0e0; border-radius: 4px; overflow: hidden;">
                                            <div style="width: <?php echo esc_attr($data->progress); ?>%; background-color: #4caf50; height: 24px; line-height: 24px; color: white; text-align: center;">
                                                <?php echo esc_html(round($data->progress)); ?>%
                                            </div>
                                        </div>
                                        <small>
                                            <?php
                                            printf(
                                                __('Page %1$d of %2$d', 'interactive-curriculum'),
                                                esc_html($data->last_read),
                                                esc_html($data->total_pages)
                                            );
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        if ($data->last_active) {
                                            echo esc_html(human_time_diff(strtotime($data->last_active), current_time('timestamp'))) . ' ' . __('ago', 'interactive-curriculum');
                                        } else {
                                            _e('N/A', 'interactive-curriculum');
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                } elseif ($selected_class_id) {
                    echo '<h2>' . sprintf(__('Analytics Report for: %s', 'interactive-curriculum'), esc_html($class_name)) . '</h2>';
                    echo '<p>' . __('No data to display. Please ensure the class has enrolled students and assigned books with activity.', 'interactive-curriculum') . '</p>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function render_annotation_viewer_page() {
        // Check if form was submitted and process it
        if (
            isset($_POST['_icp_nonce']) &&
            wp_verify_nonce($_POST['_icp_nonce'], 'icp_view_student_annotations_nonce') &&
            current_user_can('manage_options') &&
            !empty($_POST['icp_book_id']) &&
            !empty($_POST['icp_student_id'])
        ) {
            $this->admin_view_student_id = intval($_POST['icp_student_id']);
            $this->admin_view_read_only = true;
            $book_id_to_view = intval($_POST['icp_book_id']);
        } else {
            $book_id_to_view = null;
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Student Annotation Viewer', 'interactive-curriculum'); ?></h1>
            <p><?php _e('Select a class, student, and book to view the student\'s annotations. The view will be read-only.', 'interactive-curriculum'); ?></p>

            <form id="icp-annotation-viewer-form" method="POST" action="">
                <?php wp_nonce_field('icp_view_student_annotations_nonce', '_icp_nonce'); ?>
                <table class="form-table">
                    <tbody>
                        <tr valign="top">
                            <th scope="row">
                                <label for="icp_class_id"><?php _e('Select a Class', 'interactive-curriculum'); ?></label>
                            </th>
                            <td>
                                <select id="icp_class_id" name="icp_class_id" required style="min-width: 200px;">
                                    <option value=""><?php _e('— Select a Class —', 'interactive-curriculum'); ?></option>
                                    <?php
                                    $classes = get_posts(array('post_type' => 'icp_class', 'post_status' => 'publish', 'posts_per_page' => -1));
                                    $current_class_id = isset($_POST['icp_class_id']) ? intval($_POST['icp_class_id']) : 0;
                                    foreach ($classes as $class) {
                                        $selected = selected($current_class_id, $class->ID, false);
                                        echo '<option value="' . esc_attr($class->ID) . '" ' . $selected . '>' . esc_html($class->post_title) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="icp_student_id"><?php _e('Select a Student', 'interactive-curriculum'); ?></label>
                            </th>
                            <td>
                                <select id="icp_student_id" name="icp_student_id" required <?php if (!$current_class_id) echo 'disabled'; ?> style="min-width: 200px;">
                                    <option value=""><?php _e('— Select a Class First —', 'interactive-curriculum'); ?></option>
                                    <?php
                                    if ($current_class_id) {
                                        $current_student_id = isset($_POST['icp_student_id']) ? intval($_POST['icp_student_id']) : 0;
                                        $student_ids = get_post_meta($current_class_id, '_icp_enrolled_students', true);
                                        if (!empty($student_ids) && is_array($student_ids)) {
                                            echo '<option value="">— Select a Student —</option>';
                                            $students = get_users(array('include' => $student_ids, 'fields' => array('ID', 'display_name'), 'orderby' => 'display_name'));
                                            foreach ($students as $student) {
                                                $selected = selected($current_student_id, $student->ID, false);
                                                echo '<option value="' . esc_attr($student->ID) . '" ' . $selected . '>' . esc_html($student->display_name) . '</option>';
                                            }
                                        } else {
                                            echo '<option value="">— No Students Found —</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="icp_book_id"><?php _e('Select a Book', 'interactive-curriculum'); ?></label>
                            </th>
                            <td>
                                <select id="icp_book_id" name="icp_book_id" required <?php if (!$current_class_id) echo 'disabled'; ?> style="min-width: 200px;">
                                    <option value=""><?php _e('— Select a Class First —', 'interactive-curriculum'); ?></option>
                                    <?php
                                    if ($current_class_id) {
                                        $current_book_id = isset($_POST['icp_book_id']) ? intval($_POST['icp_book_id']) : 0;
                                        $book_ids = get_post_meta($current_class_id, '_icp_assigned_books', true);
                                        if (!empty($book_ids) && is_array($book_ids)) {
                                             echo '<option value="">— Select a Book —</option>';
                                            $books = get_posts(array('post__in' => $book_ids, 'post_type' => 'icp_book', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
                                            foreach ($books as $book) {
                                                $selected = selected($current_book_id, $book->ID, false);
                                                echo '<option value="' . esc_attr($book->ID) . '" ' . $selected . '>' . esc_html($book->post_title) . '</option>';
                                            }
                                        } else {
                                            echo '<option value="">— No Books Found —</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('View Annotations', 'interactive-curriculum')); ?>
            </form>

            <hr/>

            <div id="icp-viewer-container">
                <?php
                if ($book_id_to_view) {
                    $student_user = get_userdata($this->admin_view_student_id);
                    $book_title = get_the_title($book_id_to_view);
                    echo '<h2>' . sprintf(
                        esc_html__('Viewing annotations for %1$s in "%2$s"', 'interactive-curriculum'),
                        '<strong>' . esc_html($student_user->display_name) . '</strong>',
                        '<em>' . esc_html($book_title) . '</em>'
                    ) . '</h2>';

                    echo do_shortcode('[interactive_curriculum_pdf document_id="' . $book_id_to_view . '"]');
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function add_custom_meta_boxes() {
        // Book Meta Boxes
        add_meta_box(
            'icp_book_pdf_upload',
            __('Upload PDF', 'interactive-curriculum'),
            array($this, 'render_pdf_upload_meta_box'),
            'icp_book',
            'normal',
            'high'
        );
        add_meta_box(
            'icp_book_shortcode',
            __('Shortcode', 'interactive-curriculum'),
            array($this, 'render_shortcode_display_meta_box'),
            'icp_book',
            'side',
            'default'
        );

        // Class Meta Boxes
        add_meta_box(
            'icp_class_assign_books',
            __('Assign Books', 'interactive-curriculum'),
            array($this, 'render_assign_books_meta_box'),
            'icp_class',
            'normal',
            'high'
        );
        add_meta_box(
            'icp_class_enroll_students',
            __('Enroll Students', 'interactive-curriculum'),
            array($this, 'render_enroll_students_meta_box'),
            'icp_class',
            'normal',
            'high'
        );
    }

    public function render_pdf_upload_meta_box($post) {
        wp_nonce_field('icp_save_book_meta', 'icp_book_meta_nonce');
        $pdf_url = get_post_meta($post->ID, '_icp_pdf_url', true);
        $total_pages = get_post_meta($post->ID, '_icp_total_pages', true);
        ?>
        <p>
            <label for="icp_pdf_url"><?php _e('PDF URL', 'interactive-curriculum'); ?></label>
            <input type="text" id="icp_pdf_url" name="icp_pdf_url" value="<?php echo esc_url($pdf_url); ?>" style="width: 100%;" readonly />
        </p>
        <p>
            <button type="button" id="icp_upload_pdf_button" class="button"><?php _e('Upload or Select PDF', 'interactive-curriculum'); ?></button>
        </p>
        <input type="hidden" id="icp_total_pages" name="icp_total_pages" value="<?php echo esc_attr($total_pages); ?>" />
        <div id="icp-page-count-display">
             <?php if ($total_pages) : ?>
                <p><strong><?php _e('Total Pages:', 'interactive-curriculum'); ?></strong> <?php echo esc_html($total_pages); ?></p>
             <?php else: ?>
                <p><?php _e('Page count will be automatically determined after selecting a PDF and saving the post.', 'interactive-curriculum'); ?></p>
             <?php endif; ?>
        </div>
        <?php
    }

    public function render_shortcode_display_meta_box($post) {
        ?>
        <p><?php _e('Copy this shortcode and paste it into your post, page, or text widget content:', 'interactive-curriculum'); ?></p>
        <input type="text" value="[interactive_curriculum_pdf document_id=&quot;<?php echo $post->ID; ?>&quot;]" readonly style="width: 100%;" />
        <?php
    }

    public function save_book_meta_data($post_id) {
        if (!isset($_POST['icp_book_meta_nonce']) || !wp_verify_nonce($_POST['icp_book_meta_nonce'], 'icp_save_book_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (isset($_POST['icp_pdf_url'])) {
            update_post_meta($post_id, '_icp_pdf_url', esc_url_raw($_POST['icp_pdf_url']));
        }
        if (isset($_POST['icp_total_pages']) && is_numeric($_POST['icp_total_pages'])) {
            update_post_meta($post_id, '_icp_total_pages', intval($_POST['icp_total_pages']));
        }
    }

    public function render_assign_books_meta_box($post) {
        wp_nonce_field('icp_save_class_meta', 'icp_class_meta_nonce');
        $assigned_books = get_post_meta($post->ID, '_icp_assigned_books', true) ?: array();
        $all_books = get_posts(array(
            'post_type' => 'icp_book',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ));

        if (empty($all_books)) {
            echo '<p>' . __('No books found. Please create some first.', 'interactive-curriculum') . '</p>';
            return;
        }

        echo '<ul>';
        foreach ($all_books as $book) {
            $checked = in_array($book->ID, $assigned_books) ? 'checked' : '';
            echo '<li>';
            echo '<label>';
            echo '<input type="checkbox" name="icp_assigned_books[]" value="' . esc_attr($book->ID) . '" ' . $checked . '> ';
            echo esc_html($book->post_title);
            echo '</label>';
            echo '</li>';
        }
        echo '</ul>';
    }

    public function render_enroll_students_meta_box($post) {
        $enrolled_students = get_post_meta($post->ID, '_icp_enrolled_students', true) ?: array();
        $all_students = get_users(array('role' => 'student'));

        if (empty($all_students)) {
            echo '<p>' . __('No students found.', 'interactive-curriculum') . '</p>';
            return;
        }

        echo '<ul>';
        foreach ($all_students as $student) {
            $checked = in_array($student->ID, $enrolled_students) ? 'checked' : '';
            echo '<li>';
            echo '<label>';
            echo '<input type="checkbox" name="icp_enrolled_students[]" value="' . esc_attr($student->ID) . '" ' . $checked . '> ';
            echo esc_html($student->display_name);
            echo '</label>';
            echo '</li>';
        }
        echo '</ul>';
    }

    public function save_class_meta_data($post_id) {
        if (!isset($_POST['icp_class_meta_nonce']) || !wp_verify_nonce($_POST['icp_class_meta_nonce'], 'icp_save_class_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save assigned books
        if (isset($_POST['icp_assigned_books'])) {
            $assigned_books = array_map('intval', $_POST['icp_assigned_books']);
            update_post_meta($post_id, '_icp_assigned_books', $assigned_books);
        } else {
            delete_post_meta($post_id, '_icp_assigned_books');
        }

        // Save enrolled students
        if (isset($_POST['icp_enrolled_students'])) {
            // Sanitize as integers, then cast to strings for reliable LIKE query
            $student_ids = array_map('intval', $_POST['icp_enrolled_students']);
            $student_ids_as_strings = array_map('strval', $student_ids);
            update_post_meta($post_id, '_icp_enrolled_students', $student_ids_as_strings);
        } else {
            delete_post_meta($post_id, '_icp_enrolled_students');
        }
    }

    public function enqueue_admin_scripts($hook) {
        // Enqueue script for the new Annotation Viewer page
        if ('interactive-curriculum_page_icp-annotation-viewer' === $hook) {
            // Enqueue script for the admin form dropdowns
            wp_enqueue_script(
                'icp-annotation-viewer-form-script',
                plugins_url('icp-annotation-viewer.js', __FILE__),
                array('jquery'),
                filemtime(plugin_dir_path(__FILE__) . 'icp-annotation-viewer.js'),
                true
            );
            wp_localize_script(
                'icp-annotation-viewer-form-script',
                'icp_admin_data',
                array(
                    'nonce' => wp_create_nonce('icp_admin_nonce')
                )
            );

            // Enqueue all scripts and styles required for the PDF viewer itself
            wp_enqueue_style(
                'interactive-curriculum-viewer-style',
                plugins_url('interactive-curriculum-viewer.css', __FILE__),
                array(),
                filemtime(plugin_dir_path(__FILE__) . 'interactive-curriculum-viewer.css')
            );

            wp_enqueue_script('pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js', array(), '2.11.338', true);
            wp_enqueue_script('fabricjs', 'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js', array(), '5.3.0', true);

            wp_enqueue_script(
                'interactive-curriculum-viewer',
                plugins_url('interactive-curriculum-viewer.js', __FILE__),
                array('jquery', 'pdfjs', 'fabricjs'),
                filemtime(plugin_dir_path(__FILE__) . 'interactive-curriculum-viewer.js'),
                true
            );

            wp_localize_script(
                'interactive-curriculum-viewer',
                'interactiveCurriculumData',
                array(
                    'restUrl' => get_rest_url(),
                    'nonce' => wp_create_nonce('wp_rest')
                )
            );
        }

        global $post;
        if ($hook == 'post-new.php' || $hook == 'post.php') {
            if (isset($post->post_type)) {
                if ($post->post_type === 'icp_book') {
                    wp_enqueue_media();
                    // Enqueue pdf.js for the page counting functionality
                    wp_enqueue_script('pdfjs-admin', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js', array(), '2.11.338', true);
                    wp_enqueue_script(
                        'icp-admin-script',
                        plugins_url('icp-admin-script.js', __FILE__),
                        array('jquery', 'pdfjs-admin'), // Add pdfjs-admin as a dependency
                        filemtime(plugin_dir_path(__FILE__) . 'icp-admin-script.js'),
                        true
                    );
                } elseif ($post->post_type === 'icp_class') {
                     wp_enqueue_media();
                     wp_enqueue_script(
                        'icp-admin-script',
                        plugins_url('icp-admin-script.js', __FILE__),
                        array('jquery'),
                        filemtime(plugin_dir_path(__FILE__) . 'icp-admin-script.js'),
                        true
                    );
                }
            }
        }
    }

    public function enqueue_scripts_and_styles() {
        if (!is_singular()) {
            return;
        }

        wp_enqueue_style(
            'interactive-curriculum-viewer-style',
            plugins_url('interactive-curriculum-viewer.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'interactive-curriculum-viewer.css')
        );

        wp_enqueue_script('pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js', array(), '2.11.338', true);
        wp_enqueue_script('fabricjs', 'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js', array(), '5.3.0', true);

        wp_enqueue_script(
            'interactive-curriculum-viewer',
            plugins_url('interactive-curriculum-viewer.js', __FILE__),
            array('jquery', 'pdfjs', 'fabricjs'),
            filemtime(plugin_dir_path(__FILE__) . 'interactive-curriculum-viewer.js'),
            true
        );

        wp_localize_script(
            'interactive-curriculum-viewer',
            'interactiveCurriculumData',
            array(
                'restUrl' => get_rest_url(),
                'nonce' => wp_create_nonce('wp_rest')
            )
        );
    }

    public function render_pdf_viewer_shortcode($atts) {
        // LiteSpeed Cache Compatibility: Tell LiteSpeed not to cache this page.
        if (function_exists('litespeed_do_not_cache')) {
            litespeed_do_not_cache('Interactive curriculum page detected');
        }

        $atts = shortcode_atts(array(
            'document_id' => ''
        ), $atts, 'interactive_curriculum_pdf');

        if (!is_user_logged_in()) {
            return '<p>Please log in to view this book.</p>';
        }

        $document_id = intval($atts['document_id']);
        if (empty($document_id) || get_post_type($document_id) !== 'icp_book') {
            return '<p>Error: Invalid Book ID.</p>';
        }

        $is_admin_view = $this->admin_view_student_id && current_user_can('manage_options');
        $user_id_to_check = $is_admin_view ? $this->admin_view_student_id : get_current_user_id();

        // Permission Check
        $allowed_books = array();
        $student_classes = get_posts(array(
            'post_type' => 'icp_class',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key'     => '_icp_enrolled_students',
                    'value'   => '"' . strval($user_id_to_check) . '"',
                    'compare' => 'LIKE',
                ),
            )
        ));

        foreach ($student_classes as $class) {
            $class_books = get_post_meta($class->ID, '_icp_assigned_books', true);
            if (is_array($class_books)) {
                $allowed_books = array_merge($allowed_books, $class_books);
            }
        }
        $allowed_books = array_unique($allowed_books);

        if (!in_array($document_id, $allowed_books) && !current_user_can('manage_options')) {
             return '<p>You do not have permission to view this book.</p>';
        }

        $pdf_url = get_post_meta($document_id, '_icp_pdf_url', true);
        if (empty($pdf_url)) {
            return '<p>Error: PDF not found for this book.</p>';
        }
        $pdf_url = esc_url($pdf_url);

        $initial_page = (int) get_user_meta($user_id_to_check, 'icp_last_page_' . $document_id, true) ?: 1;
        $initial_color = get_user_meta($user_id_to_check, 'icp_last_color', true) ?: '#FF0000';
        $initial_color = sanitize_hex_color($initial_color);

        $container_attrs = 'data-pdf-url="' . esc_url($pdf_url) . '" ' .
                           'data-document-id="' . esc_attr($document_id) . '" ' .
                           'data-initial-page="' . esc_attr($initial_page) . '"';

        if ($is_admin_view && $this->admin_view_read_only) {
            $container_attrs .= ' data-view-as-student-id="' . esc_attr($this->admin_view_student_id) . '"';
            $container_attrs .= ' data-read-only="true"';
            $admin_nonce = wp_create_nonce('icp_view_student_annotations_' . $this->admin_view_student_id);
            $container_attrs .= ' data-admin-nonce="' . esc_attr($admin_nonce) . '"';
        }

        ob_start();
        ?>
        <div id="interactive-curriculum-container" <?php echo $container_attrs; ?>>
            <div id="pdf-viewer-toolbar">
                <div class="toolbar-group">
                    <button id="sidebar-toggle-btn" class="toolbar-btn" title="Toggle Thumbnails">
                        <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"></path></svg>
                    </button>
                </div>
                <div class="toolbar-group">
                    <button id="prev-page-btn" class="toolbar-btn" title="Previous Page">
                        <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"></path></svg>
                    </button>
                    <div class="page-info">
                        <input type="number" id="page-input" class="toolbar-input" min="1">
                        <span>/</span>
                        <span id="page-count">0</span>
                    </div>
                    <button id="next-page-btn" class="toolbar-btn" title="Next Page">
                        <svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"></path></svg>
                    </button>
                </div>

                <div class="toolbar-group">
                    <button id="zoom-out-btn" class="toolbar-btn" title="Zoom Out">
                        <svg viewBox="0 0 24 24"><path d="M19 13H5v-2h14v2z"></path></svg>
                    </button>
                    <button id="zoom-in-btn" class="toolbar-btn" title="Zoom In">
                        <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"></path></svg>
                    </button>
                    <button id="fullscreen-btn" class="toolbar-btn" title="Toggle Fullscreen">
                        <svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"></path></svg>
                    </button>
                </div>

                <div class="toolbar-group">
                    <button id="select-tool-btn" class="toolbar-btn" title="Select Tool">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M168,132.69,214.08,115l.33-.13A16,16,0,0,0,213,85.07L52.92,32.8A15.95,15.95,0,0,0,32.8,52.92L85.07,213a15.82,15.82,0,0,0,14.41,11l.78,0a15.84,15.84,0,0,0,14.61-9.59l.13-.33L132.69,168,184,219.31a16,16,0,0,0,22.63,0l12.68-12.68a16,16,0,0,0,0-22.63ZM195.31,208,144,156.69a16,16,0,0,0-26,4.93c0,.11-.09.22-.13.32l-17.65,46L48,48l159.85,52.2-45.95,17.64-.32.13a16,16,0,0,0-4.93,26h0L208,195.31Z"></path></svg>
                    </button>
                    <button id="pan-tool-btn" class="toolbar-btn active-tool" title="Pan Tool (V)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M188,48a27.75,27.75,0,0,0-12,2.71V44a28,28,0,0,0-54.65-8.6A28,28,0,0,0,80,60v64l-3.82-6.13a28,28,0,0,0-48.6,27.82c16,33.77,28.93,57.72,43.72,72.69C86.24,233.54,103.2,240,128,240a88.1,88.1,0,0,0,88-88V76A28,28,0,0,0,188,48Zm12,104a72.08,72.08,0,0,1-72,72c-20.38,0-33.51-4.88-45.33-16.85C69.44,193.74,57.26,171,41.9,138.58a6.36,6.36,0,0,0-.3-.58,12,12,0,0,1,20.79-12,1.76,1.76,0,0,0,.14.23l18.67,30A8,8,0,0,0,96,152V60a12,12,0,0,1,24,0v60a8,8,0,0,0,16,0V44a12,12,0,0,1,24,0v76a8,8,0,0,0,16,0V76a12,12,0,0,1,24,0Z"></path></svg>
                    </button>
                    <button id="freehand-tool-btn" class="toolbar-btn" title="Freehand Tool">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M227.32,73.37,182.63,28.69a16,16,0,0,0-22.63,0L36.69,152A15.86,15.86,0,0,0,32,163.31V208a16,16,0,0,0,16,16H92.69A15.86,15.86,0,0,0,104,219.31l83.67-83.66,3.48,13.9-36.8,36.79a8,8,0,0,0,11.31,11.32l40-40a8,8,0,0,0,2.11-7.6l-6.9-27.61L227.32,96A16,16,0,0,0,227.32,73.37ZM48,179.31,76.69,208H48Zm48,25.38L51.31,160,136,75.31,180.69,120Zm96-96L147.32,64l24-24L216,84.69Z"></path></svg>
                    </button>
                    <button id="highlight-tool-btn" class="toolbar-btn" title="Highlight Tool">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M253.66,106.34a8,8,0,0,0-11.32,0L192,156.69,107.31,72l50.35-50.34a8,8,0,1,0-11.32-11.32L96,60.69A16,16,0,0,0,93.18,79.5L72,100.69a16,16,0,0,0,0,22.62L76.69,128,18.34,186.34a8,8,0,0,0,3.13,13.25l72,24A7.88,7.88,0,0,0,96,224a8,8,0,0,0,5.66-2.34L136,187.31l4.69,4.69a16,16,0,0,0,22.62,0l21.19-21.18A16,16,0,0,0,203.31,168l50.35-50.34A8,8,0,0,0,253.66,106.34ZM93.84,206.85l-55-18.35L88,139.31,124.69,176ZM152,180.69,83.31,112,104,91.31,172.69,160Z"></path></svg>
                    </button>
                    <button id="text-note-tool-btn" class="toolbar-btn" title="Text Note">
                         <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M208,56V88a8,8,0,0,1-16,0V64H136V192h24a8,8,0,0,1,0,16H96a8,8,0,0,1,0-16h24V64H64V88a8,8,0,0,1-16,0V56a8,8,0,0,1,8-8H200A8,8,0,0,1,208,56Z"></path></svg>
                    </button>
                </div>

                <div class="toolbar-group">
                    <div class="color-picker-wrapper">
                         <input type="color" id="color-picker" value="<?php echo esc_attr($initial_color); ?>" title="Annotation Color">
                    </div>
                    <input type="range" id="size-slider" min="1" max="50" value="5" title="Brush Size">
                </div>

                <div class="toolbar-group">
                    <button id="undo-btn" class="toolbar-btn" title="Undo" disabled>
                        <svg viewBox="0 0 24 24"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C20.36 11.23 16.72 8 12.5 8z"></path></svg>
                    </button>
                    <button id="redo-btn" class="toolbar-btn" title="Redo" disabled>
                        <svg viewBox="0 0 24 24"><path d="M18.4 10.6C16.55 8.99 14.15 8 11.5 8c-4.22 0-7.86 3.23-9.13 7.5l-2.37-.78C1.45 10.31 5.94 6 11.5 6c2.65 0 5.05.99 6.9 2.6L22 5v9h-9l3.4-3.4z"></path></svg>
                    </button>
                    <button id="clear-page-btn" class="toolbar-btn" title="Clear Page">
                        <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"></path></svg>
                    </button>
                </div>
                <span id="save-status"></span>
            </div>
            <div id="pdf-viewer-main">
                <div id="pdf-sidebar">
                    <div id="thumbnail-view">
                    </div>
                </div>
                <div id="pdf-scroll-container">
                    <div id="page-container">
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    public function render_student_dashboard_shortcode() {
        // Enqueue the dashboard CSS right here to ensure it only loads when the shortcode is used.
        wp_enqueue_style(
            'icp-dashboard-style',
            plugins_url('icp-dashboard.css', __FILE__),
            array(),
            file_exists(plugin_dir_path(__FILE__) . 'icp-dashboard.css') ? filemtime(plugin_dir_path(__FILE__) . 'icp-dashboard.css') : false
        );

        // Enqueue the dashboard JavaScript
        wp_enqueue_script(
            'icp-dashboard-script',
            plugins_url('icp-dashboard.js', __FILE__),
            array(),
            file_exists(plugin_dir_path(__FILE__) . 'icp-dashboard.js') ? filemtime(plugin_dir_path(__FILE__) . 'icp-dashboard.js') : false,
            true
        );

        if (!is_user_logged_in()) {
            return '<p class="icp-login-prompt">' . __('Please log in to view your dashboard.', 'interactive-curriculum') . '</p>';
        }

        $user = wp_get_current_user();
        if (!in_array('student', (array) $user->roles)) {
            return '<p class="icp-error">' . __('This dashboard is for students only.', 'interactive-curriculum') . '</p>';
        }

        $student_id = $user->ID;
        // Updated student name logic
        $first_name = $user->first_name;
        $last_name = $user->last_name;
        if (!empty($first_name) && !empty($last_name)) {
            $student_name = $first_name . ' ' . $last_name;
        } else {
            $student_name = $user->display_name;
        }


        // Find all classes the student is enrolled in
        $enrolled_classes = get_posts(array(
            'post_type' => 'icp_class',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key'     => '_icp_enrolled_students',
                    'value'   => '"' . strval($student_id) . '"',
                    'compare' => 'LIKE',
                ),
            )
        ));

        if (empty($enrolled_classes)) {
            return '<p>' . __('You are not currently enrolled in any classes.', 'interactive-curriculum') . '</p>';
        }

        $class_names = wp_list_pluck($enrolled_classes, 'post_title');
        $all_assigned_book_ids = array();

        foreach ($enrolled_classes as $class) {
            $book_ids = get_post_meta($class->ID, '_icp_assigned_books', true);
            if (is_array($book_ids) && !empty($book_ids)) {
                $all_assigned_book_ids = array_merge($all_assigned_book_ids, $book_ids);
            }
        }

        $unique_book_ids = array_unique($all_assigned_book_ids);

        ob_start();
        ?>
        <div id="icp-dashboard-container">
            <header class="dashboard-header">
                <div class="dashboard-user-info">
                    <h2><?php echo esc_html($student_name); ?></h2>
                    <p><?php echo esc_html(implode(', ', $class_names)); ?></p>
                </div>
                <div class="dashboard-actions">
                    <div class="view-switcher">
                        <button id="icp-grid-view-btn" class="view-btn active" title="<?php _e('Grid View', 'interactive-curriculum'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M4 4h6v6H4zm8 0h6v6h-6zM4 14h6v6H4zm8 0h6v6h-6z"/></svg>
                        </button>
                        <button id="icp-list-view-btn" class="view-btn" title="<?php _e('List View', 'interactive-curriculum'); ?>">
                             <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
                        </button>
                    </div>
                    <a href="<?php echo wp_logout_url(get_permalink()); ?>" class="logout-button"><?php _e('Logout', 'interactive-curriculum'); ?></a>
                </div>
            </header>

            <main class="dashboard-book-list">
                <?php if (empty($unique_book_ids)) : ?>
                    <p><?php _e('No books have been assigned to your class(es) yet.', 'interactive-curriculum'); ?></p>
                <?php else : ?>
                    <ul>
                        <?php
                        foreach ($unique_book_ids as $book_id) {
                            $book_title = get_the_title($book_id);
                            $book_url = get_permalink($book_id);
                            $book_cover_url = get_the_post_thumbnail_url($book_id, 'medium');
                            $total_pages = get_post_meta($book_id, '_icp_total_pages', true) ?: 1; // Default to 1 to avoid division by zero
                            $last_read = get_user_meta($student_id, 'icp_last_page_' . $book_id, true) ?: 1;

                            $progress_percentage = ($last_read / $total_pages) * 100;
                            if ($progress_percentage > 100) $progress_percentage = 100;

                            ?>
                            <li class="book-item">
                                <a href="<?php echo esc_url($book_url); ?>">
                                    <div class="book-cover">
                                        <?php if ($book_cover_url) : ?>
                                            <img src="<?php echo esc_url($book_cover_url); ?>" alt="<?php echo esc_attr($book_title); ?>" />
                                        <?php else : ?>
                                            <div class="book-cover-placeholder">
                                                <span><?php echo esc_html($book_title); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="book-info">
                                        <h3 class="book-title"><?php echo esc_html($book_title); ?></h3>
                                        <div class="book-progress">
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill" style="width: <?php echo esc_attr($progress_percentage); ?>%;"></div>
                                            </div>
                                            <span class="progress-label">
                                                <?php
                                                printf(
                                                    __('Page %1$d of %2$d (%3$d%%)', 'interactive-curriculum'),
                                                    esc_html($last_read),
                                                    esc_html($total_pages),
                                                    round($progress_percentage)
                                                );
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                <?php endif; ?>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }
}

$interactive_curriculum_plugin = new Interactive_Curriculum_Plugin();