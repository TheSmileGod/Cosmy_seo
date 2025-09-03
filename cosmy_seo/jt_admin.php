<?php
// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

function cosmy_register_settings_page() {
    add_menu_page(
        'Cosmy API Plugin Settings',
        'Cosmy API Plugin',
        'manage_options',
        'cosmy-seo',
        'cosmy_render_settings_page',
        'dashicons-admin-generic',
        99
    );
}
add_action('admin_menu', 'cosmy_register_settings_page');

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_url = admin_url('admin.php?page=cosmy-seo');
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . __('Настройки', 'cosmy-seo') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

function cosmy_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $settings = get_site_option('cosmy_settings', [
        'cosmy_public_key' => '',
        'cosmy_private_key' => '',
        'cosmy_user_id' => null,
		'cosmy_category_id'  => null,
    ]);

    if (isset($_POST['cosmy_save_settings'])) {
        check_admin_referer('cosmy_save_settings_verify');

        $cosmy_public_key = sanitize_text_field($_POST['cosmy_public_key']);
        $cosmy_private_key = sanitize_text_field($_POST['cosmy_private_key']);
        $cosmy_user_id = intval($_POST['cosmy_user_id']);
		$cosmy_category_id = intval($_POST['cosmy_category_id']); // 🔹
		
        $settings = [
            'cosmy_public_key' => $cosmy_public_key,
            'cosmy_private_key' => $cosmy_private_key,
            'cosmy_user_id' => $cosmy_user_id,
			'cosmy_category_id' => $cosmy_category_id,
        ];
        update_site_option('cosmy_settings', $settings);
        
        echo '<div class="updated"><p>Настройки сохранены.</p></div>';
    }
	$categories = get_categories(['hide_empty' => false]);
    $users = get_users(['role__in' => ['administrator', 'editor']]);
    $user_ids = array_map(function($u) { return $u->ID; }, $users);
    
    ?>
    <div class="wrap">
        <h1>Настройки My API Plugin</h1>
        <form method="POST" action="">
            <?php wp_nonce_field('cosmy_save_settings_verify'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="cosmy_public_key">Public API Key</label></th>
                    <td><input type="text" name="cosmy_public_key" id="cosmy_public_key" value="<?php echo esc_attr($settings['cosmy_public_key']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="cosmy_private_key">Private API Key</label></th>
                    <td><input type="text" name="cosmy_private_key" id="cosmy_private_key" value="<?php echo esc_attr($settings['cosmy_private_key']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="cosmy_user_id">Выберите пользователя</label></th>
                    <td>
                        <select name="cosmy_user_id" id="cosmy_user_id">
                            <option value="">— Не выбран —</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($settings['cosmy_user_id'], $user->ID); ?>>
                                    <?php echo esc_html($user->display_name . " (ID: {$user->ID})"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
				<tr>
					<th><label for="cosmy_category_id">Категория постов</label></th>
					<td>
						<select name="cosmy_category_id" id="cosmy_category_id">
							<option value="">— Не выбрана —</option>
							<?php foreach ($categories as $cat): ?>
								<option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($settings['cosmy_category_id'], $cat->term_id); ?>>
									<?php echo esc_html($cat->name . " (ID: {$cat->term_id})"); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
            </table>
            <input type="submit" name="cosmy_save_settings" value="Сохранить" class="button button-primary">
        </form>
    </div>
    <?php
}
