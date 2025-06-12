// PASTE THIS ENTIRE CODE BLOCK INTO aura-sync-plugin.php ON GITHUB
<?php
/**
 * Plugin Name:       Aura Sync Plugin
 * Description:       Manages Activities, Guides, and syncs data with the Aura app's Firebase database.
 * Version:           3.0.0 (Production Ready)
 * Author:            Aura Development Team
 * Author URI:        https://yourauraapp.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aura-sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\FirebaseException;

class Aura_Sync_Plugin {

    private $firestore;

    public function __construct() {
        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'init', [ $this, 'register_taxonomies' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'save_post_activity', [ $this, 'save_and_sync_activity' ], 10, 2 );
        add_action( 'delete_post', [ $this, 'delete_activity_from_firebase' ], 10, 1 );
    }
    
    public function save_and_sync_activity( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        
        if ( empty(get_post_meta($post_id, '_aura_activity_code', true)) ) {
            update_post_meta($post_id, '_aura_activity_code', 'AURA-' . $post_id);
        }
        $fields_to_save = [
            'aura_activity_date' => '_aura_activity_date',
            'aura_activity_time' => '_aura_activity_time',
            'aura_assigned_guide_id' => '_aura_assigned_guide_id',
            'aura_guide_title' => '_aura_guide_title',
            'aura_activity_document_ids' => '_aura_activity_document_ids'
        ];
        foreach ($fields_to_save as $post_key => $meta_key) {
            if (isset($_POST[$post_key])) {
                update_post_meta($post_id, $meta_key, sanitize_text_field($_POST[$post_key]));
            }
        }
        
        $firestore = $this->initialize_firebase();
        if (!$firestore) return;

        $activity_code = get_post_meta($post_id, '_aura_activity_code', true);
        $guide_id = get_post_meta($post_id, '_aura_assigned_guide_id', true);
        $guide_info = $guide_id ? ['name' => get_the_title($guide_id), 'title' => get_post_meta($post_id, '_aura_guide_title', true)] : null;
        $company_terms = get_the_terms($post_id, 'company');
        $company_name = !is_wp_error($company_terms) && !empty($company_terms) ? $company_terms[0]->name : '';
        $document_ids_str = get_post_meta($post_id, '_aura_activity_document_ids', true);
        $documents = [];
        if (!empty($document_ids_str)) { $ids = explode(',', $document_ids_str); foreach($ids as $id) { if (empty(trim($id))) continue; $documents[] = ['name' => basename(get_attached_file($id)), 'url' => wp_get_attachment_url($id)]; } }
        $date_string = get_post_meta($post_id, '_aura_activity_date', true);
        $time_string = get_post_meta($post_id, '_aura_activity_time', true);
        $full_date = $time_string ? $date_string . ' at ' . $time_string : $date_string;

        $data_to_sync = [
            'name'          => $post->post_title,
            'id'            => $post->post_name,
            'description'   => $post->post_content,
            'date'          => $full_date,
            'company'       => $company_name,
            'guide'         => $guide_info,
            'documents'     => $documents
        ];

        try {
            $firestore->collection('activities')->document($activity_code)->set($data_to_sync);
        } catch (FirebaseException | Exception $e) {
            error_log('Firebase Sync Error for post ' . $post_id . ': ' . $e->getMessage());
        }
    }

    private function initialize_firebase() {
        if (!class_exists('Kreait\Firebase\Factory')) {
            error_log('Firebase SDK not found. Please run "composer install".');
            return null;
        }
        if ($this->firestore) return $this->firestore;
        try {
            $serviceAccountPath = __DIR__ . '/config/firebase-service-account.json';
            if (!file_exists($serviceAccountPath)) {
                error_log('Firebase Service Account key not found at: ' . $serviceAccountPath);
                return null;
            }
            $factory = (new Factory)->withServiceAccount($serviceAccountPath);
            $this->firestore = $factory->createFirestore();
            return $this->firestore;
        } catch (FirebaseException | Exception $e) {
            error_log('Firebase Init Error: ' . $e->getMessage());
            return null;
        }
    }

    public function delete_activity_from_firebase($post_id) {
        if (get_post_type($post_id) !== 'activity') return;
        $firestore = $this->initialize_firebase();
        if (!$firestore) return;
        $activity_code = get_post_meta($post_id, '_aura_activity_code', true);
        if (empty($activity_code)) return;
        try {
            $firestore->collection('activities')->document($activity_code)->delete();
        } catch (FirebaseException | Exception $e) {
            error_log('Firebase Delete Error for post ' . $post_id . ': ' . $e->getMessage());
        }
    }
    
    public function enqueue_admin_scripts( $hook ) { if ( 'post.php' != $hook && 'post-new.php' != $hook ) { return; } if ( 'activity' !== get_post_type() ) { return; } wp_enqueue_script('jquery-ui-datepicker'); wp_enqueue_style('jquery-ui-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css', true); wp_enqueue_media(); wp_enqueue_script('aura-admin-scripts', plugin_dir_url( __FILE__ ) . 'js/admin-scripts.js', ['jquery', 'jquery-ui-datepicker'], '1.1.0', true); }
    public function register_post_types() { $activity_labels = [ 'name' => 'Activities', 'singular_name' => 'Activity' ]; $activity_args = [ 'label' => __( 'Activity', 'aura-sync' ), 'description' => __( 'Tour activities for the Aura app.', 'aura-sync' ), 'labels' => $activity_labels, 'supports' => [ 'title', 'editor', 'thumbnail' ], 'hierarchical' => false, 'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'menu_position' => 5, 'menu_icon' => 'dashicons-calendar-alt', 'show_in_admin_bar' => true, 'show_in_nav_menus' => true, 'can_export' => true, 'has_archive' => true, 'exclude_from_search' => false, 'publicly_queryable' => true, 'capability_type' => 'post', 'show_in_rest' => true, ]; register_post_type( 'activity', $activity_args ); $guide_labels = [ 'name' => 'Guides', 'singular_name' => 'Guide' ]; $guide_args = [ 'label' => __( 'Guide', 'aura-sync' ), 'description' => __( 'Tour guide profiles.', 'aura-sync' ), 'labels' => $guide_labels, 'supports' => [ 'title', 'thumbnail' ], 'hierarchical' => false, 'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_position' => 6, 'menu_icon' => 'dashicons-businessperson', 'can_export' => true, 'exclude_from_search' => true, 'publicly_queryable' => false, 'capability_type' => 'post', 'show_in_rest' => true, ]; register_post_type( 'guide', $guide_args ); }
    public function register_taxonomies() { $company_labels = [ 'name' => 'Companies', 'singular_name' => 'Company' ]; $company_args = [ 'hierarchical' => true, 'labels' => $company_labels, 'show_ui' => true, 'show_admin_column' => true, 'query_var' => true, 'rewrite' => [ 'slug' => 'company' ], 'show_in_rest' => true, ]; register_taxonomy( 'company', [ 'activity', 'guide' ], $company_args ); }
    public function register_meta_boxes() { add_meta_box('aura_activity_details_meta_box', 'Activity Details', [ $this, 'render_activity_meta_box' ], 'activity', 'normal', 'high'); }
    public function render_activity_meta_box( $post ) { wp_nonce_field( 'aura_save_activity_details', 'aura_activity_details_nonce' ); $activity_date = get_post_meta( $post->ID, '_aura_activity_date', true ); $activity_time = get_post_meta( $post->ID, '_aura_activity_time', true ); $activity_code = get_post_meta( $post->ID, '_aura_activity_code', true ); $assigned_guide_id = get_post_meta( $post->ID, '_aura_assigned_guide_id', true ); $guide_title = get_post_meta( $post->ID, '_aura_guide_title', true ); $document_ids = get_post_meta( $post->ID, '_aura_activity_document_ids', true ); ?> <style>.document-item{padding: 8px; border: 1px solid #ddd; margin-bottom: 5px; background: #f9f9f9; display:flex; justify-content:space-between; align-items:center;} .document-item .dashicons-no-alt{color: #a00; cursor: pointer;}</style> <table class="form-table"> <tbody> <tr> <th><label for="aura_activity_date"><?php _e( 'Activity Date', 'aura-sync' ); ?></label></th> <td><input type="text" id="aura_activity_date" name="aura_activity_date" value="<?php echo esc_attr( $activity_date ); ?>" class="regular-text" placeholder="Click to select date" autocomplete="off" /></td> </tr> <tr> <th><label for="aura_activity_time"><?php _e( 'Activity Time', 'aura-sync' ); ?></label></th> <td><input type="text" id="aura_activity_time" name="aura_activity_time" value="<?php echo esc_attr( $activity_time ); ?>" class="regular-text" placeholder="e.g., 9:00 AM" /></td> </tr> <tr> <th><label for="aura_activity_code"><?php _e( 'Activity Code', 'aura-sync' ); ?></label></th> <td><input type="text" value="<?php echo esc_attr( $activity_code ); ?>" class="regular-text" readonly /></td> </tr> <tr> <th><label for="aura_assigned_guide_id"><?php _e( 'Assign Guide', 'aura-sync' ); ?></label></th> <td> <select name="aura_assigned_guide_id" id="aura_assigned_guide_id" class="regular-text"> <option value=""><?php _e( '-- Select a Guide --', 'aura-sync' ); ?></option> <?php $guides_query = new WP_Query(['post_type' => 'guide', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']); if ( $guides_query->have_posts() ) { while ( $guides_query->have_posts() ) { $guides_query->the_post(); echo '<option value="' . esc_attr( get_the_ID() ) . '" ' . selected( $assigned_guide_id, get_the_ID(), false ) . '>' . esc_html( get_the_title() ) . '</option>'; } wp_reset_postdata(); } ?> </select> </td> </tr> <tr> <th><label for="aura_guide_title"><?php _e( 'Guide Title (for this activity)', 'aura-sync' ); ?></label></th> <td><input type="text" id="aura_guide_title" name="aura_guide_title" value="<?php echo esc_attr( $guide_title ); ?>" class="regular-text" placeholder="Lead Kayak Instructor" /></td> </tr> <tr> <th><?php _e( 'Documents', 'aura-sync' ); ?></th> <td> <div id="aura-documents-list"> <?php if ( $document_ids ) { $ids = explode(',', $document_ids); foreach ($ids as $id) { if(empty(trim($id))) continue; $filename = basename( get_attached_file( $id ) ); echo '<div class="document-item" data-id="' . esc_attr($id) . '"><span class="dashicons dashicons-media-default"></span> <span>' . esc_html($filename) . '</span><a href="#" class="remove-document"><span class="dashicons dashicons-no-alt"></span></a></div>'; } } ?> </div> <input type="hidden" id="aura_activity_document_ids" name="aura_activity_document_ids" value="<?php echo esc_attr( $document_ids ); ?>"> <button type="button" id="add_aura_document" class="button"><?php _e( 'Add Document', 'aura-sync' ); ?></button> <p class="description"><?php _e( 'Upload or select documents like tickets and waivers.', 'aura-sync' ); ?></p> </td> </tr> </tbody> </table> <?php }
}

new Aura_Sync_Plugin();
