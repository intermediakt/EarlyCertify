<?php
/**
 * Plugin Name: EarlyCertify
 * Plugin URI: https://github.com/intermediakt/EarlyCertify
 * Description: Allows users to earn certificates after completing first lesson, last lesson, and defined ammount of middle lessons (default: 4)
 * Version: 1.0.0
 * Author: Charalambos Rentoumis
 * Author URI: https://github.com/5skr0ll3r
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: CC-BY-SA-4.0
 * Text Domain: sensei-custom-cert
 */

if (!defined('ABSPATH')) {
    exit;
}


class Sensei_Custom_Certificate_Requirements{
    const VERSION = '1.0.0';
    
    private static $instance = null;
        
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }

    public static function activate(){
        $required_middle_lessons = get_option('required_middle_lessons');

        if(!$required_middle_lessons){
            add_option('required_middle_lessons', 4);
        }

        flush_rewrite_rules();
    }

    public static function deactivate(){
        flush_rewrite_rules();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(){
        // Handle post from admin settings page
        add_action('admin_post_update_cert_requirements', array($this, 'handle_update_settings'));

        // Hook into Sensei's course completion check
        add_filter('sensei_user_course_status_passed', array($this, 'custom_course_completion_check'), 10, 2);
        
        // Filter to modify whether user can view certificate
        add_filter('sensei_certificate_can_user_view', array($this, 'can_user_view_certificate'), 10, 3);
        
        // Modify the course results data
        add_filter('sensei_course_results_data', array($this, 'modify_course_results'), 10, 3);


        // Add view certificate button in last cource
        add_action('sensei_single_lesson_content_inside_after', array($this, 'display_certificate_button_on_lesson'), 10);
        add_action('wp_footer', array($this, 'display_certificate_button_lesson_footer'), 10);
        
        // Force certificate view permission
        add_filter('sensei_certificates_can_user_view_certificate', array($this, 'allow_certificate_view'), 10, 3);
        
        // Add sub menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Trigger certificate generation
        add_action('wp', array($this, 'maybe_generate_certificates'), 20);
    }

    /**
     * Handles update post request in admin menu
     * and updates option in database
     * 
     * @param _wpnonce Nonce to protect against CSRF 
     * @param int data middle lessons count required for cource completion
     **/
    public function handle_update_settings(){
        if (
            ! isset($_POST['_wpnonce']) ||
            ! wp_verify_nonce($_POST['_wpnonce'], 'update_cert_req_non')
        ) {
            wp_safe_redirect(admin_url('admin.php?page=sensei-custom-cert&updated=0&error=1'));
            exit;
        }

        if ( ! isset($_POST['data']) ) {
            wp_safe_redirect(admin_url('admin.php?page=sensei-custom-cert&updated=0&error=2'));
            exit;
        }

        $data_raw = trim($_POST['data']);
        if ( ! ctype_digit($data_raw) ) {
            wp_safe_redirect(admin_url('admin.php?page=sensei-custom-cert&updated=0&error=3'));
            exit;
        }

        $data = (int)$data_raw;
        update_option('required_middle_lessons', $data);

        wp_safe_redirect(admin_url('admin.php?page=sensei-custom-cert&updated=1'));
        exit;
    }

    /**
     * Issues certificate ID
     * 
     * @param int $user_id ID for user that completed the cource
     * @param int $cource_id Course that was completed by the user
     * @return void
     **/
    public function generate_certificate_number( $user_id = 0, $course_id = 0 ) {

        if ( ! $user_id || ! $course_id || ! is_numeric( $user_id ) || ! is_numeric( $course_id ) ) {
            return;
        }
        $user_id   = absint( $user_id );
        $course_id = absint( $course_id );
        if ( false === get_user_by( 'id', $user_id ) ) {
            return;
        }

        if ( null === get_post( $course_id ) ) {
            return;
        }

        $certificate_id = $this->insert( $user_id, $course_id );
        error_log("CertificateId: $certificate_id");
        if ( ! is_wp_error( $certificate_id ) ) {

            $data = array(
                'post_id' => absint( $certificate_id ),
                'data'    => Woothemes_Sensei_Certificates_Utils::get_certificate_hash( $course_id, $user_id ),
                'type'    => 'sensei_certificate',
                'user_id' => $user_id,
            );

            WooThemes_Sensei_Utils::sensei_log_activity( $data );
        }

    }
    /**
     * Inserts the certificate data in the database
     * 
     * @param int $user_id ID for user that completed the cource
     * @param int $cource_id Course that was completed by the user
     * @return WP_ERROR || certificate_id
     **/
    function insert( $user_id, $course_id ) {
        error_log("insert(): Inserting certificate");
        if ( ! class_exists( 'Woothemes_Sensei_Certificates_Utils' ) ) {
            include_once WP_PLUGIN_DIR . '/sensei-certificates/classes/class-woothemes-sensei-certificates-utils.php';
        }
        $certificate_hash = Woothemes_Sensei_Certificates_Utils::get_certificate_hash( $course_id, $user_id );
        $certificate_query = new WP_Query(
            array(
                'post_type'      => 'certificate',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'title'          => $certificate_hash,
            )
        );
        if ( $certificate_query->have_posts() ) {
            return new WP_Error( 'sensei_certificates_duplicate' );
        }

        $cert_args = array(
            'post_author' => intval( $user_id ),
            'post_title'  => $certificate_hash,
            'post_name'   => $certificate_hash,
            'post_type'   => 'certificate',
            'post_status' => 'publish',
        );
        $post_id   = wp_insert_post( $cert_args, $wp_error = false );

        if ( ! is_wp_error( $post_id ) ) {
            add_post_meta( $post_id, 'course_id', absint( $course_id ) );
            add_post_meta( $post_id, 'learner_id', absint( $user_id ) );
            add_post_meta( $post_id, 'certificate_hash', $certificate_hash );
        }
        return $post_id;
    }

    /**
     * Displayes the view certificate button in the footer of the 
     * last lesson inside the cource
     * 
     * @return void
     **/
    public function display_certificate_button_lesson_footer(){
        if (!is_singular('lesson') || !is_user_logged_in()) {
            return;
        }

        $lesson_id = get_the_ID();
        $user_id = get_current_user_id();
        $course_id = Sensei()->lesson->get_course_id($lesson_id);

         if(isset($_GET['get-certificate']) && $_GET['get-certificate'] == 1){
            return $this->render_certificate($user_id, $course_id);
         }

        if (!$course_id || !$this->check_custom_requirements($user_id, $course_id)) {
            return;
        }

        static $already_displayed = false;
        if ($already_displayed) {
            return;
        }
        $already_displayed = true;

        $stats = $this->get_completion_stats($user_id, $course_id);
        $lessons = $this->get_course_lessons($course_id);
        $is_last_lesson = ($lesson_id == end($lessons));

        if($is_last_lesson){
        ?>
        <script>
        jQuery(document).ready(function($) {
            var $contentArea = $('.sensei-course-theme .sensei-lesson-content, .lesson-content, .entry-content, .sensei article.lesson');
            
            if ($contentArea.length) {
                var certificateHtml = `
                    <div class="sensei-custom-certificate-lesson" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; margin: 30px 0; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); text-align: center; color: white;">
                        <div style="font-size: 48px; margin-bottom: 15px;">🎓</div>
                        <h2 style="color: white; margin: 0 0 10px 0; font-size: 28px;"><?php _e('Certificate Earned!', 'sensei-custom-cert'); ?></h2>
                        <?php if ($is_last_lesson): ?>
                        <p style="color: rgba(255,255,255,0.95); font-size: 16px; margin: 10px 0 20px 0; font-weight: 500;">
                            <?php _e('🎉 You\'ve completed the final lesson!', 'sensei-custom-cert'); ?>
                        </p>
                        <?php endif; ?>
                        <p style="color: rgba(255,255,255,0.9); font-size: 16px; margin: 0 0 20px 0;">
                            <?php _e('You have completed all requirements for your certificate!', 'sensei-custom-cert'); ?>
                        </p>
                        
                        <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; margin: 20px 0; backdrop-filter: blur(10px);">
                            <p style="margin: 5px 0; color: rgba(255,255,255,0.95); font-size: 14px;">
                                ✓ <?php _e('First Lesson Completed', 'sensei-custom-cert'); ?>
                            </p>
                            <p style="margin: 5px 0; color: rgba(255,255,255,0.95); font-size: 14px;">
                                ✓ <?php _e('Last Lesson Completed', 'sensei-custom-cert'); ?>
                            </p>
                            <p style="margin: 5px 0; color: rgba(255,255,255,0.95); font-size: 14px;">
                                ✓ <?php printf(__('%d out of %d Middle Lessons Completed', 'sensei-custom-cert'), $stats['middle_completed'], $stats['middle_total']); ?>
                            </p>
                        </div>
                        <form method="GET">
                        <button name="get-certificate" value="1"
                           class="sensei-certificate-view-button" 
                           target="_blank"
                           style="display: inline-block; background: white; color: #667eea; padding: 15px 40px; border-radius: 50px; text-decoration: none; font-weight: bold; font-size: 18px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); transition: transform 0.3s ease, box-shadow 0.3s ease; margin-bottom: 10px;">
                            📜 <?php _e('View Your Certificate', 'sensei-custom-cert'); ?>
                        </button>
                        </form>
                        
                        <div style="margin-top: 15px;">
                            <a href="<?php echo esc_url(get_permalink($course_id)); ?>" 
                               style="color: rgba(255,255,255,0.8); text-decoration: underline; font-size: 14px;">
                                ← <?php _e('Back to Course', 'sensei-custom-cert'); ?>
                            </a>
                        </div>
                    </div>
                `;
                
                $contentArea.last().append(certificateHtml);
                
                $('.sensei-certificate-view-button').hover(
                    function() {
                        $(this).css({
                            'transform': 'translateY(-2px)',
                            'box-shadow': '0 8px 20px rgba(0,0,0,0.3)'
                        });
                    },
                    function() {
                        $(this).css({
                            'transform': 'translateY(0)',
                            'box-shadow': '0 5px 15px rgba(0,0,0,0.2)'
                        });
                    }
                );
            }
        });
        </script>
        <?php
        }
    }


    /**
     * Gets the cource stats
     * Overall lessons and lessons completed by user in specific cource
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return array()
     **/
    private function get_completion_stats($user_id, $course_id) {
        $lessons = $this->get_course_lessons($course_id);
        
        if (empty($lessons)) {
            return array(
                'middle_completed' => 0,
                'middle_total' => 0
            );
        }
        
        $middle_lessons = array_slice($lessons, 1, -1);
        
        $middle_completed = 0;
        foreach ($middle_lessons as $lesson_id) {
            if ($this->is_lesson_completed($user_id, $lesson_id)) {
                $middle_completed++;
            }
        }
        
        return array(
            'middle_completed' => $middle_completed,
            'middle_total' => count($middle_lessons)
        );
    }

    /**
     * Retrieves cource lessons 
     * 
     * @param int $cource_id Course that was enrolled by the user
     * @return array()
     **/
    private function get_course_lessons($course_id) {
        if (!class_exists('Sensei_Course')) {
            return array();
        }
        
        $course_lessons = Sensei()->course->course_lessons($course_id, 'publish', 'ids');
        error_log("get_course_lessons()");
        return is_array($course_lessons) ? $course_lessons : array();
    }


    /**
     * Checks if lesson is complete
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return bool
     **/
    private function is_lesson_completed($user_id, $lesson_id) {
        if (!class_exists('Sensei_Utils')) {
            return false;
        }
        
        return Sensei_Utils::user_completed_lesson($lesson_id, $user_id);
    }

    /**
     * Checks if user has completed thee cource
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return bool
     **/
    public function custom_course_completion_check($passed, $user_id) {
        $course_id = get_the_ID();
        
        if (!$course_id || !is_singular('course')) {
            global $post;
            if (isset($post) && 'course' === $post->post_type) {
                $course_id = $post->ID;
            } else {
                return $passed;
            }
        }
        
        return $this->check_custom_requirements($user_id, $course_id);
    }

    /**
     * Checks custom requirements to see if user is allowed to view certificate
     * and changes can_view value to enable view from sensei
     * 
     * @param bool $can_view Bool provided from sensei_certificate_can_user_view filter
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return bool
     **/
    public function allow_certificate_view($can_view, $user_id, $course_id) {
        if ($this->check_custom_requirements($user_id, $course_id)) {
            return true;
        }
        return $can_view;
    }

    /**
     * Checks if custom requirements are met and if yes begins the generate certificate process
     * 
     * @return void
     **/
    public function maybe_generate_certificates() {
        error_log("maybe_generate_certificates runs");
        if (!is_singular(array('course', 'lesson'))) {
            return;
        }
        error_log("maybe_generate_certificates goes past page check");
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $course_id = null;
        
        if (is_singular('course')) {
            $course_id = get_the_ID();
        } elseif (is_singular('lesson')) {
            $lesson_id = get_the_ID();
            $course_id = Sensei()->lesson->get_course_id($lesson_id);
        }
        
        if (!$course_id) {
            return;
        }
        
        if ($this->check_custom_requirements($user_id, $course_id)) {

            $hash = $this->get_certificate_hash($user_id, $course_id);
            if(!$hash){
                error_log("maybe_generate_certificates() start generating certificate");
                $this->maybe_update_course_status($user_id, $course_id);
                $this->generate_certificate_number( $user_id, $course_id );
            }
        }
    }

    /**
     * Checks custom requirements and updates cource status if requirements are met
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return void
     **/
    private function maybe_update_course_status($user_id, $course_id) {
        if (!$this->check_custom_requirements($user_id, $course_id)) {
            return;
        }
        error_log("Updating cources status");
        
        $course_status = Sensei_Utils::user_course_status($course_id, $user_id);
        
        if (!$course_status || $course_status->comment_approved !== 'complete') {
            $activity_args = array(
                'post_id' => $course_id,
                'user_id' => $user_id,
                'type' => 'sensei_course_status',
                'status' => 'complete'
            );
            
            $existing_status = Sensei_Utils::sensei_check_for_activity(
                array(
                    'post_id' => $course_id,
                    'user_id' => $user_id,
                    'type' => 'sensei_course_status'
                )
            );
            
            if (!$existing_status) {
                Sensei_Utils::sensei_log_activity(
                    $course_id,
                    'course',
                    'complete',
                    $user_id
                );
            } else {
                Sensei_Utils::update_course_status($user_id, $course_id, 'complete');
            }
        }
    }

    /**
     * Checks custom requirements to see if user is allowed to view certificatei
     * 
     * @param bool $can_view Bool provided from sensei_certificate_can_user_view filter
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return bool
     **/
    public function can_user_view_certificate($can_view, $user_id, $course_id) {
        return $this->check_custom_requirements($user_id, $course_id);
    }


    /**
     * Retrieves course certificate and redirects user to it
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return void
     **/
    private function render_certificate($user_id, $course_id) {
        $certificate_hash = $this->get_certificate_hash($user_id, $course_id);
        wp_safe_redirect(home_url('/certificate/' . $certificate_hash . '/'));
        exit;
    }

    /**
     * Retrieves certificate hash
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return string | bool
     **/
    private function get_certificate_hash($user_id, $course_id) {
        $certificate_id = $this->get_certificate_id($user_id, $course_id);
        //Title of the post is the hash dont look meta values no need
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT post_title FROM $wpdb->posts WHERE id = %d", $certificate_id
        );
        
        $hash = $wpdb->get_var( $sql );
        error_log("get_certificate_hash() $hash");
        //$hash = get_post_meta($certificate_id, 'certificate_hash', true);
        
        if (!empty($hash)) {
            return $hash;
        }
        
        return false;
    }


    /**
     * Finds certificate id
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return int
     **/
    public function get_certificate_id($user_id, $course_id){
        global $wpdb;
        //get title
        $sql = $wpdb->prepare(
            "SELECT
                p.id 
            FROM
                $wpdb->posts p
            INNER JOIN $wpdb->postmeta m ON p.id = m.post_id
            WHERE
                p.post_type = %s
                AND m.meta_key = %s
                AND m.meta_value = %d
                AND p.post_author = %d",
            'certificate', //Probably wrong
            'course_id',
            $course_id,
            $user_id
        );

        $certificate_id = $wpdb->get_var( $sql );
        return $certificate_id;
    }

    /**
     * Checks custom requirements to see if user completed the cource
     * 
     * @param int $user_id ID for user that enrolled the cource
     * @param int $cource_id Course that was enrolled by the user
     * @return bool
     **/
    private function check_custom_requirements($user_id, $course_id) {
        $lessons = $this->get_course_lessons($course_id);
        
        if (empty($lessons) || count($lessons) < 8) {
            return Sensei_Utils::user_completed_course($course_id, $user_id);
        }
        
        $first_lesson = $lessons[0];
        $last_lesson = end($lessons);
        $middle_lessons = array_slice($lessons, 1, -1);
        
        $first_completed = $this->is_lesson_completed($user_id, $first_lesson);
        
        $last_completed = $this->is_lesson_completed($user_id, $last_lesson);
        
        $middle_completed = 0;
        foreach ($middle_lessons as $lesson_id) {
            if ($this->is_lesson_completed($user_id, $lesson_id)) {
                $middle_completed++;
            }
        }
        
        $requirements_met = $first_completed && 
                           $last_completed && 
                           $middle_completed >= get_option('required_middle_lessons');
        error_log("check_custom_requirements() $requirements_met");
        return $requirements_met;
    } 

    /**
     * Adds admin menu under Sensei's menu
     **/
    public function add_admin_menu() {
        add_submenu_page(
            'sensei',
            __('Custom Certificate Requirements Settings', 'sensei-custom-cert'),
            __('Certificate Settings', 'sensei-custom-cert'),
            'manage_options',
            'sensei-custom-cert',
            array($this, 'admin_page')
        );
    }

    /**
     * Generates HTML to be displayed in admin page and handles
     * GET requests depending on the status of the POST request 
     * Sent by the form
     **/
    public function admin_page() {
        $message = "";
        if(isset($_GET['updated']) && (int)$_GET['updated'] === 1){
            $message = "Updated Successfully.";
        }
        if(isset($_GET['error']) && (int)$_GET['error'] !== 0){
            if((int)$_GET['error'] == 1){
                $message = "Nonce was wrong or not set.";
            }
            if((int)$_GET['error'] == 2){
                $message = "Input was empty.";
            }
            if((int)$_GET['error'] == 3){
                $message = "Input must be a digit.";
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Custom Certificate Requirements', 'sensei-custom-cert'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Current Requirements', 'sensei-custom-cert'); ?></h2>
                <p><?php _e('Students can earn a certificate when they complete:', 'sensei-custom-cert'); ?></p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><?php _e('The FIRST lesson of the course', 'sensei-custom-cert'); ?></li>
                    <li><?php _e('The LAST lesson of the course', 'sensei-custom-cert'); ?></li>
                    <li><?php printf(__('At least %d out of the middle lessons', 'sensei-custom-cert'), get_option('required_middle_lessons')); ?></li>
                </ul>
                
                <p><strong><?php _e('Note:', 'sensei-custom-cert'); ?></strong> <?php _e('This plugin only works with courses that have at least 8 lessons. Courses with fewer lessons will use the default Sensei completion requirements.', 'sensei-custom-cert'); ?></p>
            </div>
            
            <div class="card">
                <h2><?php _e('How It Works', 'sensei-custom-cert'); ?></h2>
                <ol>
                    <li><?php _e('The plugin identifies the first and last lessons in your course', 'sensei-custom-cert'); ?></li>
                    <li><?php _e('It counts all lessons in between as "middle lessons"', 'sensei-custom-cert'); ?></li>
                    <li><?php _e('Students must complete both the first and last lessons', 'sensei-custom-cert'); ?></li>
                    <li><?php printf(__('Students must complete at least %d middle lessons', 'sensei-custom-cert'), get_option('required_middle_lessons')); ?></li>
                    <li><?php _e('Once these requirements are met, the certificate becomes available', 'sensei-custom-cert'); ?></li>
                </ol>
            </div>
            <div class="card">
                <h2><?php _e('Set Middle Lessons Requirement'); ?></h2>
                <form method="POST" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field('update_cert_req_non'); ?>
                    <input type="hidden" name="action" value="update_cert_requirements">
                    <input type="text" name="data">
                    <button type="submit">Save</button>
                </form>
                <p><?= $message ?></p>
            </div>
        </div>
        <?php
    }
}

register_activation_hook( __FILE__, array( 'Sensei_Custom_Certificate_Requirements', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sensei_Custom_Certificate_Requirements', 'deactivate' ) );

function sensei_custom_certificate_requirements_init() {
    // Check if Sensei is active
    if (!class_exists('Sensei_Main')) {
        add_action('admin_notices', 'sensei_custom_cert_missing_sensei_notice');
        return;
    }
    
    Sensei_Custom_Certificate_Requirements::get_instance();
}
add_action('plugins_loaded', 'sensei_custom_certificate_requirements_init');