<?php
/*
* Other Functions.
*
* Plugin: cosmy-seo
* @since   1.0.0
* @author  Dmitry <github.com/TheSmileGod/>
*/

if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }
    $plugin_file = plugin_basename(__DIR__ . '/index.php');
    $plugin_data = get_plugin_data(__DIR__ . '/index.php');
    $current_version = $plugin_data['Version'];
    $plug_refresh_check = "https://raw.githubusercontent.com/TheSmileGod/Cosmy_seo/main/update/cosmy-plugin-update.json";
    $plug_url = 'https://github.com/TheSmileGod/Cosmy_seo';
    
    $remote = wp_remote_get($plug_refresh_check);
    if (is_wp_error($remote) || 200 !== wp_remote_retrieve_response_code($remote)) return $transient;
    $remote = json_decode(wp_remote_retrieve_body($remote));
    $plugin = 'cosmy_seo/index.php';

    if ($remote && version_compare($current_version, $remote->version, '<')) {
        $res = new stdClass();
        $res->slug = 'cosmy-seo';
        $res->plugin = $plugin;
        $res->new_version = $remote->version;
        $res->package = $remote->download_url;
        $res->url = $plug_url;
        $transient->response[$plugin] = $res;
    }
    return $transient;
});

add_action('wp_head', function () {
  if (is_single()) {
    global $post;
    $title = get_the_title($post);
    $desc  = get_the_excerpt($post) ?: wp_trim_words( wp_strip_all_tags($post->post_content), 35 );
    $img   = get_the_post_thumbnail_url($post, 'full');

    echo '<meta property="og:type" content="article" />' . "\n";
    echo '<meta property="og:title" content="'.esc_attr($title).'" />' . "\n";
    if ($desc) echo '<meta property="og:description" content="'.esc_attr($desc).'" />' . "\n";
    if ($img)  {
      echo '<meta property="og:image" content="'.esc_url($img).'" />' . "\n";
      $size = wp_getimagesize($img);
      if (!empty($size[0]) && !empty($size[1])) {
        echo '<meta property="og:image:width" content="'.$size[0].'" />' . "\n";
        echo '<meta property="og:image:height" content="'.$size[1].'" />' . "\n";
      }
    }
    echo '<meta property="og:url" content="'.esc_url(get_permalink($post)).'" />' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
  }
}, 5);
