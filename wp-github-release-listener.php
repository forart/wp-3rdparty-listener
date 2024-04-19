<?php
/**
 * Plugin Name: Release listener for GitHub
 * Description: Listens to a GitHub webhook and creates a new post every time a release is made.
 * Version: 1.1 - AI
 * Author: Piiu Pilt, Silktide
 * Bugfixer: ChatGPT 3.5
 * Author URI: http://www.silktide.com
 * License: GPLv2
 * Text Domain: release-listener-for-github
 */

defined('ABSPATH') or die('No!');

add_action('wp_ajax_nopriv_wgrl_release_post', 'wgrl_new_release_handler');
function wgrl_new_release_handler() {
    header("Content-Type: application/json");

    $raw_data = file_get_contents('php://input');
    $signatureCheck = 'sha1=' . hash_hmac('sha1', $raw_data, get_option('wgrl-webhook-secret'));

    if ($_SERVER["CONTENT_TYPE"] != 'application/json' || $_SERVER['HTTP_X_HUB_SIGNATURE'] != $signatureCheck) {
        echo json_encode(array('success' => false, 'error' => 'Invalid request'));
    } else {
        $data = json_decode($raw_data, true);
        $release_published = wgrl_add_post($data);
        echo json_encode(array('success' => $release_published));
    }
    exit;
}

function wgrl_add_post($data) {
    if (isset($data['action']) && isset($data['release'])) {
        $existing_post = get_page_by_title(wp_strip_all_tags($data['release']['name']), OBJECT, 'post');

        if ($existing_post) {
            return false;
        }

        $repository_name = isset($data['repository']['full_name']) ? str_replace('forart/', '', $data['repository']['full_name']) : '';
        $name = '<a href="https://github.com/' . esc_attr($data['repository']['full_name']) . '#readme" target="_blank">' . $repository_name . '</a> - ' . wp_strip_all_tags($data['release']['name']);
        $name = $name != '' ? $name : $data['release']['tag_name'];
        $name = get_option('wgrl-title-prefix') != '' ? get_option('wgrl-title-prefix').' '.$name : $name;

        $post_content = $data['release']['body'] . '<p><a href="https://github.com/' . esc_attr($data['repository']['full_name']) . '/releases/latest" target="_blank">► GitHub</a></p>';

        $new_post = array(
            'post_title'   => $name,
            'post_content' => wp_kses_post($post_content), // Sanitize HTML content
            'post_author'  => get_option('wgrl-post-author'),
            'post_status'  => 'publish',
        );

        if (get_option('wgrl-custom-post-type')) {
            $new_post['post_type'] = 'release';
        }

        $post_id = wp_insert_post($new_post);

        add_post_meta($post_id, 'release_tag', $data['release']['tag_name']);
        add_post_meta($post_id, 'download_tar', $data['release']['tarball_url']);
        add_post_meta($post_id, 'download_zip', $data['release']['zipball_url']);

        if (!get_option('wgrl-custom-post-type')) {
            wp_set_object_terms($post_id, wgrl_get_custom_tag(), 'post_tag');
        }

        return true;
    }

    return false;
}

// Shortcode for displaying releases
add_shortcode('wgrl-changelog', 'wgrl_changelog');
function wgrl_changelog($attributes) {
    $options = shortcode_atts(array(
        'limit'     => false,
        'title'     => true,
        'date'      => false,
        'downloads' => false
    ), $attributes);

    $return = '';

    $query = wgrl_get_query($options['limit']);
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $title_values = array();
            if (wgrl_is_true($options['title'])) {
                $title_values[] = get_the_title();
            }
            if (wgrl_is_true($options['date'])) {
                $title_values[] = get_the_date();
            }
            $zip_url = get_post_meta(get_the_id(), 'download_zip', true);
            $tar_url = get_post_meta(get_the_id(), 'download_tar', true);

            $return .= '<div class="release">';
            $return .= !empty($title_values) ? '<h3 class="release-title">' . implode(' - ', $title_values) . '</h3>' : '';
            $return .= '<div class="release-body">' . apply_filters('the_content', get_the_content()) . '</div>';
            if (wgrl_is_true($options['downloads'])) {
                $return .= '<div class="release-downloads">';
                $return .= ($zip_url && $zip_url != '') ? '<a href="' . esc_url($zip_url) . '">[zip]</a>&nbsp;' : '';
                $return .= ($tar_url && $tar_url != '') ? '<a href="' . esc_url($tar_url) . '">[tar]</a>' : '';
                $return .= '</div>';
            }
            $return .= '</div>';
        }
    }
    wp_reset_postdata();

    return $return;
}

function wgrl_is_true($option) {
    return !empty($option) && $option !== 'false';
}

function wgrl_get_query($limit) {
    $args = array(
        'posts_per_page' => $limit ? intval($limit) : -1,
        'post_type'      => get_option('wgrl-custom-post-type') ? 'release' : 'post',
        'tag'            => get_option('wgrl-custom-post-type') ? '' : wgrl_get_custom_tag(),
    );

    return new WP_Query($args);
}

function wgrl_get_custom_tag() {
    return get_option('wgrl-tag-post') ? esc_attr(get_option('wgrl-tag-post')) : 'Opensource';
}

