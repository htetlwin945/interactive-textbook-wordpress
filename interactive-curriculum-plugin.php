<?php
/**
 * Plugin Name: Interactive Student Curriculum
 * Plugin URI: https://example.com/interactive-curriculum-plugin
 * Description: Enables interactive PDF viewing and annotation for students.
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPLv2 or later
 * Text Domain: interactive-curriculum
 *
 * == Changelog ==
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

    if ($existing) {
        $wpdb->update(
            $table_name,
            array('annotations_data' => $annotations_data),
            array('user_id' => $user_id, 'document_id' => $document_id, 'page_num' => $page_num),
            array('%s'),
            array('%d', '%s', '%d')
        );
    } else {
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'document_id' => $document_id,
                'page_num' => $page_num,
                'annotations_data' => $annotations_data,
            ),
            array('%d', '%s', '%d', '%s')
        );
    }

    return new WP_REST_Response(array('status' => 'success', 'message' => 'Annotations saved.'), 200);
}

function interactive_curriculum_get_annotations(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $document_id = sanitize_text_field($request->get_param('document_id'));
    $page_num = (int)$request->get_param('page_num');

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
            'supports'    => array('title', 'editor'),
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

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts_and_styles'));
        add_shortcode('interactive_curriculum_pdf', array($this, 'render_pdf_viewer_shortcode'));
        add_action('add_meta_boxes', array($this, 'add_custom_meta_boxes'));
        add_action('save_post_icp_book', array($this, 'save_book_meta_data'));
        add_action('save_post_icp_class', array($this, 'save_class_meta_data'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp', array($this, 'litespeed_compatibility'));
    }

    /**
     * Adds compatibility with the LiteSpeed Cache plugin.
     * Prevents caching on pages where the interactive viewer is active.
     */
    public function litespeed_compatibility() {
        global $post;
        // Check if we are on a singular page and the post content has our shortcode
        if (is_singular() && is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'interactive_curriculum_pdf')) {
            // Tell LiteSpeed not to cache this page
            if (function_exists('litespeed_do_not_cache')) {
                litespeed_do_not_cache('Interactive curriculum page detected');
            }
        }
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
        ?>
        <p>
            <label for="icp_pdf_url"><?php _e('PDF URL', 'interactive-curriculum'); ?></label>
            <input type="text" id="icp_pdf_url" name="icp_pdf_url" value="<?php echo esc_url($pdf_url); ?>" style="width: 100%;" readonly />
        </p>
        <p>
            <button type="button" id="icp_upload_pdf_button" class="button"><?php _e('Upload or Select PDF', 'interactive-curriculum'); ?></button>
        </p>
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
        global $post;
        if ($hook == 'post-new.php' || $hook == 'post.php') {
            if (isset($post->post_type) && ($post->post_type === 'icp_book' || $post->post_type === 'icp_class')) {
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

    public function enqueue_scripts_and_styles() {
        if (!is_singular()) {
            return;
        }

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
        $atts = shortcode_atts(array(
            'document_id' => ''
        ), $atts, 'interactive_curriculum_pdf');

        if (!is_user_logged_in()) {
            return '<p>Please log in to view this book.</p>';
        }

        $user_id = get_current_user_id();
        $document_id = intval($atts['document_id']);

        if (empty($document_id) || get_post_type($document_id) !== 'icp_book') {
            return '<p>Error: Invalid Book ID.</p>';
        }

        // Permission Check
        $allowed_books = array();
        $student_classes = get_posts(array(
            'post_type' => 'icp_class',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key'     => '_icp_enrolled_students',
                    'value'   => '"' . strval($user_id) . '"',
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

        $initial_page = (int) get_user_meta($user_id, 'icp_last_page_' . $document_id, true) ?: 1;

        $initial_color = get_user_meta($user_id, 'icp_last_color', true) ?: '#FF0000';
        $initial_color = sanitize_hex_color($initial_color);

        ob_start();
        ?>
        <div id="interactive-curriculum-container" data-pdf-url="<?php echo $pdf_url; ?>" data-document-id="<?php echo $document_id; ?>" data-initial-page="<?php echo $initial_page; ?>">
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
}

$interactive_curriculum_plugin = new Interactive_Curriculum_Plugin();