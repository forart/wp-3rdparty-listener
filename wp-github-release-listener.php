<?php
/**
 * Plugin Name: Github release listener
 * Description: Listens to a GitHub webhook and creates a new post every time a release is made.
 * Version: 0.1
 * Author:  Piiu Pilt, Silktide
 * Author URI: http://www.silktide.com
 * License: GPLv2
 * Text Domain: wp-github-release-listener
 */

defined( 'ABSPATH' ) or die( 'No!' );

add_action( 'wp_ajax_nopriv_wgrl_release_post', 'wgrl_new_release_handler' );
function wgrl_new_release_handler() {
    $raw_data = file_get_contents( 'php://input' );
    header( "Content-Type: application/json" );

    // Check secret
    $hash = hash_hmac( 'sha1', $raw_data, get_option('wgrl-webhook-secret') );
    if ( 'sha1=' . $hash != $_SERVER['HTTP_X_HUB_SIGNATURE'] ) {
        echo json_encode( [ 'success' => false, 'error' => 'Failed to validate the secret' ] );
        exit;
    }

    $data = json_decode($raw_data, true);
    $release_published = wgrl_add_post($data);
    echo json_encode( [ 'success' => true, 'release_published' => $release_published ] );
    exit;
}

function wgrl_add_post($data) {
    if ( isset($data['action']) && isset($data['release']) ) {
        global $wpdb;
        try {
            $new_post = [
                'post_title' => wp_strip_all_tags( $data['release']['tag_name'] ),
                'post_content' => $data['release']['body'],
                'post_author' => get_option('wgrl-post-author'),
                'post_status' => 'publish',
            ];
            if (get_option('wgrl-webhook-secret')) {
                $new_post['post_type'] = 'release';
            } else {
                $new_post['tax_input'] = [ 'tag' => 'release' ];
            }

            wp_insert_post( $new_post );
        } catch(Exception $e) {
            return false;
        }
        return true;
    }
    return false;
}

add_action('init', 'wgrl_add_custom_post_type');
function wgrl_add_custom_post_type() {
    if (get_option('wgrl-webhook-secret')) {
        $args = [
            'labels' => [
                'name' => 'Releases',
                'singular_name' => 'Release'
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true
        ];
        register_post_type('release', $args);
    }
}

add_action( 'admin_menu', 'wgrl_menu' );
function wgrl_menu() {
    add_options_page(
        'GitHub release listener settings',
        'GitHub release listener',
        'manage_options',
        'wgrl-options',
        'wgrl_options_page'
    );
    add_action( 'admin_init', 'wgrl_register_settings' );
}

function wgrl_register_settings() {
    register_setting( 'wgrl-options', 'wgrl-webhook-secret' );
    register_setting( 'wgrl-options', 'wgrl-post-author' );
    register_setting( 'wgrl-oprions', 'wgrl-custom-post-type');
}

function wgrl_options_page() {
    echo '<div class="wrap">
        <h2>GitHub release listener settings</h2>
        <form method="post" action="options.php" style="text-align: left;">';
    settings_fields('wgrl-options');
    do_settings_sections( 'wgrl-options' );
    echo '<table>
            <tr>
                <th>Webhook secret</th>
                <td><input type="password" name="wgrl-webhook-secret" value="'. esc_attr( get_option('wgrl-webhook-secret') ) .'" /></td>
            </tr>
            <tr>
                <th>Assign posts to user</th>
                <td>'. wp_dropdown_users(['name' => 'wgrl-post-author', 'echo' => false, 'selected' => get_option('wgrl-post-author') ]). '</td>
            </tr>
            <tr>
                <th>Post type</th>
                <td>
                    <select name="wgrl-custom-post-type">
                        <option value="0">Post tagged "release"</option>
                        <option value="1" '. (get_option('wgrl-webhook-secret') ? 'selected' : '') . '>Custom post type "release"</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>Webhook callback URL</th>
                <td><code>'. esc_url(admin_url('admin-ajax.php')) . '?action=wgrl_release_post</code></td>
            </tr>
        </table>';
    submit_button();
    echo '</form></div>';
}
