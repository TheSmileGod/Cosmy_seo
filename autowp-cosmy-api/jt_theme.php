<?php
// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_set_site_transient_update_plugins', 'cosmy_check_for_plugin_update');

function cosmy_check_for_plugin_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }
    $plug_url = 'https://wp.lexx.xyz/';
    $plug_refresh_check = $plug_url.'plugin/cosmy-plugin-update.json';

    $plugin_file = plugin_basename(__FILE__);
    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
    $current_version = $plugin_data['Version'];
    $remote_info = wp_remote_get($plug_refresh_check);

    if (is_wp_error($remote_info)) {
        return $transient;
    }

    $remote_info = json_decode(wp_remote_retrieve_body($remote_info));

    if ($remote_info && version_compare($current_version, $remote_info->version, '<')) {
        $transient->response[$plugin_file] = (object) [
            'slug'        => 'autowp_cosmy_api',
            'new_version' => $remote_info->version,
            'package'     => $remote_info->download_url,
            'url'         => $plug_url
        ];
    }

    return $transient;
}

add_filter('plugins_api', 'cosmy_plugin_info', 10, 3);
function cosmy_plugin_info($false, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'cosmy-plugin') {
        return $false;
    }
    $plug_url = 'https://wp.lexx.xyz/';
    $plug_refresh_check = $plug_url.'plugin/cosmy-plugin-update.json';
    
    $remote_info = wp_remote_get($plug_refresh_check);
    if (is_wp_error($remote_info)) {
        return $false;
    }
    $remote_info = json_decode(wp_remote_retrieve_body($remote_info));

    return (object) [
        'name'          => 'COSMY SEO',
        'slug'          => 'autowp_cosmy_api',
        'version'       => $remote_info->version,
        'author'        => 'CaMnO',
        'homepage'      => $plug_url,
        'download_link' => $remote_info->download_url,
        'sections'      => [
            'description' => $remote_info->description,
            'changelog'   => $remote_info->changelog
        ]
    ];
}
