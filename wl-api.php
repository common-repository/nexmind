<?php
/*
Plugin Name: NexMind
Plugin URI: https://www.nexmind.ai/
Description: a WordPress plugin that brings your generated content into WordPress Posts.
Version: 1.0.3
Author: Ali
License: GPL2
Requires PHP: 7.0 or higher
Text Domain: NexMind
 */
defined('ABSPATH') or die('Unauthorized Access');

function nxmind_create_settings_table()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'nexmind_settings';

    $sql = "CREATE TABLE $table_name (
        id mediumint(11) NOT NULL AUTO_INCREMENT,
        user_id varchar(255) NOT NULL,
        token varchar(255) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'nxmind_create_settings_table');

function nxmind_check_auth(string $user_id, string $token): bool
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'nexmind_settings';

    $user = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE user_id = %s AND token = %s", $table_name, $user_id, $token));

    if ($user) {
        return true;
    }
    return false;
}

function nxmind_posts(WP_REST_Request $request): array
{
    $data = $request->get_json_params();
    $title = sanitize_text_field($data['title']);
    $content = wp_kses_post($data['content']);
    // $slug = sanitize_title($data['slug']);
    // $page = sanitize_title($data['page']);
    // $categories = $data['categories'];

    $post_name = strtolower(str_replace(' ', '/', trim(sanitize_title($title))));
    $page_id = wp_insert_post(
        array(
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_author' => 1,
            'post_name' => $post_name,
            'post_status' => 'publish',
            'post_content' => $content,
            'post_title' => $title,
            'post_type' => 'post',
            // 'post_category' => $categories,
        )
    );

    $post = get_post($page_id);
    $post_url = get_permalink($post);

    return array(
        'url' => $post_url,
        'page_id' => $page_id,
    );
}

function nxmind_posts_delete(WP_REST_Request $request): mixed
{
    $page_id = sanitize_text_field($request->get_param('page_id'));

    if (!empty($page_id)) {
        $result = wp_delete_post($page_id);
        if ($result) {
            return "post deleted successfully";
        }
    }
    return new WP_Error('post_not_found', 'The post was not found', array('status' => 404));
}

function nxmind_posts_update(WP_REST_Request $request): mixed
{
    $data = $request->get_json_params();
    $page_id = sanitize_text_field($request->get_param('page_id'));

    if (empty($page_id)) {
        return new WP_Error('page_id_missing', 'page_id parameter is required', array('code' => 400));
    }

    $post = get_post($page_id);
    if (!$post) {
        return new WP_Error('post_not_found', 'Post not found with the given page_id', array('code' => 404));
    }

    $post_data = array();
    $post_data['ID'] = $page_id;
    $post_data['post_title'] = $data['title'];
    $post_data['post_content'] = $data['content'];
    $post_data['post_name'] = strtolower(str_replace(' ', '/', trim($data['title'])));
    $post_data['comment_status'] = 'close';
    $post_data['ping_status'] = 'close';
    $post_data['post_status'] = 'publish';
    $post_data['post_type'] = 'post';

    $result = wp_update_post($post_data);
    if ($result) {
        $post = get_post($page_id);
        $post_url = esc_url(get_permalink($post));
        return array(
            'url' => $post_url,
            'page_id' => $page_id,
        );
    } else {
        return new WP_Error('post_update_failed', 'Failed to update the post', array('code' => 500));
    }
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class nxmind_post_main extends WP_List_Table
{
    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = $this->get_hidden_columns();
        $sortable = $this->get_sortable_columns();

        $data = $this->table_data();
        usort($data, array(&$this, 'sort_data'));

        $perPage = 20;
        $currentPage = $this->get_pagenum();
        $totalItems = count($data);

        $this->set_pagination_args(array(
            'total_items' => $totalItems,
            'per_page' => $perPage,
        ));

        $data = array_slice($data, (($currentPage - 1) * $perPage), $perPage);

        $this->_column_headers = array($columns, $hidden, $sortable);
        $this->items = $data;
    }

    public function get_columns()
    {
        $columns = array(
            'cb' => '<input type="checkbox" />',
            'title' => 'Title',
            'actions' => 'Actions',
            'author' => 'Author',
            'date' => 'Date',
        );
        return $columns;
    }

    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="post[]" value="%s" />', $item['ID']);
    }

    public function column_actions($item)
    {
        $view_link = get_permalink(absint($item['ID']));
        $edit_link = get_edit_post_link(absint($item['ID']));
        $trash_link = get_delete_post_link(absint($item['ID']));
        $actions = sprintf('<a href="%1$s">View</a> |  <a href="%3$s">Trash</a>', $view_link, $edit_link, $trash_link);
        return $actions;
    }

    public function get_hidden_columns()
    {
        return array();
    }

    public function get_sortable_columns()
    {
        return array(
            'title' => array('title', false),
            'author' => array('author', false),
            'date' => array('date', false),
        );
    }

    private function table_data()
    {
        global $wpdb;
        $data = array();
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title as title, post_author as author, post_date as date
                FROM %i
                WHERE post_type = %s AND post_status = %s
                ORDER BY post_date DESC",
                $wpdb->posts,
                'post',
                'publish'
            )
        );
        foreach ($posts as $post) {
            // $table_name = $wpdb->prefix . 'nexmind_settings';
            // $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM %i WHERE post_id = %d", $table_name, $post->ID));
            // $token = $wpdb->get_var($wpdb->prepare("SELECT token FROM %i WHERE post_id = %d", $table_name, $post->ID));
            $data[] = array(
                'ID' => intval($post->ID),
                'title' => sanitize_text_field($post->title),
                'author' => sanitize_text_field(get_the_author_meta('display_name', $post->author)),
                'date' => date_i18n(get_option('date_format'), strtotime($post->date)),
                // 'user_id' => intval($user_id),
                // 'token' => esc_html($token),
            );
        }
        return $data;
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'user_id':
            case 'token':
                return intval($item[$column_name]);
            default:
                return sanitize_text_field($item[$column_name]);
        }
    }
}

function nxmind_wl_add_custom_menu_page()
{
    add_menu_page(
        'NexMind Page', // page title
        'NexMind', // menu title
        'manage_options', // capability
        'nexmind-plugin-page', // menu slug
        'nxmind_custom_plugin_page_callback', // callback function
        plugins_url('a1.png', __FILE__), // icon URL
        99 // position
    );
}
add_action('admin_menu', 'nxmind_wl_add_custom_menu_page');

function nxmind_custom_plugin_page_callback()
{
    $post_table = new nxmind_post_main();
    $post_table->prepare_items();
?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html('Posts'); ?></h1>
        <form id="posts-filter" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr('nexmind-plugin-page'); ?>">
            <?php echo esc_html($post_table->display()); ?>
        </form>
    </div>
<?php
}

function nxmind_add_custom_submenu_page()
{
    add_submenu_page(
        'nexmind-plugin-page', // parent slug
        'Users', // page title
        'Users', // menu title
        'manage_options', // capability
        'nexmind-plugin-users-page', // menu slug
        'nxmind_custom_plugin_users_page_callback' // callback function
    );
}
add_action('admin_menu', 'nxmind_add_custom_submenu_page');

function nxmind_custom_plugin_users_page_callback()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'nexmind_settings';
    $settings = $wpdb->get_results($wpdb->prepare("SELECT id, user_id, token FROM %i", $table_name));

    if (isset($_POST['submit'])) {
        if (isset($_POST['nxmind_settings_nonce']) && wp_verify_nonce($_POST['nxmind_settings_nonce'], 'nxmind_settings_nonce_action')) {
            $user_id = sanitize_text_field($_POST['user_id']);
            $token = sanitize_text_field($_POST['token']);

            if (!empty($user_id) && !empty($token)) {
                $wpdb->insert(
                    $wpdb->prefix . 'nexmind_settings',
                    array(
                        'user_id' => $user_id,
                        'token' => $token,
                    ),
                    array(
                        '%s',
                        '%s',
                    )
                );
                echo '<div class="updated notice is-dismissible"><p>' . esc_html__('User ID and Token saved successfully!', 'nexmind') . '</p></div>';
            }
        } else {
            // Nonce verification failed, handle the error here
            echo '<div class="error notice is-dismissible"><p>' . esc_html__('Nonce verification failed. Please try again.', 'nexmind') . '</p></div>';
        }
    }

    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        if ($id > 0) {
            $wpdb->delete($table_name, array('id' => $id), array('%d'));
            echo '<div class="updated notice is-dismissible"><p>' . esc_html__('Settings deleted successfully!', 'nexmind') . '</p></div>';
        }
    }
?>
    <div class="wrap1">
        <br>
        <h1 class="wp-heading-inline1"><?php esc_html_e('NexMind User Settings', 'nexmind'); ?></h1>
        <hr class="line">
        <br>
    </div>
    <div class="wrap">
        <form method="post">
            <div class="form-inline">
                <label for="user_id"><?php esc_html_e('User ID:', 'nexmind'); ?></label>
                <input type="text" name="user_id" id="user_id" value="<?php echo esc_attr((isset($_GET['action']) && $_GET['action'] == 'edit') ? $settings[intval($_GET['id']) - 1]->user_id : ''); ?>" required>
                <label for="token"><?php esc_html_e('Token ID:', 'nexmind'); ?></label>
                <input type="text" name="token" id="token" value="<?php echo esc_attr((isset($_GET['action']) && $_GET['action'] == 'edit') ? $settings[intval($_GET['id']) - 1]->token : ''); ?>" required>
            </div>
            <?php wp_nonce_field('nxmind_settings_nonce_action', 'nxmind_settings_nonce'); ?>
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Save', 'nexmind'); ?>">
            </p>
        </form>
    </div>
    <?php

    echo '<style>

    .form-inline {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    }

    .form-inline label {
    margin: 0 10px 0 0;
    }

    .form-inline input {
    flex: inherit;
    margin: 0 10px;
    }

    .support-text {
        font-size: 14px;
        margin-top: 550px;
    }


    </style>';

    echo '<table class="settings-table">';
    echo '<tr>';
    echo '<th class="table-header">' . esc_html('User ID') . '</th>';
    echo '<th class="table-header">' . esc_html('Token ID') . '</th>';
    echo '<th class="table-header">' . esc_html('Action') . '</th>';
    echo '</tr>';

    foreach ($settings as $setting) {
        echo '<tr class="table-row">';
        echo '<td class="table-data">' . esc_html($setting->user_id) . '</td>';
        echo '<td class="table-data">' . esc_html($setting->token) . '</td>';
        echo '<td class="table-data"><a href="' . esc_url(sanitize_url($_SERVER['REQUEST_URI'])) . '&action=delete&id=' . absint($setting->id) . '" class="delete-btn">Delete</a></td>';
        echo '</tr>';
    }

    echo '</table>';

    ?>
    <div class="support-text">
        If you encounter any problems, please check our <a href="https://www.nexmind.ai/wordpress-integration" target="_blank">documentation</a> or contact our <a href="mailto:techteam@nexmind.ai">support team</a>.
    </div>
<?php

    echo '<style>

    table.settings-table {
        width: 50%;
        border-collapse: collapse;
        margin: 8px;

    }

    .table-header {
        background-color: #ddd;
        font-weight: bold;
        padding: 10px;
        text-align: left;
    }

    .table-row {
        border-bottom: 1px solid #ddd;
    }

    .table-data {
        padding: 10px;
        text-align: left;
    }

    .delete-btn {
        color: #fff;
        background-color: #2271b1;
        padding: 5px 10px;
        border-radius: 5px;
        text-decoration: none;
    }

    .delete-btn:hover {
        color: white;
    }

    hr.line {
        border-top: 2px solid #black;
    }


    </style>';
}

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    $headers = $request->get_headers();
    $page_id = sanitize_text_field($request->get_param('page_id'));

    if (isset($headers['nexmind']) && isset($headers['token'])) {
        $user_id = $headers['nexmind'][0];
        $token = $headers['token'][0];

        if (nxmind_check_auth($user_id, $token)) {
            return $page_id;
        } else {
            return new WP_Error('unauthorized', 'Unauthorized', array('status' => 401));
        }
    } else if (is_user_logged_in() && current_user_can('manage_options')) {
        return $page_id;
    } else {
        return new WP_Error('missing_auth_headers', 'Missing user_id and token headers', array('status' => 400));
    }
}, 10, 3);

add_filter('kses_allowed_protocols', function ($protocols) {
    $protocols[] = 'data';

    return $protocols;
});

add_action('rest_api_init', function () {
    register_rest_route('wp/v2', '/posts', array(
        'methods' => 'POST',
        'callback' => 'nxmind_posts',
        'permission_callback' => '__return_true',
    ));
});

add_action('rest_api_init', function () {
    register_rest_route('wp/v2', '/posts/(?P<page_id>\d+)', array(
        'methods' => array('POST'),
        'callback' => 'nxmind_posts_update',
        'permission_callback' => '__return_true',
    ));
});

add_action('rest_api_init', function () {
    register_rest_route('wp/v2', '/posts/(?P<page_id>\d+)', array(
        'methods' => array('DELETE'),
        'callback' => 'nxmind_posts_delete',
        'permission_callback' => '__return_true',
    ));
});