<?php
// Exercise the real article callback with WordPress boundary doubles.
define('ABSPATH', __DIR__ . '/');
function add_filter(...$args) {}
function add_action(...$args) {}
class WP_REST_Request {
    public function __construct(private array $params) {}
    public function get_json_params() { return $this->params; }
}
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return $value; }
function wp_kses_post($value) { return $value; }
function cosmy_get_settings_cached() { return $GLOBALS['settings']; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function get_userdata($id) { return isset($GLOBALS['users'][$id]) ? (object) ['ID' => $id] : false; }
function current_time($type) { return '2026-10-13 12:00:00'; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function parse_blocks($html) { return [$html]; }
function serialize_blocks($blocks) { return implode('', $blocks); }
function wp_insert_post($data, $error) {
    $GLOBALS['inserted'][] = $data;
    $GLOBALS['posts'][99] = (object) $data;
    return 99;
}
function wp_update_post($data, $error = false) {
    $GLOBALS['updated'][] = $data;
    foreach ($data as $key => $value) $GLOBALS['posts'][$data['ID']]->$key = $value;
    return $data['ID'];
}
function update_post_meta(...$args) {}
function has_post_thumbnail($id) { return false; }
function get_permalink($id) { return 'https://example.invalid/?p=' . $id; }
require dirname(__DIR__) . '/cosmy_seo/jt_api.php';
function run_case($name, $settings, $current, $users, $request, $expected, $existing = null) {
    $GLOBALS['settings'] = $settings;
    $GLOBALS['current_user'] = $current;
    $GLOBALS['users'] = array_fill_keys($users, true);
    $GLOBALS['inserted'] = $GLOBALS['updated'] = [];
    $GLOBALS['posts'] = $existing ? [42 => (object) ['ID' => 42, 'post_author' => $existing]] : [];
    $result = cosmy_post_article(new WP_REST_Request($request + ['title' => 'Fixture', 'content' => 'Fixture']));
    if ($expected === 'error') {
        if (!is_wp_error($result) || $result->code !== 'invalid_post_author' || $GLOBALS['inserted'] || $GLOBALS['updated']) throw new RuntimeException($name);
    } elseif ($existing) {
        if (is_wp_error($result) || $GLOBALS['posts'][42]->post_author !== $existing) throw new RuntimeException($name);
        foreach ($GLOBALS['updated'] as $row) if (isset($row['post_author'])) throw new RuntimeException($name);
    } else {
        if (is_wp_error($result) || $GLOBALS['inserted'][0]['post_author'] !== $expected) throw new RuntimeException($name);
    }
    echo "PASS $name\n";
}
run_case('selected editor, anonymous API, missing user 1', ['cosmy_user_id' => 3783], 0, [3783], [], 3783);
run_case('selected editor precedes logged-in user', ['cosmy_user_id' => '3783'], 8, [3783, 8], [], 3783);
foreach ([null, '', 0, -1, 9999, '3783x', 3783.5] as $id) run_case('invalid explicit setting ' . json_encode($id), ['cosmy_user_id' => $id], 8, [8, 1, 3783], [], 'error');
run_case('absent setting retains authenticated author', [], 8, [8], [], 8);
run_case('absent setting retains existing user-1 legacy policy', [], 0, [1], [], 1);
run_case('absent setting cannot create orphaned author', [], 0, [], [], 'error');
run_case('update retains author despite selected editor', ['cosmy_user_id' => 3783], 0, [3783, 8], ['id' => 42, 'excerpt' => 'updated'], 8, 8);
run_case('update retains author despite invalid setting', ['cosmy_user_id' => 0], 0, [8], ['id' => 42, 'tags' => ['tag']], 8, 8);
