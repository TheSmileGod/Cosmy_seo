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

    if (taxonomy_exists('product_cat')) {
        $product_cats = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'fields'     => 'id=>name',
        ]);

        $prod_cats = [];
        foreach ($product_cats as $cat_id => $cat_name) {
            $cat = get_term($cat_id, 'product_cat');
            $prod_cats[] = [
                'id'    => $cat->term_id,
                'name'  => $cat->name,
                'slug'  => $cat->slug,
                'count' => $cat->count,
                'link'  => get_term_link($cat_id, 'product_cat'),
            ];
        }

        $info['product_categories'] = $prod_cats;
    } else {
        $info['product_categories'] = [];
    }
    // 🕓 Кэшируем на день (или сколько нужно)
    set_transient($cache_key, $info, 2 * 60 * MINUTE_IN_SECONDS);

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
                'flag' => [
                    'create' => get_post_meta($post->ID, '_cosmy_seo_post', true),
                    'tagger' => get_post_meta($post->ID, '_cosmy_seo_post_tagger', true),
                    'excerpt' => get_post_meta($post->ID, '_cosmy_seo_post_excerpt', true),
                ],
                'cats' => $cats
            ];
        }
    }

    return [
        'page' => $page,
        'limit' => $limit,
        'total' => (int) $query->found_posts,
        'posts' => $posts
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

    if ($html && !empty($settings['cosmy_show_featured']) && strpos($html, 'wp:post-featured-image') === false) {
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
		$flag = '';
		if (isset($params['status'])) {
			$post_data['post_status'] = $status;
		}

		if (isset($params['excerpt'])) {
			$post_data['post_excerpt'] = $excerpt;
            $flag = '_excerpt';
		}

        if (isset($params['tags'])) {
			$post_data['tags_input'] = $tags;
            $flag = '_tagger';
		}
        $post_id = wp_update_post($post_data, true);
        update_post_meta($post_id, '_cosmy_seo_post' . $flag, current_time('mysql'));
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
    $page    = max((int) $request->get_param('page'), 1);
    $limit   = $request->has_param('page') ? 100 : ((int) $request->get_param('limit') ?: 10);
    $settings = get_site_option('cosmy_tags');

    $cat_ids = $request->get_param('cats');
    if (!empty($cat_ids) && !is_array($cat_ids)) {
        $cat_ids = explode(',', $cat_ids);
    }

    $args_base = [
        'taxonomy'   => 'post_tag',
        'hide_empty' => false,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ];

    // 🔹 Фильтрация по категориям
    if (!empty($cat_ids)) {
        $posts = get_posts([
            'post_type'      => 'post',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'category__in'   => $cat_ids,
        ]);

        if (empty($posts)) {
            return [
                'page'  => $page,
                'limit' => $limit,
                'total' => 0,
                'tags'  => [],
            ];
        }

        $term_ids = wp_get_object_terms($posts, 'post_tag', ['fields' => 'ids']);
        if (is_wp_error($term_ids) || empty($term_ids)) {
            return [
                'page'  => $page,
                'limit' => $limit,
                'total' => 0,
                'tags'  => [],
            ];
        }

        $args_base['include'] = $term_ids;
    }

    $selected_tags = [];

    // 🔹 1. Берём теги из настроек постранично
    if (!empty($settings)) {
        $offset = ($page - 1) * $limit;
        $settings_page = array_slice($settings, $offset, $limit); // часть для текущей страницы

        if (!empty($settings_page)) {
            $args_settings = array_merge($args_base, [
                'name' => $settings_page,
            ]);
            $selected_tags = get_terms($args_settings);
            if (is_wp_error($selected_tags)) {
                $selected_tags = [];
            }
        }
    }

    $selected_count = count($selected_tags);

    // 🔹 2. Добор популярных, если не хватает тегов на странице
    if ($selected_count < $limit) {
        $exclude_ids = wp_list_pluck($selected_tags, 'term_id');

        $shown_tags = [];
        if (!empty($settings)) {
            $shown_tags = array_slice($settings, 0, ($page - 1) * $limit);
        }

        $args_popular = array_merge($args_base, [
            'number'  => $limit - $selected_count,
            'offset'  => max(0, ($page - 1) * $limit - count($shown_tags)),
            'exclude' => $exclude_ids,
        ]);

        $popular_tags = get_terms($args_popular);
        if (!is_wp_error($popular_tags) && !empty($popular_tags)) {
            $existing_slugs = array_column($selected_tags, 'slug');
            foreach ($popular_tags as $tag) {
                if (!in_array($tag->slug, $existing_slugs)) {
                    $selected_tags[] = $tag;
                }
            }
        }
    }

    // 🔹 3. Формируем ответ
    $result = [];
    foreach ($selected_tags as $tag) {
        $result[] = [
            'id'          => $tag->term_id,
            'name'        => $tag->name,
            'slug'        => $tag->slug,
            'count'       => $tag->count,
            'link'        => get_tag_link($tag->term_id),
            'description' => $tag->description,
            'meta'        => [
                'cosmy_tag_excerpt'  => get_term_meta($tag->term_id, 'cosmy_tag_excerpt', true),
                'cosmy_tag_keywords' => get_term_meta($tag->term_id, 'cosmy_tag_keywords', true),
            ],
            'flag' => [
                'create'  => get_term_meta($tag->term_id, '_cosmy_seo_tag', true),
                'tagger'  => get_term_meta($tag->term_id, '_cosmy_seo_tag_tagger', true),
                'excerpt' => get_term_meta($tag->term_id, '_cosmy_seo_tag_excerpt', true),
            ],
        ];
    }

    // 🔹 4. Общее количество тегов
    $total = wp_count_terms('post_tag', ['hide_empty' => false]);
    if (is_wp_error($total)) {
        $total = 0;
    }

    return [
        'page'  => $page,
        'limit' => $limit,
        'total' => (int) $total,
        'tags'  => $result,
    ];
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
        update_term_meta($id, '_cosmy_seo_tag_excerpt', current_time('mysql'));
    }
	if (!empty($keywords)){
        update_term_meta($id, 'cosmy_tag_keywords', $keywords);
        update_term_meta($id, '_cosmy_seo_tag_tagger', current_time('mysql'));
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

    $taxonomy = 'post_tag';
    $results = [];

    foreach ($tags as $tag_name) {
        $tag_name = trim($tag_name);
        if ($tag_name === '') {
            continue;
        }

        // Собираем все термы, схожие по имени (без учета регистра)
        $all_terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'name__like' => $tag_name,
        ]);

        // Фильтруем только те, у которых имя совпадает без регистра
        $same_terms = array_filter($all_terms, fn($term) => mb_strtolower($term->name) === mb_strtolower($tag_name));

        $target_term = null;

        if (!empty($same_terms)) {
            // Определяем “правильный” вариант (тот, что точно совпадает по регистру)
            foreach ($same_terms as $term) {
                if ($term->name === $tag_name) {
                    $target_term = $term;
                    break;
                }
            }

            // Если точного совпадения нет — выбираем самый популярный
            if (!$target_term) {
                usort($same_terms, fn($a, $b) => $b->count <=> $a->count);
                $target_term = $same_terms[0];
                // Переименовываем в нужный регистр
                wp_update_term($target_term->term_id, $taxonomy, ['name' => $tag_name]);
            }

            // Переносим все посты с дублей к правильному тегу
            foreach ($same_terms as $term) {
                if ($term->term_id === $target_term->term_id) continue;
                  // Получаем все посты с этим дублем
                $posts = get_objects_in_term($term->term_id, $taxonomy);


                // Получаем все посты с этим дублем
                if (!empty($posts)) {
                    foreach ($posts as $post_id) {
                        wp_remove_object_terms($post_id, (int) $term->term_id, $taxonomy);
                        wp_add_object_terms($post_id, (int) $target_term->term_id, $taxonomy);
                    }
                }
                // Удаляем дубль, если у него нет статей
                $term_data = get_term($term->term_id, $taxonomy);
                if (empty($term_data->count)) {
                    wp_delete_term($term->term_id, $taxonomy);
                }
            }
        } else {
            // Если ничего нет — создаем новый тег
            $inserted = wp_insert_term($tag_name, $taxonomy);
            if (!is_wp_error($inserted)) {
                $target_term = get_term($inserted['term_id'], $taxonomy);
            }
        }

        $results[] = [
            'name' => $tag_name,
            'term_id' => $target_term ? $target_term->term_id : null,
        ];
    }

    update_site_option('cosmy_tags', array_column($results, 'name'));

    return [
        'success' => true,
        'tags' => $results,
    ];
}

//POST /prod
function cosmy_post_prod(WP_REST_Request $request) {
    if ( !class_exists('WooCommerce') ) {
        return [];
    }
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
    update_post_meta($post_id, '_cosmy_seo_prod_excerpt', current_time('mysql'));
    if (is_wp_error($result)) return $result;

    $keywords = $data['keyword'] ?? '';
    if (is_array($keywords)) {
        $keywords = implode(', ', $keywords);
    }
    if (isset($data['keyword'])) {
        update_post_meta($post_id, 'cosmy_prod_keyword', sanitize_text_field($keywords));
        update_post_meta($post_id, '_cosmy_seo_prod_tagger', current_time('mysql'));
        $tags = array_filter(array_map('trim', explode(',', $keywords)));

        if (!empty($tags)) {
            wp_set_object_terms($post_id, $tags, 'product_tag', false);
        }
    }
    
    return [
        'success' => true,
        'id' => $post_id,
        'message' => 'Описание и ключевые слова обновлены',
    ];
}
//GET /prod
function cosmy_get_prod(WP_REST_Request $request) {
    if ( !class_exists('WooCommerce') ) {
        return [];
    }
    $limit = intval($request->get_param('limit') ?? 10);
    $cat_ids = $request->get_param('cats');

     if (!is_array($cat_ids)) {
        $cat_ids = [];
    }
    $cat_ids = array_filter(array_map('intval', $cat_ids));

    // Базовый массив аргументов запроса
    $query_args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'no_found_rows'  => true,
    ];

    if (!empty($cat_ids)) {
        $query_args['tax_query'] = [[
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $cat_ids,
            'operator' => 'IN',
        ]];
    }

    $query = new WP_Query($query_args);
    $posts = $query->posts;

    if (empty($posts)) {
        return [];
    }
   
    $items = [];

    foreach ($posts as $post) {
        $keyword = get_post_meta($post->ID, 'cosmy_prod_keyword', true);

        // Получаем категории (ID)
        $cat_ids = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'ids']);

        $longest_chain = [];
        foreach ($cat_ids as $cat_id) {
            $chain = cosmy_get_category_chain($cat_id);
            if (count($chain) > count($longest_chain)) {
                $longest_chain = $chain;
            }
        }

        // 📁 превращаем цепочку в строку через /
        $cat_string = implode(' / ', array_map(fn($c) => $c['name'], $longest_chain));

        
        $items[] = [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'content'     => $post->post_content,
            'excerpt'     => $post->post_excerpt,
            'categories'  => $cat_string,
            'keyword'     => $keyword,
            'flag' => [
                'tagger' => get_post_meta($post->ID, '_cosmy_seo_prod_tagger', true),
                'excerpt' => get_post_meta($post->ID, '_cosmy_seo_prod_excerpt', true),
            ],
			'counter' => [
				'status' => get_post_meta($post->ID, '_stock_status', true),
			],
			'raw' => $post
        ];
    }

    return $items;
}