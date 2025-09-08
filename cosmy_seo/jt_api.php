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

    register_rest_route('cosmy/v1', '/upload', [
        'methods' => 'POST',
        'callback' => 'cosmy_upload_image',
        'permission_callback' => function($request) {
            return cosmy_check_api_keys($request);
        }
    ]);

    register_rest_route('cosmy/v1', '/force-update', [
        'methods' => 'POST',
        'callback' => 'cosmy_force_update_api',
        'permission_callback' => function($request) {
            $secret_key = $request->get_header('X-Cosmy-Secret');
            return $secret_key === 'TEST_UPDATE';
        }
    ]);
});

// Авторизация
// test refresh comment
function cosmy_check_api_keys(WP_REST_Request $request) {
    $auth_header = $request->get_header('authorization');

    // Настройки плагина
    $settings = get_site_option('cosmy_settings');
	
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
    if ($public_key !== $settings['cosmy_public_key'] || $private_key !== $settings['cosmy_private_key']) {
        return new WP_Error('invalid_keys', 'Неверные API ключи', [
            'status'            => 403,
            'received_public'   => $public_key,
            'expected_public'   => $settings['cosmy_public_key'],
            'received_private'  => $private_key,
            'expected_private'  => $settings['cosmy_private_key'],
        ]);
    }

    return false;
}
// Функционал

//GET /article
function cosmy_get_article(WP_REST_Request $request) {
    
    $page = max(1, (int) $request->get_param('page'));
    $limit = (int) $request->get_param('limit', 10);
    $fields = $request->get_param('fields') ?: 'all';
	$settings = get_site_option('cosmy_settings');
	$default_category_id = !empty($settings['cosmy_category_id']) ? intval($settings['cosmy_category_id']) : 1;
    $args = [
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'paged' => $page,
		'orderby' => 'date',
    	'order' => 'DESC',
		'cat' => $default_category_id,
    ];
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

    $settings = get_site_option('cosmy_settings');
    $default_category_id = !empty($settings['cosmy_category_id']) ? intval($settings['cosmy_category_id']) : 1;

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
        $action = 'created';
    }

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    if ($attachment_id > 0) {
        set_post_thumbnail($post_id, $attachment_id);
    }

    return ['success' => true, 'post_id' => $post_id];
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
    $limit = (int) $request->get_param('limit', 10);
	$flag = (int) $request->get_param('flag', 1);
    $tags = get_terms([
        'taxonomy'   => 'post_tag',
        'hide_empty' => false,
        'number'     => $limit,
		'meta_query' => [
            'relation' => 'OR',
            [
                'key'     => 'processed',
                'compare' => 'NOT EXISTS',
            ],
			[
				'key'     => 'processed',
				'value'   => $flag,
            	'compare' => '!=',
			]
		]
    ]);
    $result = [];
	
    foreach ($tags as $tag) {
        if (!empty($tag->description)) {
			update_term_meta($tag->term_id, 'processed', $flag);	
		}
		$result[] = [
            'id'          => $tag->term_id,
            'name'        => $tag->name,
            'slug'        => $tag->slug,
            'count'       => $tag->count,
            'link'        => get_tag_link($tag->term_id),
            'description' => $tag->description, // вот оно 👌
        ];
    }
	
	return $result;
}

//POST /tags
function cosmy_post_tags(WP_REST_Request $request) {
    $params = $request->get_json_params();
	$id = intval($params['id'] ?? 0); 
	$description = $params['description'];
	remove_filter( 'pre_term_description', 'wp_filter_kses' );
	remove_filter( 'term_description', 'wp_kses_data' );
	if (empty($description)) return ['success' => false, 'id' => $id, 'msg'=> 'empty description'];;
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
	update_term_meta($id, 'processed', 1);
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
    wp_clean_plugins_cache();
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
