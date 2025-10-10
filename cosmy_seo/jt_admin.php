<?php
/*
* Settings page.
*
* Plugin: cosmy-seo
* @since   1.0.0
* @author  Dmitry <github.com/TheSmileGod/>
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function() {
    $last_checked = get_site_option('cosmy_last_update_check', 0);
    
    if (time() - $last_checked > 0.5 * 3600) {
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache();
        update_site_option('cosmy_last_update_check', time());
    }
});

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

add_filter('plugin_action_links_cosmy_seo/index.php', function($links) {
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
        'cosmy_show_featured'=> 1,
    ]);

    if (isset($_POST['cosmy_save_settings'])) {
        check_admin_referer('cosmy_save_settings_verify');

        $cosmy_public_key = sanitize_text_field($_POST['cosmy_public_key']);
        $cosmy_private_key = sanitize_text_field($_POST['cosmy_private_key']);
        $cosmy_user_id = intval($_POST['cosmy_user_id']);
		$cosmy_category_id = intval($_POST['cosmy_category_id']);
        $cosmy_show_featured = isset($_POST['cosmy_show_featured']) ? 1 : 0;

        $settings = [
            'cosmy_public_key' => $cosmy_public_key,
            'cosmy_private_key' => $cosmy_private_key,
            'cosmy_user_id' => $cosmy_user_id,
			'cosmy_category_id' => $cosmy_category_id,
            'cosmy_show_featured'=> $cosmy_show_featured,
        ];
        update_site_option('cosmy_settings', $settings);

        $cosmy_custom_css = isset($_POST['cosmy_custom_css']) ? wp_strip_all_tags($_POST['cosmy_custom_css'], true) : '';
        update_site_option('cosmy_custom_css', $cosmy_custom_css);
        
        echo '<div class="updated"><p>Настройки сохранены.</p></div>';
    }
    if (isset($_POST['cosmy_reset_css'])) {
        check_admin_referer('cosmy_save_settings_verify');
        update_site_option('cosmy_custom_css', COSMY_DEFAULT_CSS);
        echo '<div class="updated"><p>Стили сброшены на предустановленные.</p></div>';
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
                <tr>
                    <th><label for="cosmy_show_featured">Миниатюра в статье</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="cosmy_show_featured" id="cosmy_show_featured" value="1" <?php checked($settings['cosmy_show_featured'], 1); ?>>
                            Вставлять изображение записи в начало статьи
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="cosmy_custom_css">Пользовательский CSS</label></th>
                    <td>
                        <textarea 
                            name="cosmy_custom_css" 
                            id="cosmy_custom_css" 
                            rows="10" 
                            cols="70" 
                            style="width:100%; font-family: monospace;"><?php 
                                echo esc_textarea( get_site_option('cosmy_custom_css', '') ); 
                            ?></textarea>
                        <p class="description">Введённый здесь CSS будет добавлен в &lt;head&gt; нужных страниц.</p>
                    </td>
                </tr>
            </table>
            <input type="submit" name="cosmy_save_settings" value="Сохранить" class="button button-primary">
            <input type="submit" name="cosmy_reset_css" value="Сбросить стили по умолчанию" class="button button-secondary" onclick="return confirm('Вы уверены, что хотите сбросить стили?');">

        </form>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const categorySelect = document.getElementById('cosmy_category_id');
            const row = categorySelect.closest('tr');

            function checkCategory() {
                if (!categorySelect.value) {
                    row.style.background = '#ffe6e6'; // мягкий красноватый фон
                    if (!row.querySelector('.cosmy-warning')) {
                        const warning = document.createElement('p');
                        warning.className = 'cosmy-warning';
                        warning.style.color = '#cc0000';
                        warning.style.margin = '5px 0 0';
                        warning.textContent = '⚠ Категория не выбрана — это может повлиять на работу модуля.';
                        row.querySelector('td').appendChild(warning);
                    }
                } else {
                    row.style.background = '';
                    const warning = row.querySelector('.cosmy-warning');
                    if (warning) warning.remove();
                }
            }

            checkCategory();
            categorySelect.addEventListener('change', checkCategory);
        });
    </script>
    <?php
}
