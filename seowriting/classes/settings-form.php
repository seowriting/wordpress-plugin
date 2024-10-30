<?php

namespace SEOWriting;

defined('WPINC') || exit;

class SettingsForm
{
    /**
     * @var \SEOWriting
     */
    private $plugin;

    /**
     * @param \SEOWriting $plugin
     */
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function init()
    {
        add_action('admin_menu', [$this, 'menu_page'], 20);
        add_action('admin_post_seowriting_admin_save', array($this, 'settings_save'));
        if (wp_doing_ajax()) {
            add_action('wp_ajax_seowriting_settings', [$this, 'conectionAjax']);
        }
    }

    public function menu_page()
    {
        $page = add_options_page(
            'SEOWriting Settings',
            'SEOWriting',
            'manage_options',
            'seowriting-setting',
            [$this, 'render_page']
        );

        add_action('load-' . $page, [$this, 'initSettingsPage']);
    }

    public function initSettingsPage()
    {
        wp_enqueue_script('seowriting-settings',
            plugins_url('../assets/js/settings.js', __FILE__),
            ['jquery'],
            $this->plugin->version,
            true
        );
        wp_enqueue_style('seowriting-admin-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            [],
            $this->plugin->version
        );
        wp_localize_script('seowriting-settings', 'ajax_var', array(
            'nonce' => wp_create_nonce('ajax-nonce')
        ));
    }

    private function getMessageBox($error, $message, $description = '')
    {
        return '<div class="notice ' . ($error ? 'notice-error' : 'notice-success') . ' settings-error inline">'
            . '<p><strong>' . esc_html($message) . '</strong>'
            . ($description ? '<br/>' . esc_html($description) : '')
            . '</p>'
            . '</div>';
    }

    private function getReturnUrl($key)
    {
        return admin_url() . 'options-general.php?page=seowriting-setting&m=' . $key;
    }

    private function getWebhookUrl()
    {
        return admin_url() . 'admin-ajax.php?action=seowriting-notify';
    }

    public function showMessages()
    {
        $res = isset($_GET['m']) ? sanitize_text_field($_GET['m']) : '';
        $message = '';

        if ($res === 'success') {
            $message = $this->getMessageBox(false, 'Connection established');
        } elseif ($res === 'failure') {
            $message = $this->getMessageBox(true,
                'Authorisation Error',
                (isset($_GET['t']) ? sanitize_text_field($_GET['t']) : '')
            );
        }
        return $message;
    }

    public function showNotifications()
    {
        $notice = '';
        if (isset($_GET['seved'])) {
            $notice = $this->getMessageBox(false, __('Settings updated successfully', 'seowriting'));
        }
        return $notice;
    }

    public function render_page()
    {
        require_once $this->plugin->plugin_path . 'tpl/settings/entry.tpl.php';
    }

    public function form_action($action = 'seowriting_admin_save', $type = false, $has_upload = false)
    {
        echo '<form method="post" action="' . esc_html(admin_url('admin-post.php')) . '" class="seowriting-relative" ' . ($has_upload ? 'enctype="multipart/form-data"' : '') . '>';
        echo '<input type="hidden" name="action" value="' . esc_html($action) . '">';
        if ($type) {
            echo '<input type="hidden" name="seowriting_type" value="' . esc_html($type) . '" />';
        }
        wp_nonce_field($action, 'SWR_NONCE');
    }

    public function form_end($disable_reset = false)
    {
        submit_button(__('Save Changes', 'seowriting'), 'primary seowriting-duplicate-float', 'seowriting-submit', true);

        echo '</form>';
    }

    public function settings_save()
    {
        $nonce = sanitize_text_field($_POST['SWR_NONCE']);
        $action = sanitize_text_field($_POST['action']);
        if (!isset($nonce) || !wp_verify_nonce($nonce, $action)) {
            print 'Sorry, your nonce did not verify.';
            exit;
        }
        if (!current_user_can('manage_options')) {
            print 'You can\'t manage options';
            exit;
        }

        $keys = ['sw_shema_type', 'seowriting_split_to_elementor', 'seowriting_debug', 'seowriting_plugin_name'];
        $fields_to_update = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $_POST)) {
                $value = trim($_POST[$key]);
                if ($key === 'seowriting_plugin_name') {
                    /** @phpstan-ignore-next-line */
                    $value = str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9\s]/', '', mb_strtolower($value)));
                }
                $fields_to_update[$key] = sanitize_text_field($value);
            }
        }
        $rename = '';
        /** @phpstan-ignore-next-line */
        $prev_name = (string)get_option('seowriting_plugin_name');
        if (isset($fields_to_update['seowriting_plugin_name']) && $fields_to_update['seowriting_plugin_name'] !== '' && $fields_to_update['seowriting_plugin_name'] !== $prev_name) {
            $rename = $fields_to_update['seowriting_plugin_name'];
        }
        $this->db_update_options($fields_to_update);
        if ($rename !== '') {
            $this->plugin->getAPIClient();
            $result = $this->rename_plugin($rename);
            if ($result === false) {
                $this->db_update_options([
                    'seowriting_plugin_name' => $prev_name,
                ]);
            } else {
                $cur_user = wp_get_current_user();
                $this->plugin->rename([
                    'user_id' => $cur_user->ID,
                    'user_email' => $cur_user->user_email,
                    'webhook' => $this->getWebhookUrl(),
                ]);
            }
        }
        if (isset($_POST['_wp_http_referer'])) {
            wp_safe_redirect(admin_url('options-general.php?page=seowriting-setting&seved=true'));
            exit();
        }
    }

    public function rename_plugin($new_name)
    {
        $file = __DIR__ . '/../seowriting.php';
        if (!is_readable($file) || !is_writable($file)) {
            return false;
        }
        $active_plugins = get_option('active_plugins');
        if (!empty($active_plugins) && is_array($active_plugins) && isset($active_plugins[0])) {
            foreach ($active_plugins as $idx => $active_plugin) {
                if (strpos($active_plugin, '/seowriting.php') !== false) {
                    $active_plugin = explode('/', $active_plugin);
                    $active_plugin[0] = $new_name;
                    $active_plugin = implode('/', $active_plugin);
                    $active_plugins[$idx] = $active_plugin;
                    update_option('active_plugins', $active_plugins);
                    break;
                }
            }
        }
        $current_dir = realpath(__DIR__ . '/../');
        $new_dir = realpath(__DIR__ . '/../../') . '/' . $new_name;
        if (file_exists($new_dir)) {
            $result = false;
        } else {
            /** @phpstan-ignore-next-line */
            $result = rename($current_dir, $new_dir);
        }
        return $result;
    }

    private function db_update_options($group)
    {
        foreach ($group as $key => $fields) {
            update_option($key, $fields);
        }
    }

    public function conectionAjax($action)
    {
        $nonce = sanitize_text_field($_POST['nonce']);
        $action = sanitize_text_field($_POST['aj']);
        if (!isset($nonce) || !wp_verify_nonce($nonce, 'ajax-nonce') || empty($action)) {
            exit();
        }
        $ret = [
            'success' => false
        ];
        if ($action === 'connect') {
            if ($this->plugin->isConnected()) {
                $ret['success'] = true;
                $ret['msg'] = $this->getMessageBox(false, 'Connection established');
                $ret['body'] = __('Your site is connected to SEOWRITING.AI', 'seowriting');
            } else {
                $cur_user = wp_get_current_user();

                $result = $this->plugin->connect([
                    'user_id' => $cur_user->ID,
                    'user_email' => $cur_user->user_email,
                    'webhook' => $this->getWebhookUrl(),
                    //'success_url' => $this->getReturnUrl('success'),
                    'failure_url' => $this->getReturnUrl('failure'),
                ]);

                if (isset($result['status'])) {
                    if ($result['status'] === 1) {
                        $ret['success'] = true;
                        $ret['auth_url'] = $result['auth_url'];
                    } elseif (isset($result['error'])) {
                        $ret['error'] = $this->getMessageBox(true, 'Error', $result['error']);
                    }
                }
            }
        } elseif ($action === 'disconnect') {
            if ($this->plugin->isConnected()) {
                $result = $this->plugin->disconnect();
            } else {
                $result = ['status' => 1];
            }

            if ($result['status'] === 1) {
                $ret['success'] = true;
                $ret['msg'] = $this->getMessageBox(false, 'Connection terminated');
                $ret['body'] = __('Your site is not connected to SEOWRITING.AI', 'seowriting');
            } elseif (isset($result['error'])) {
                $ret['error'] = $this->getMessageBox(true, 'Error', $result['error']);
            }
        }

        wp_send_json($ret);
    }

    public function render_input_text($name)
    {
        /** @phpstan-ignore-next-line */
        return '<input type="text" name="' . esc_html($name) . '" value="' . esc_html((string)get_option($name)) . '" class="form-control">';
    }

    public function render_select($name, $options)
    {
        $selectedVal = get_option($name);
        $html = '';
        $html .= '<select class="form-control" name="' . esc_html($name) . '">';
        foreach ($options as $key => $opt) {
            $selectedOpt = '';
            if (!empty($selectedVal) && $selectedVal === $key) {
                $selectedOpt = 'selected="selected"';
            }
            $html .= '<option value="' . esc_html($key) . '" ' . $selectedOpt . '>' . esc_html($opt) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}