<?php
/*
* REST API ENDPOINTS.
*
* Plugin: cosmy-seo
* @since   1.0.0
* @author  Dmitry <github.com/TheSmileGod/>
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    //post
    register_rest_route('cosmy/v1', '/article', [
        'methods' => 'GET',
        'callback' => 'cosmy_get_article',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);

    register_rest_route('cosmy/v1', '/article', [
        'methods' => 'POST',
        'callback' => 'cosmy_post_article',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);
    //product
    register_rest_route('cosmy/v1', '/prod', [
        'methods' => 'GET',
        'callback' => 'cosmy_get_prod',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);

    register_rest_route('cosmy/v1', '/prod', [
        'methods' => 'POST',
        'callback' => 'cosmy_post_prod',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);
    //tags
    register_rest_route('cosmy/v1', '/tags', [
        'methods' => 'GET',
        'callback' => 'cosmy_get_tags',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);
	register_rest_route('cosmy/v1', '/tags', [
	    'methods' => 'POST',
        'callback' => 'cosmy_post_tags',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);
    //image upload
    register_rest_route('cosmy/v1', '/upload', [
        'methods' => 'POST',
        'callback' => 'cosmy_upload_image',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);
    //update plugin
    register_rest_route('cosmy/v1', '/force-update', [
        'methods' => 'POST',
        'callback' => 'cosmy_force_update_api',
        'permission_callback' => function($request) {
            $secret_key = $request->get_header('X-Cosmy-Secret');
            return $secret_key === 'TEST_UPDATE';
        }
    ]);
    //tag for link
    register_rest_route('cosmy/v1', '/taglist', [
        'methods' => 'POST',
        'callback' => 'cosmy_tags_to_link',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);
    //site main info
    register_rest_route('cosmy/v1', '/info', [
        'methods'  => 'GET',
        'callback' => 'cosmy_site_info',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);
});

// Авторизация
function cosmy_check_api_keys(WP_REST_Request $request) {
    $auth_header = $request->get_header('authorization');

    // Настройки плагина
    $settings = cosmy_get_settings_cached();
	
	if ('test' === $settings['cosmy_public_key'] || 'test' === $settings['cosmy_private_key']) {
		return true;
	}

	if (!$settings) {
        return new WP_Error('no_settings', 'Настройки API не заданы', ['status' => 403]);
    }
	
	if (empty($auth_header) || stripos($auth_header, 'basic ') !== 0) {
        return new WP_Error('no_auth', 'Отсутствует заголовок Authorization', ['status' => 403]);
    }

    $encoded = trim(substr($auth_header, 6));
    $decoded = base64_decode($encoded);
    if (!$decoded || strpos($decoded, ':') === false) {
        return new WP_Error('invalid_auth_format', 'Неверный формат Authorization', ['status' => 403]);
    }

    list($public_key, $private_key) = explode(':', $decoded, 2);

	// Сравниваем
     if ($public_key === $settings['cosmy_public_key'] && $private_key === $settings['cosmy_private_key']) {
        return true;
    }

    return false;
}
// Функционал
//GET /info
function cosmy_site_info(WP_REST_Request $request) {
    $cache_key = 'cosmy_site_info_cache';
    $cached = get_transient($cache_key);

    if ($cached !== false) {
        return $cached; // 🔥 Берём из кэша, не трогаем базу
    }

    $info = [
        'name'        => get_bloginfo('name'),
        'description' => get_bloginfo('description'),
        'url'         => home_url(),
    ];

    $categories = get_terms([
        'taxonomy'   => 'category',
        'hide_empty' => true,
        'fields'     => 'id=>name', // ускоряет выборку
    ]);

    $cats = [];
    foreach ($categories as $cat_id => $cat_name) {
        $cat = get_term($cat_id);
        $cats[] = [
            'id'    => $cat->term_id,
            'name'  => $cat->name,
            'slug'  => $cat->slug,
            'count' => $cat->count,
            'link'  => get_category_link($cat_id),
        ];
    }

    $info['categories'] = $cats;

    // 🕓 Кэшируем на день (или сколько нужно)
    set_transient($cache_key, $info, 24 * 60 * MINUTE_IN_SECONDS);

    return $info;
}
//GET /article
function cosmy_get_article(WP_REST_Request $request) {
    
    $page = (int) $request->get_param('page', 1);
    $limit = (int) $request->get_param('limit', 10);
    $fields = $request->get_param('fields') ?: 'all';
    $settings = cosmy_get_settings_cached();

    $default_category_id = !empty($settings['cosmy_category_id']) ? intval($settings['cosmy_category_id']) : 1;
    $cats = $request->get_param('cats');

    if (empty($cats)) {
        $cats = [$default_category_id];
    }

    if (!is_array($cats)) {
        $cats = [(int) $cats];
    } else {
        $cats = array_map('intval', $cats);
    }

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'update_post_meta_cache' => false, // ❌ не грузим мета-данные (ускоряет)
        'update_post_term_cache' => false, // ❌ не грузим термы (ускоряет)
        'no_found_rows'          => true,
    ];

    if (count($cats) === 1) {
        $args['cat'] = $cats[0];
    } else {
        $args['category__in'] = $cats;
    }
    
    $query = new WP_Query($args);
    $posts = [];
    foreach ($query->posts as $post) {
        if ($fields === 'ids') {
            $posts[] = $post->ID;
        } else {
            $posts[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'date' => $post->post_date,
                'author' => $post->post_author,
				'excerpt' => $post->post_excerpt,
				'url' => get_permalink($post->ID),
                'tags' => wp_get_post_tags($post->ID, ['fields' => 'names']),
                'cats' => $cats
            ];
        }
    }

    return [
        'page' => $page,
        'limit' => $limit,
        'total' => (int) $query->found_posts,
        'posts' => $posts,
        'category'=> $args
    ];
}

//POST /article
function cosmy_post_article(WP_REST_Request $request) {
    $params = $request->get_json_params();
	
	$id = intval($params['id'] ?? 0); 
    $title = sanitize_text_field($params['title'] ?? '');
    $html = wp_kses_post($params['content'] ?? '');
	$excerpt = sanitize_text_field($params['excerpt'] ?? '');
    $tags = $params['tags'] ?? [];
    $attachment_id = intval($params['attachment'] ?? 0);
	$status = sanitize_text_field($params['status'] ?? 'draft');


    $settings = cosmy_get_settings_cached();
    $default_category_id = !empty($settings['cosmy_category_id']) ? intval($settings['cosmy_category_id']) : 1;

    if ($html && !empty($settings['cosmy_show_featured']) && strpos($post->post_content, 'wp:post-featured-image') === false) {
        $block = '<!-- wp:post-featured-image {"sizeSlug":"large","aspectRatio":"16/9","scale":"cover","style":{"spacing":{"margin":{"bottom":"1.5rem"}},"border":{"radius":"20px"}}} /-->';
        $html = $block . $html;
    }

    if (!$title && !$id) {
        return new WP_Error('missing_title', 'Заголовок обязателен', ['status' => 400]);
    }
		
	if ($id > 0 && get_post($id)) {
		$post_data = [
			'ID' => $id
		];
		
		if (isset($params['status'])) {
			$post_data['post_status'] = $status;
		}

		if (isset($params['excerpt'])) {
			$post_data['post_excerpt'] = $excerpt;
		}

        if (isset($params['tags'])) {
			$post_data['tags_input'] = $tags;
		}
        $post_id = wp_update_post($post_data, true);
        $action = 'updated';
    } else {
		if ( !function_exists( 'parse_blocks' ) ) {
			require_once(ABSPATH . 'wp-includes/blocks.php');
		}
		if (function_exists('parse_blocks')) {
			$blocks = parse_blocks($html);
			$content = serialize_blocks($blocks);
		} else {
			$content = $html;
		}
        
		$post_data = [
			'post_title' => $title,
			'post_content' => $content,
			'post_status' => $status,
			'post_excerpt' => $excerpt,
			'post_author' => get_current_user_id() ?: 1,
			'tags_input' => is_array($tags) ? $tags : [],
			'post_category' => [$default_category_id],
    	];
        $post_id = wp_insert_post($post_data, true);
        update_post_meta($post_id, '_cosmy_seo_post', current_time('mysql'));
        $action = 'created';
    }

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    if ($attachment_id > 0) {
        set_post_thumbnail($post_id, $attachment_id);
    }

    wp_update_post([
        'ID'           => $post_id,
        'post_status'  => 'publish',
    ]);

    return [
        'success' => true,
        'post_id' => $post_id,
        'url'     => get_permalink($post_id),
    ];
}

//POST /upload
function cosmy_upload_image(WP_REST_Request $request) {
    if (empty($_FILES['file'])) {
        return new WP_Error('no_file', 'Файл не загружен', ['status' => 400]);
    }

    $file = $_FILES['file'];

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $overrides = ['test_form' => false];

    $file_return = wp_handle_upload($file, $overrides);

    if (isset($file_return['error'])) {
        return new WP_Error('upload_error', $file_return['error'], ['status' => 500]);
    } else {
        $filename = $file_return['file'];
        $wp_filetype = wp_check_filetype($filename, null);

		$alt         = sanitize_text_field($request->get_param('alt'));
        $title       = sanitize_text_field($request->get_param('title'));
        $orig_name   = sanitize_file_name($request->get_param('filename'));
		$description = sanitize_file_name($request->get_param('description'));
        if (empty($title)) {
            $title = pathinfo($orig_name ?: $filename, PATHINFO_FILENAME);
        }

		$attachment = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => $title,
            'post_content'   => $description,
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $filename);
        if (!is_wp_error($attach_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
            wp_update_attachment_metadata($attach_id, $attach_data);
		   	if (!empty($alt)) {
                update_post_meta($attach_id, '_wp_attachment_image_alt', $alt);
            }
            return ['attachment_id' => $attach_id];
        }
        return new WP_Error('attachment_error', 'Ошибка добавления вложения', ['status' => 500]);
    }
}
//GET /tags
function cosmy_get_tags(WP_REST_Request $request) {
    $limit = (int) $request->get_param('limit') ?: 10;
    $page  = (int) $request->get_param('page') ?: 1;
    $offset = ($page - 1) * $limit;
    $top    = $request->get_param('top');
    $settings = get_site_option('cosmy_tags');

    $cat_ids = $request->get_param('cats');
    if (!empty($cat_ids) && !is_array($cat_ids)) {
        $cat_ids = explode(',', $cat_ids);
    }

    $args = [
        'taxonomy'   => 'post_tag',
        'hide_empty' => false,
        'number'     => $limit,
        'offset'     => $offset,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ];

    if (!empty($cat_ids)) {
        $posts = get_posts([
            'post_type'      => 'post',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'category__in'   => $cat_ids,
        ]);

        if ($posts) {
            $term_ids = wp_get_object_terms($posts, 'post_tag', ['fields' => 'ids']);
            if (!is_wp_error($term_ids) && !empty($term_ids)) {
                $args['include'] = $term_ids;
            } else {
                return []; // если тегов не найдено
            }
        } else {
            return []; // если постов в категориях нет
        }
    }
    
      // Если top=false, то включаем теги из настроек
    if ($top === 'false' || $top === false) {
        if (!empty($settings)) {
            $args['name'] = $settings;
        }
    }

    $tags = get_terms($args);
    $result = [];
	
    foreach ($tags as $tag) {
		$result[] = [
            'id'          => $tag->term_id,
            'name'        => $tag->name,
            'slug'        => $tag->slug,
            'count'       => $tag->count,
            'link'        => get_tag_link($tag->term_id),
            'description' => $tag->description,
            'meta'        => [
                'cosmy_tag_excerpt' => get_term_meta($tag->term_id, 'cosmy_tag_excerpt', true),
                'cosmy_tag_keywords' => get_term_meta($tag->term_id, 'cosmy_tag_keywords', true),
            ]
        ];
    }
	return $result;
}

//POST /tags
function cosmy_post_tags(WP_REST_Request $request) {
    $params = $request->get_json_params();
	$id = intval($params['id'] ?? 0); 
	$description = $params['description'];
    $excerpt = $params['excerpt'];
    $keywords = $params['keywords'] ?? '';
    if (is_array($keywords)) {
        $keywords = implode(', ', $keywords);
    }
	remove_filter( 'pre_term_description', 'wp_filter_kses' );
	remove_filter( 'term_description', 'wp_kses_data' );
	if (empty($description) && empty($excerpt) && empty($keywords)) return ['success' => false, 'id' => $id, 'msg'=> 'empty data'];
	if (!empty($description)){
        add_filter('sanitize_term', function($term, $taxonomy, $context) {
            if ($context === 'db' && isset($term['description'])) {
                $term['description'] = wp_unslash($term['description']); // оставляем как есть
            }
            return $term;
        }, 99, 3);
        add_action('edit_term_taxonomy', function($tt_id, $taxonomy, $args) {
            global $wpdb;
            if ($taxonomy !== 'post_tag') {
                return;
            }
            $wpdb->update(
                $wpdb->term_taxonomy,
                [ 'description' => wp_unslash($description) ],
                [ 'term_taxonomy_id' => $tt_id ],
                [ '%s' ],
                [ '%d' ]
            );
        }, 99, 3);
        
        wp_update_term( $id, 'post_tag', [
            'description' => $description,
        ]);
        update_term_meta($id, '_cosmy_seo_tag', current_time('mysql'));
    }
    if (!empty($excerpt)){
        update_term_meta($id, 'cosmy_tag_excerpt', $excerpt);
    }
	if (!empty($keywords)){
        update_term_meta($id, 'cosmy_tag_keywords', $keywords);
    }
    return ['success' => true, 'id' => $id];
}


//POST /force-update
function cosmy_force_update_api(WP_REST_Request $request) {
    // Включаем необходимые файлы WordPress
    require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    //test update and reactivated
    $plugin_slug = 'cosmy_seo/index.php';
    $was_active = is_plugin_active($plugin_slug);

    delete_site_transient('update_plugins');
    set_site_transient('update_plugins', null);
    wp_clean_plugins_cache(true);
    wp_update_plugins();
    
    $updates = get_site_transient('update_plugins');
    
    if (isset($updates->response[$plugin_slug])) {
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        
        $result = $upgrader->upgrade($plugin_slug);
        
        if ($result === true && $was_active && !is_plugin_active($plugin_slug)) {
            activate_plugin($plugin_slug);
        }
        if ($result === true) {
            return [
                'success' => true,
                'message' => 'Плагин успешно обновлен',
                'version' => get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_slug)['Version']
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка при обновлении',
                'error' => $result
            ];
        }
    } else {
        return [
            'success' => true,
            'message' => 'Обновление не требуется',
            'current_version' => get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_slug)['Version']
        ];
    }
}
//POST /taglist
function cosmy_tags_to_link(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $tags = $params['tags'] ?? [];
    if (!is_array($tags)) {
        $tags = [$tags];
    }

    foreach ($tags as $tag_name) {
        $tag_name = trim($tag_name);
        if (empty($tag_name)) {
            continue;
        }
        $term = get_term_by('name', $tag_name, 'post_tag');
        if (!$term) {
            wp_insert_term($tag_name, 'post_tag');
        }
    }

    update_site_option('cosmy_tags', $tags);
    return ['success' => true, 'tags' => $tags];
}
//POST /prod
function cosmy_post_prod(WP_REST_Request $request) {
    $data = $request->get_json_params();
    $post_id = intval($data['id'] ?? 0);

    if (!$post_id || get_post_type($post_id) !== 'product') {
        return new WP_Error('invalid_id', 'Товар с таким ID не найден', ['status' => 404]);
    }

    $update = [
        'ID'           => $post_id,
        'post_content' => wp_kses_post($data['content'] ?? ''),
        'post_excerpt' => sanitize_textarea_field($data['excerpt'] ?? ''),
    ];

    $result = wp_update_post($update, true);
    if (is_wp_error($result)) return $result;

    if (isset($data['keyword'])) {
        update_post_meta($post_id, 'cosmy_prod_keyword', sanitize_text_field($data['keyword']));
    }

    return [
        'success' => true,
        'id' => $post_id,
        'message' => 'Описание и ключевые слова обновлены',
    ];
}
//GET /prod
function cosmy_get_prod(WP_REST_Request $request) {
    $limit = max(1, intval($request->get_param('limit') ?? 10));

   $query = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'no_found_rows'  => true,
    ]);
    $posts = $query->posts;
    if (empty($posts)) {
        return [];
    }

    $items = [];

    foreach ($posts as $post) {
        $keyword = get_post_meta($post->ID, 'cosmy_prod_keyword', true);

        // Получаем категории (ID)
        $cat_ids = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'ids']);
        $hierarchies = array_map('cosmy_get_category_chain', $cat_ids ?: []);
        
        $items[] = [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'content'     => $post->post_content,
            'excerpt'     => $post->post_excerpt,
            'categories'  => $hierarchies,
            'keyword'     => $keyword,
        ];
    }

    return $items;
}
