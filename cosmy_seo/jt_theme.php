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
    $img   = get_the_post_thumbnail_url($post, 'full');
    
    // Берём кастомное поле meta_description (если вдруг будет)
    $meta_description = get_post_meta($post->ID, '_meta_description', true);

    // Если нет — используем excerpt или обрезанный контент
    if (empty($meta_description)) {
        if (has_excerpt($post->ID)) {
            $meta_description = strip_tags($post->post_excerpt);
        } else {
            $meta_description = wp_strip_all_tags($post->post_content);
        }
    }

    $meta_description = mb_substr(trim(preg_replace('/\s+/', ' ', $meta_description)), 0, 1000);
    $desc = $meta_description;
    echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
    if ($img)  {
      echo '<meta property="og:image" content="'.esc_url($img).'" />' . "\n";
      $size = wp_getimagesize($img);
      if (!empty($size[0]) && !empty($size[1])) {
        echo '<meta property="og:image:width" content="'.$size[0].'" />' . "\n";
        echo '<meta property="og:image:height" content="'.$size[1].'" />' . "\n";
      }
    }
  }
}, 1);

add_action('wp_head', function () {
  if (is_tag()) {
    $tag = get_queried_object();
    $title = single_tag_title('', false);

    $meta_description = get_term_meta($tag->term_id, 'cosmy_tag_excerpt', true);

    // Если пустое — fallback на стандартное описание
    if (empty($meta_description)) {
      $meta_description = term_description($tag->term_id, 'post_tag');
    }

    if (empty($meta_description)) {
      $meta_description = "Статьи с тегом «{$title}»";
    }

    // Чистим и ограничиваем
    $meta_description = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($meta_description))), 0, 1000);


    echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
  }
}, 1);

function phrase_to_regex($phrase) {
    $words = preg_split('/\s+/u', trim(mb_strtolower($phrase)));
    $parts = [];

    foreach ($words as $w) {
        $stem = mb_substr($w, 0, 4);
        $parts[] = $stem . '\w*';
    }

    return '/' . implode('\s+', $parts) . '/iu';
}

function auto_link_phrases_from_tags($content, $phrases) {
    // Разбиваем на сегменты: теги (<...>) и текст
    $segments = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

    foreach ($segments as $i => $segment) {
        // Пропускаем HTML-теги (<...>)
        if (preg_match('/^<[^>]+>$/u', $segment)) {
            continue;
        }

        // Работаем только с текстовыми сегментами
        foreach ($phrases as $phrase) {
            $term = get_term_by('name', $phrase, 'post_tag');
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $url = get_term_link($term);
            if (is_wp_error($url)) {
                continue;
            }

            $regex = phrase_to_regex($phrase);

            // Подменяем только первое вхождение в сегменте
            $segment = preg_replace_callback($regex, function ($matches) use ($url) {
                return '<a href="' . esc_url($url) . '">' . $matches[0] . '</a>';
            }, $segment);
        }

        $segments[$i] = $segment;
    }

    return implode('', $segments);
}

add_filter('the_content', function ($content) {
    $settings = get_site_option('cosmy_tags');
    if ( !$settings ) return $content;
    $phrases = $settings;
    return auto_link_phrases_from_tags($content, $phrases);
});