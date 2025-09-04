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

add_action('save_post', function($post_id, $post, $update) {
    // Только для записей (post)
    if ($post->post_type !== 'post') {
        return;
    }

    // Не трогаем автосохранения и ревизии
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }

    // Берём глобальные настройки плагина
    $settings = get_site_option('cosmy_settings', []);
    if (empty($settings['cosmy_show_featured'])) {
        return; // выключено в настройках
    }

    // Проверяем, есть ли уже блок "Изображение записи"
    if (strpos($post->post_content, 'wp:post-featured-image') !== false) {
        return;
    }

    // Добавляем Gutenberg-блок в начало
    $block = '<!-- wp:post-featured-image {"sizeSlug":"large","aspectRatio":"16/9","scale":"cover","style":{"spacing":{"margin":{"bottom":"1.5rem"}},"border":{"radius":"20px"}}} /-->';
    
    // Избегаем рекурсии
    remove_action('save_post', __FUNCTION__, 10);
    wp_update_post([
        'ID'           => $post_id,
        'post_content' => $block . "\n" . $post->post_content,
    ]);
    add_action('save_post', __FUNCTION__, 10, 3);
}, 10, 3);
