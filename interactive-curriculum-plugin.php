<?php
/**
 * Plugin Name: Interactive Student Curriculum
 * Plugin URI: https://example.com/interactive-curriculum-plugin
 * Description: Enables interactive PDF viewing and annotation for students.
 * Version: 3.0.13
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPLv2 or later
 * Text Domain: interactive-curriculum
 *
 * == Changelog ==
 * = 3.0.13 =
 * * UX FEAT: Redesigned toolbar to be vertical on the left side for mobile/tablet devices for improved usability.
 *
 * = 3.0.12 =
 * * FIX: Corrected visibility of floating zoom buttons to only appear on smaller screens.
 * * FIX: Reinstated logic to prevent accidental selections after a two-finger pan gesture.
 *
 * = 3.0.11 =
 * * UX FEAT: Replaced pinch-to-zoom on touch devices with floating zoom-in/out buttons for a more controlled experience.
 *
 * = 3.0.10 =
 * * FIX: Resolved conflict between one-finger and two-finger touch gestures, preventing unwanted scrolling during pinch-to-zoom.
 *
 * = 3.0.9 =
 * * FIX: Prevented accidental object selection on touch devices after a two-finger pinch-to-zoom gesture.
 *
 * = 3.0.8 =
 * * UX FEAT: Changed the default tool from "Select" to "Pan" for more intuitive initial navigation.
 *
 * = 3.0.7 =
 * * UX FEAT: Major overhaul of touch controls for a more intuitive mobile experience.
 * * UX FEAT: One-finger touch now intelligently scrolls the document when a navigation tool (Select, Pan) is active, and draws when an annotation tool is active.
 * * UX FEAT: Two-finger gestures (pinch-to-zoom, pan) are now always available, regardless of the selected tool.
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

    if ($data) {
        return new WP_REST_Response(json_decode($data), 200);
    } else {
        return new WP_REST_Response(array(), 200);
    }
}

class Interactive_Curriculum_Plugin {

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts_and_styles'));
        add_shortcode('interactive_curriculum_pdf', array($this, 'render_pdf_viewer_shortcode'));
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
            'pdf_url' => '',
            'document_id' => ''
        ), $atts, 'interactive_curriculum_pdf');

        if (!is_user_logged_in()) {
            return '<p>Please log in to view this document.</p>';
        }

        $pdf_url = esc_url($atts['pdf_url']);
        $document_id = sanitize_text_field($atts['document_id']);
        
        if (empty($pdf_url) || empty($document_id)) {
            return '<p>Error: Missing PDF URL or Document ID in shortcode.</p>';
        }

        $user_id = get_current_user_id();
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
                        <svg viewBox="0 0 24 24"><path d="M13.5,4.2C13.5,4.2,13.5,4.2,13.5,4.2l-9.3,15.3h8.3l2,4.5l3.5-21.9h-4.5V4.2z"/></svg>
                    </button>
                    <button id="pan-tool-btn" class="toolbar-btn active-tool" title="Pan Tool (V)">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.92 2.89a2.17 2.17 0 00-2.17-2.17A2.17 2.17 0 008.58.72a2.17 2.17 0 00-2.17 2.17v.21c0 .59.24 1.15.65 1.56l.76.76c.41.41.97.65 1.56.65h.42c.59 0 1.15-.24 1.56-.65l.76-.76c.41-.41.65-.97.65-1.56v-.21zM23 11.5c0-1.4-1.12-2.5-2.5-2.5a2.45 2.45 0 00-1.63.63L15 13.5v-1.75a2.25 2.25 0 00-2.25-2.25A2.25 2.25 0 0010.5 11.75V18c0 .83.67 1.5 1.5 1.5h6.75c.83 0 1.5-.67 1.5-1.5a1.5 1.5 0 00-1.5-1.5H18v-4.1l3.38-3.37c.37-.38.62-.89.62-1.43z"></path></svg>
                    </button>
                    <button id="freehand-tool-btn" class="toolbar-btn" title="Freehand Tool">
                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"></path></svg>
                    </button>
                    <button id="highlight-tool-btn" class="toolbar-btn" title="Highlight Tool">
                        <svg viewBox="0 0 24 24"><path d="m18 4-3.5 3.5 5 5L23 9l-5-5zM4 20l11-11 5 5-11 11H4v-5z"></path></svg>
                    </button>
                    <button id="text-note-tool-btn" class="toolbar-btn" title="Text Note">
                         <svg viewBox="0 0 24 24"><path d="M5 4v3h5.5v12h3V7H19V4z"></path></svg>
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
            <div id="floating-zoom-controls">
                <button id="floating-zoom-in-btn" class="floating-zoom-btn">+</button>
                <button id="floating-zoom-out-btn" class="floating-zoom-btn">-</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

$interactive_curriculum_plugin = new Interactive_Curriculum_Plugin();
