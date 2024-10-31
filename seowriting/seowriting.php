<?php

/**
 * @link              https://seowriting.ai/
 * @since             1.1.8
 * @package           SEOWriting
 *
 * @wordpress-plugin
 * Plugin Name:       SEOWriting
 * Description:       SEOWriting - AI Writing Tool Plugin For Text Generation
 * Version:           1.9.1
 * Author:            SEOWriting
 * Author URI:        https://seowriting.ai/?utm_source=wp_plugin
 * License:           GPL-2.0 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       seowriting
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
defined('WPINC') || exit;

include_once __DIR__ . '/utils.php';

if (!class_exists('SEOWriting')) {
    class SEOWriting
    {
        public $plugin_slug;
        public $plugin_path;
        public $version = '1.9.1';
        /**
         * @var \SEOWriting\APIClient|null
         */
        private $api_client = null;
        private $settings = null;
        private $log_file = __DIR__ . '/log.php';

        const SETTINGS_DEBUG_KEY = 'seowriting_debug';
        const SETTINGS_GENERATOR_NAME = 'seowriting';
        const SETTINGS_GENERATOR_NAME_KEY = 'seowriting_generator';
        const SETTINGS_KEY = 'seowriting_settings';
        const SETTINGS_PLUGIN_NAME_KEY = 'seowriting_plugin_name';
        const SETTINGS_PLUGIN_VERSION = 'seowriting_plugin_version';
        const REST_VERSION = 1;
        const MB_ENCODING = 'UTF-8';

        const SCHEMA_TYPE_JSON = 'json';
        const SCHEMA_TYPE_MICRODATA = 'microdata';
        const SCHEMA_TYPE_OFF = 'off';

        const SEOWRITING_PHP = 'seowriting.php';

        public function __construct()
        {

            set_exception_handler(function ($e) {
                $record = [
                    'type' => 'exceptionHandler',
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'backtrace' => []
                ];
                foreach ($e->getTrace() as $item) {
                    if (isset($item['file'])) {
                        $file = $item['file'];
                    } else {
                        $file = 'unknown';
                    }
                    if (isset($item['line'])) {
                        $line = $item['line'];
                    } else {
                        $line = -1;
                    }
                    $record['backtrace'][] = [
                        $file,
                        $line
                    ];
                }
                $this->writeLog($record);
            });

            $this->plugin_slug = plugin_basename(__DIR__);
            $this->plugin_path = plugin_dir_path(__FILE__);

            if (is_admin()) {
                $this->adminPages();
                add_filter('plugin_action_links_' . plugin_basename($this->plugin_path . self::SEOWRITING_PHP), [$this, 'adminSettingsLink']);

                register_deactivation_hook(__FILE__, [$this, 'deactivate']);
                register_activation_hook(__FILE__, [$this, 'activate']);
            }

            if (wp_doing_ajax()) {
                add_action('wp_ajax_seowriting-notify', [$this, 'ajaxWebhook']);
                add_action('wp_ajax_nopriv_seowriting-notify', [$this, 'ajaxWebhook']);
            }

            add_action('rest_api_init', [$this, 'initRest']);
            add_filter('wp_kses_allowed_html', [$this, 'ksesAllowedHtml'], 10, 2);

            add_filter('the_content', [$this, 'restoreSchemaSection'], 20);
            add_action("wp_head", [$this, 'printJSONLD'], 20);

            add_action('transition_post_status', [$this, 'onChangePostStatus'], 10, 3);
            add_action('upgrader_process_complete', [$this, 'onUpdate'], 10, 2);

            add_action('requests-requests.before_parse', [$this, 'onAfterRequest'], 10, 6);
            add_action('plugins_loaded', [$this, 'onPluginsLoaded']);

        }

        public function onPluginsLoaded()
        {
            /** @phpstan-ignore-next-line */
            $prevVersion = (string)get_option(self::SETTINGS_PLUGIN_VERSION);
            /** @phpstan-ignore-next-line */
            $pluginName = (string)get_option(self::SETTINGS_PLUGIN_NAME_KEY);
            if (strlen($pluginName) > 0 && $pluginName !== basename(__DIR__)) {
                include_once __DIR__ . '/classes/settings-form.php';
                $form = new \SEOWriting\SettingsForm($this);
                $form->rename_plugin($pluginName);
            }
        }

        public function activate()
        {
            update_option(self::SETTINGS_PLUGIN_VERSION, $this->version);
        }

        public function onAfterRequest(&$response, $url, $headers, $data, $type, $options)
        {
            if (strpos($url, 'seowriting') === false || strpos($url, '.zip') === false) {
                return;
            }
            if (!is_array($options) || !isset($options['filename']) || !is_readable($options['filename'])) {
                return;
            }
            if (!class_exists('ZipArchive')) {
                return;
            }
            /** @phpstan-ignore-next-line */
            $pluginName = (string)get_option(self::SETTINGS_PLUGIN_NAME_KEY);
            if (strlen($pluginName) === 0) {
                return;
            }

            $filename = $options['filename'];
            $tmpDir = sys_get_temp_dir() . '/' . uniqid('seowriting_');
            mkdir($tmpDir);
            $newDir = $tmpDir . '/' . $pluginName;
            $zip = new ZipArchive();
            if ($zip->open($filename)) {
                $zip->extractTo($tmpDir);
                $zip->close();
                rename($tmpDir . '/seowriting/', $newDir);
                $resultFile = $tmpDir . '/' . basename($filename);
                $result = new ZipArchive();
                if ($result->open($resultFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                    $result->addEmptyDir($pluginName);
                    seowriting_add_file_to_zip($newDir, $result, $pluginName);
                    $result->close();
                    copy($resultFile, $filename);
                    seowriting_delete_dir($tmpDir);
                }
            }
        }

        /**
         * @param $new_status string
         * @param $old_status string
         * @param $post WP_Post
         * @return bool
         */
        public function onChangePostStatus($new_status, $old_status, $post)
        {
            $status = '';
            if (
                ($old_status === 'auto-draft' && $new_status === 'publish')
                || ($old_status === 'pending' && $new_status === 'publish')
                || ($old_status === 'draft' && $new_status === 'publish')
                || ($old_status === 'publish' && $new_status === 'publish')
            ) {
                $status = 'update';
            } else if (
                ($old_status === 'publish' && $new_status === 'pending')
                || ($old_status === 'publish' && $new_status === 'draft')
                || ($old_status === 'publish' && $new_status === 'trash')
            ) {
                $status = 'delete';
            }
            if ($status === '') {
                return false;
            }
            $settings = $this->getSettings();
            $this->getAPIClient()->changePostStatus($status, [
                'post_id' => $post->ID,
                'api_key' => $settings['api_key'],
            ]);

            return true;
        }

        /**
         * @param $upgrader_object
         * @param $options
         * @return bool
         */
        public function onUpdate($upgrader_object, $options)
        {
            return $this->getAPIClient()->update($this->version);
        }

        public function ksesAllowedHtml($allowed, $context)
        {
            if (!is_array($context) && ($context === 'post')) {
                $allowed['div']['itemscope'] = true;
                $allowed['div']['itemprop'] = true;
                $allowed['div']['itemtype'] = true;
                $allowed['h3']['itemprop'] = true;
                $allowed['a']['rel'] = true;
                $allowed['a']['target'] = true;
            }
            return $allowed;
        }

        public function initRest()
        {
            register_rest_route($this->getRestNamespace(), '/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'restWebhook'],
                'permission_callback' => '__return_true'
            ]);
            register_rest_route($this->getRestNamespace(), '/debug', [
                'methods' => 'POST',
                'callback' => [$this, 'restDebug'],
                'permission_callback' => '__return_true'
            ]);
            register_rest_route($this->getRestNamespace(), '/log', [
                'methods' => 'POST',
                'callback' => [$this, 'restLog'],
                'permission_callback' => '__return_true'
            ]);
        }

        public function getRestNamespace()
        {
            return 'seowriting/v' . self::REST_VERSION;
        }

        /**
         * @param WP_REST_Request $request
         * @return WP_REST_Response|WP_Error
         */
        public function restLog($request)
        {
            $res = [];
            $post = $request->get_json_params();
            $settings = $this->getSettings();
            $client = $this->getAPIClient();
            if (isset($settings['secret'])
                && isset($post['sign'])
                && $client->checkSign($post, $settings['secret'])
                && is_readable($this->log_file)
            ) {
                // @phpstan-ignore-next-line
                $res = json_decode('[' . str_replace("}\n", "},", trim(explode('?>', @file_get_contents($this->log_file))[1])) . ']', true);
            }
            return new WP_REST_Response($res, is_array($res) && count($res) > 0 ? 200 : 400);
        }

        /**
         * @param WP_REST_Request $request
         * @return WP_REST_Response|WP_Error
         */
        public function restDebug($request)
        {
            $res = [];
            $post = $request->get_json_params();
            $settings = $this->getSettings();
            $client = $this->getAPIClient();
            if (
                get_option('seowriting_debug') === 'yes'
                && isset($settings['secret'])
                && isset($post['sign'])
                && $client->checkSign($post, $settings['secret'])) {
                include_once __DIR__ . '/../../../wp-admin/includes/plugin.php';
                $plugins = [];
                if (function_exists('get_plugins')) {
                    foreach (get_plugins() as $pluginFile => $settings) {
                        $plugins[] = [
                            'name' => seowriting_escape(explode('/', $pluginFile)[0]),
                            'version' => seowriting_escape($settings['Version']),
                            'active' => is_plugin_active($pluginFile),
                        ];
                    }
                }
                $res = [
                    'engine' => [
                        'name' => 'wordpress',
                        'version' => seowriting_escape(get_bloginfo('version')),
                    ],
                    'php' => [
                        'version' => seowriting_escape(PHP_VERSION),
                    ],
                    'webServer' => [
                        'name' => seowriting_escape(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown'),
                    ],
                    'plugins' => $plugins,
                ];
            }

            return new WP_REST_Response($res, count($res) > 0 ? 200 : 400);
        }

        /**
         * @param WP_REST_Request $request
         * @return WP_REST_Response|WP_Error
         */
        public function restWebhook($request)
        {

            $rs = $request->has_valid_params();
            if (is_wp_error($rs)) {
                $this->writeLog([
                    'type' => 'restWebhookValidation',
                    'data' => $rs->get_error_messages(),
                ]);
                return new WP_REST_Response(['message' => $rs->get_error_messages()], 400);
            }

            // WP 4.9 compatibility, do not edit
            $contentType = $request->get_content_type();
            if (!is_array($contentType) || !isset($contentType['value']) || $contentType['value'] !== 'application/json') {
                $error = 'Wrong Content-Type: ' . (isset($contentType['value']) ? $contentType['value'] : '');

                $this->writeLog([
                    'type' => 'restWebhookContentType',
                    'message' => $error,
                ]);
                return new WP_REST_Response(['message' => $error], 400);
            }

            $ret = $this->webhook($request->get_json_params());
            return is_array($ret)
                ? new WP_REST_Response($ret)
                : new WP_REST_Response([
                    'message' => 'You do not have permission to access this resource.',
                ], 400);

        }

        public function adminSettingsLink($links)
        {
            array_unshift($links,
                '<a href="' . esc_url($this->getPageUrl('seowriting-setting')) . '">Settings</a>'
            );
            return $links;
        }

        public function getPageUrl($page)
        {
            $args = ['page' => $page];

            return add_query_arg($args, admin_url('options-general.php'));
        }

        public function getSettings()
        {
            if (is_null($this->settings)) {
                $this->settings = get_option(self::SETTINGS_KEY, []);
                if (!is_array($this->settings)) {
                    $this->settings = [];
                }
            }
            return $this->settings;
        }

        public function setSettings($data)
        {
            $modified = false;
            foreach ($data as $k => $v) {
                if (is_null($v)) {
                    if (isset($this->settings[$k])) {
                        unset($this->settings[$k]);
                        $modified = true;
                    }
                } elseif (!isset($this->settings[$k]) || ($this->settings[$k] !== $v)) {
                    $this->settings[$k] = $v;
                    $modified = true;
                }
            }

            if ($modified) {
                if (count($this->settings) > 0) {
                    update_option(self::SETTINGS_KEY, $this->settings);
                } else {
                    delete_option(self::SETTINGS_KEY);
                }
            }
        }

        public function deleteSettings()
        {
            $this->settings = [];

            delete_option(self::SETTINGS_KEY);
            delete_option(self::SETTINGS_DEBUG_KEY);
            delete_option(self::SETTINGS_PLUGIN_NAME_KEY);
        }

        /**
         * @return bool
         */
        public function isConnected()
        {
            $settings = $this->getSettings();
            return !empty($settings['api_key']);
        }

        public function connect($params)
        {
            return $this->getAPIClient()->connect($params);
        }

        public function disconnect()
        {
            return $this->getAPIClient()->disconnect();
        }

        private function getWebhookData()
        {
            $post = file_get_contents('php://input');
            if (!empty($post)) {
                $_data = @json_decode($post, true);
                if (is_array($_data)) {
                    return $_data;
                }
            }
            return [];
        }

        public function rename($data)
        {
            return $this->getAPIClient()->rename($data);
        }

        /**
         * @param array<string, string> $post
         * @return array<string, string>|null
         */
        private function webhook($post)
        {
            $settings = $this->getSettings();

            if (isset($settings['secret']) && isset($post['sign'])) {
                $client = $this->getAPIClient();
                if ($client->checkSign($post, $settings['secret'])) {
                    $action = isset($post['action']) ? sanitize_text_field($post['action']) : '';
                    $ret = [
                        'result' => 0,
                        'version' => $this->version,
                    ];

                    if ($action === 'post') {
                        $rs = $this->publishPost($settings['user_id'], $post);
                    } elseif (($action === 'connect') && isset($post['api_key'])) {
                        $this->setSettings([
                            'api_key' => sanitize_text_field($post['api_key']),
                        ]);
                        $rs = [
                            'result' => 1,
                            'categories' => $this->getCategories(),
                            'authors' => $this->getAuthors(),
                            'types' => $this->getPostTypes(),
                        ];
                    } elseif ($action === 'disconnect') {
                        $this->deleteSettings();
                        $rs = [
                            'result' => 1
                        ];
                    } elseif ($action === 'get_categories') {
                        $rs = [
                            'result' => 1,
                            'categories' => $this->getCategories(isset($post['page_type']) ? $post['page_type'] : 'post')
                        ];
                    } elseif ($action === 'get_authors') {
                        $rs = [
                            'result' => 1,
                            'authors' => $this->getAuthors()
                        ];
                    } elseif ($action === 'get_posts') {
                        $rs = [
                            'result' => 1,
                            'posts' => $this->getPosts(
                                intval(isset($post['page']) ? sanitize_text_field($post['page']) : '1'),
                                intval(isset($post['per_page']) ? sanitize_text_field($post['per_page']) : '50')
                            )
                        ];
                    } elseif ($action === 'get_post') {
                        $rs = [
                            'result' => 1,
                            'post' => $this->getPost(isset($post['post_id']) ? sanitize_text_field($post['post_id']) : '')
                        ];
                    }  elseif ($action === 'get_post_slug') {
                        $rs = [
                            'result' => 1,
                            'slug' => get_permalink(intval($post['post_id']))
                        ];
                    } elseif ($action === 'get_version') {
                        $rs = [
                            'result' => 1,
                            'version' => $this->getVersion()
                        ];
                    } elseif ($action == 'get_meta') {
                        $rs = [
                            'result' => 1,
                            'authors' => $this->getAuthors(),
                            'categories' => $this->getCategories(isset($post['page_type']) ? $post['page_type'] : 'post'),
                            'types' => $this->getPostTypes(),
                            'version' => $this->getVersion()
                        ];
                    }  elseif ($action === 'search_pages') {
                        $rs = [
                            'result' => 1,
                            'pages' => $this->searchPages(sanitize_text_field($post['q']), isset($post['limit']) ? intval($post['limit']) : 10)
                        ];
                    } else {
                        $rs = [
                            'error' => 'Plugin does not support this feature'
                        ];
                    }

                    return array_merge($ret, $rs);
                }
            }
            return null;
        }

        private function isDisabledSchema()
        {
            return get_option('sw_shema_type') === self::SCHEMA_TYPE_OFF;
        }

        public function ajaxWebhook()
        {
            $ret = $this->webhook($this->getWebhookData());
            if (is_array($ret)) {
                wp_send_json($ret);
            }
            exit();
        }

        /**
         * @param $content
         * @return string
         */
        private function clearSchemaSection($content)
        {
            if (preg_match('#<section itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage">(.*?)</section>#s', $content, $matches)) {
                $html = $matches[0];
                $html = str_replace('itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage"', 'class="schema-section"', $html);
                $items = array(' itemscope', ' itemprop="mainEntity"', ' itemprop="text"', ' itemprop="name"', ' itemprop="acceptedAnswer"', ' itemtype="https://schema.org/Question"', ' itemtype="https://schema.org/Answer"');
                $html = str_replace($items, "", $html);
                $content = preg_replace('#<section itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage">(.*?)</section>#s', $html, $content);
            }

            return $content;
        }

        /**
         * @return bool
         */
        private function isJSONSchema()
        {
            return get_option('sw_shema_type') === self::SCHEMA_TYPE_JSON;
        }

        /**
         * @return bool
         */
        private function isMicrodataSchema()
        {
            return get_option('sw_shema_type') === self::SCHEMA_TYPE_MICRODATA;
        }

        public function printJSONLD()
        {
            if (!is_single() || !$this->isJSONSchema()) {
                return;
            }
            // WP 4.9 compatibility, do not edit
            $post = get_post(get_queried_object_id());
            if ($post instanceof WP_Post) {
                $content = $post->post_content;
            } else {
                return;
            }
            $qa = $this->qaList($content);
            if (!is_array($qa) || !isset($qa[1])) {
                return;
            }
            $questions = $qa[0];
            $answers = $qa[1];
            $count = count($questions);
            $items = '';
            for ($i = 0; $i < $count; $i++) {
                if (isset($answers[$i]) && isset($questions[$i])) {
                    $items .= '{'
                        . '"@type": "Question",'
                        . '"name": "' . esc_html($questions[$i]) . '",'
                        . '"acceptedAnswer": {'
                        . '"@type": "Answer",'
                        . '"text": "' . esc_html($answers[$i]) . '"'
                        . '}'
                        . '}';
                    if ($i != $count - 1) {
                        $items .= ',';
                    }
                }
            }
            echo '<script type="application/ld+json">'
                . '{'
                . '"@context": "https://schema.org",'
                . '"@type": "FAQPage",'
                . '"mainEntity": [' . $items . ']'
                . '}'
                . '</script>';
        }

        private function qaList($html)
        {
            if (preg_match('#<section class="schema-section">(.*?)</section>#s', $html, $matches)) {
                $title = '';
                $fhtml = $matches[1];
                if (preg_match('#<h2>(.*?)</h2>#s', $fhtml, $titles)) {
                    $title = $titles[1];
                    $fhtml = str_replace($titles[0], '', $fhtml);
                }
                $fhtml = strip_tags($fhtml, "<h3><p><b>");
                preg_match_all('#<h3>(.*?)</h3>#s', $fhtml, $questions);
                $questions = isset($questions[1]) ? $questions[1] : [];
                $answers = preg_split('#<h3>(.*?)</h3>#s', $fhtml);
                if (is_array($answers)) {
                    array_shift($answers);
                    foreach ($answers as $idx => $answer) {
                        $answers[$idx] = trim(str_replace(PHP_EOL, "", strip_tags($answer)));
                    }
                }
                return [$questions, $answers, $title];
            }

            return false;
        }

        public function restoreSchemaSection($content)
        {
            if (!is_single() || $this->isJSONSchema()) {
                return $content;
            }
            $qa = $this->qaList($content);
            if (!isset($qa[2]) || !isset($qa[0][0]) || !isset($qa[1][0])) {
                return $content;
            }
            $questions = $qa[0];
            $answers = $qa[1];
            $title = $qa[2];
            $count = count($questions);

            $isDisabled = !$this->isMicrodataSchema() && $this->isDisabledSchema();
            $out = '<section' . ($isDisabled ? '' : ' itemscope itemtype="https://schema.org/FAQPage"') . '>';
            $out .= '<h2>' . $title . '</h2>';
            for ($i = 0; $i < $count; $i++) {
                if (isset($answers[$i]) && isset($questions[$i])) {
                    $out .= '<div' . ($isDisabled ? '' : ' itemscope itemprop="mainEntity" itemtype="https://schema.org/Question"') . '>'
                        . '<h3' . ($isDisabled ? '' : ' itemprop="name"') . '>' . $questions[$i] . '</h3>'
                        . '<div' . ($isDisabled ? '' : ' itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"') . '>'
                        . '<div' . ($isDisabled ? '' : ' itemprop="text"') . '>' . $answers[$i] . '</div>'
                        . '</div>'
                        . '</div>';
                }
            }
            $out .= '</section>';

            $content = preg_replace('#<section class="schema-section">(.*?)</section>#s', $out, $content);

            return $content;
        }

        private function downloadImages(&$data, $post_id, $featured_image)
        {
            $html = $data['html'];

            if (preg_match_all('/<img .*src="([^">]+)"[^>]*>/uU', $html, $matches)) {
                $data['images'] = [];
                include_once(ABSPATH . 'wp-admin/includes/image.php');
                include_once(ABSPATH . 'wp-admin/includes/file.php');
                include_once(ABSPATH . 'wp-admin/includes/media.php');
                $api = $this->getAPIClient();
                $images = [];
                foreach ($matches[1] as $i => $path) {
                    if ($api->checkImageUrl($path)) {
                        $alt = '';
                        if (preg_match('/ alt="([^"]*)"/u', $matches[0][$i], $_matches)) {
                            $alt = html_entity_decode($_matches[1], ENT_COMPAT | ENT_HTML401, self::MB_ENCODING);
                        }

                        $attachment_id = 0;
                        if (in_array($path, $images)) {
                            $attachment_id = array_search($path, $images);
                        } elseif ($file = $api->loadImage($path, $alt)) {
                            $id = media_handle_sideload($file, $post_id);
                            $api->deleteImage($file);

                            if (is_wp_error($id)) {
                                $this->writeLog([
                                    'type' => 'downloadImagesWpError',
                                    'data' => $id->get_error_messages(),
                                ]);
                            } else {
                                $attachment_id = $id;
                                $images[$id] = $path;

                                if (strlen($alt) > 0) {
                                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
                                }
                            }
                        } else {
                            $this->writeLog([
                                'type' => 'downloadImagesWpError',
                                'path' => $path,
                                'data' => $api->error,
                            ]);
                        }

                        if ($attachment_id > 0) {
                            if ($featured_image) {
                                set_post_thumbnail($post_id, $attachment_id);
                                $featured_image = false;

                                $html = str_replace($matches[0][$i], '', $html);
                            } else {
                                $data['images'][wp_get_attachment_url($attachment_id)] = $attachment_id;
                                $html = str_replace($matches[0][$i],
                                    get_image_tag($attachment_id, $alt, $alt, 'center', 'large'),
                                    $html
                                );
                            }
                        }
                    }
                }

                if (count($images) > 0) {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_content' => wp_slash($html)
                    ]);
                }

                $data['html'] = $html;
            }
        }

        private function publishPost($user_id, $data)
        {
            global $current_user;

            $maxExecutionTime = (int)ini_get('max_execution_time');
            @set_time_limit(120);

            $post_status = (isset($data['publish']) && intval($data['publish']) === 1) ? 'publish' : 'draft';
            $post_time = time();

            if (($post_status === 'publish') && isset($data['post_time'])) {
                $new_post_time = intval($data['post_time']);
                if ($new_post_time > 0) {
                    if ($new_post_time > $post_time) {
                        $post_status = 'future';
                    }
                    $post_time = $new_post_time;
                }
            }
            $content = wp_kses_post(
                $this->clearSchemaSection(
                    str_replace('itemscope="" itemprop=', 'itemscope itemprop=', $data['html'])
                )
            );
            $data['html'] = $content;
            if (isset($data['author_id'])) {
                $user_id = (int)$data['author_id'];
            }
            $current_user = new WP_User($user_id);
            $post_type = isset($data['post_type']) ? $data['post_type'] : 'post';
            $new_post = [
                'post_title' => sanitize_text_field($data['theme']),
                'post_content' => $content,
                'post_status' => $post_status,
                'post_date' => date('Y-m-d H:i:s', $post_time),
                'post_author' => $user_id,
                'post_type' => $post_type,
                'post_excerpt' => isset($data['excerpt']) && (int)$data['excerpt'] === 1
                    ? (isset($data['description']) ? sanitize_text_field($data['description']) : '')
                    : '',
            ];

            if (in_array('category', get_object_taxonomies($post_type))) {
                $new_post['post_category'] =  array_map('intval', explode(',', isset($data['category']) ? sanitize_text_field($data['category']) : '0'));
            } else {
                $ids = array_map('intval', explode(',', sanitize_text_field($data['category'])));
                foreach ($ids as $id) {
                    $term = get_term($id);
                    if (!is_wp_error($term) && $term) {
                        /** @phpstan-ignore-next-line */
                        $taxonomy_name = $term->taxonomy;
                        if (!isset($new_post['tax_input'])) {
                            $new_post['tax_input'] = [];
                        }
                        if (!isset($new_post['tax_input'][$taxonomy_name])) {
                            $new_post['tax_input'][$taxonomy_name] = [];
                        }
                        $new_post['tax_input'][$taxonomy_name][] = $id;
                    }
                }
            }

            if (isset($data['post_slug'])) {
                $slug = sanitize_title($data['post_slug']);
                if (strlen($slug) > 0) {
                    $new_post['post_name'] = $slug;
                }
            }

            if (isset($data['post_type']) && $data['post_type'] === 'page' && isset($data['parent_id'])) {
                global $wpdb;
                $new_post['post_parent'] = $data['parent_id'];
                if (isset($data['sort_order'])) {
                    $new_post['menu_order'] = $data['sort_order'];
                } else {
                    $sort_order = $wpdb->get_var($wpdb->prepare("select max(menu_order)+1 from $wpdb->posts where post_parent = %s", $data['parent_id']));
                    if ($sort_order) {
                        $new_post['menu_order'] = $sort_order;
                    }
                }
            }

            $post_id = wp_insert_post($new_post);

            if (is_wp_error($post_id)) {
                return [
                    'result' => 0,
                    'error' => $post_id->get_error_message(),
                ];
            }

            if (!empty($data['tags']) && is_array($data['tags'])) {
                $rs = wp_set_post_tags($post_id, $data['tags']);
                if (is_wp_error($rs)) {
                    $this->writeLog([
                        'type' => 'setPostTags',
                        'id' => $post_id,
                        'data' => $rs->get_error_message(),
                        'tags' => $data['tags'],
                    ]);
                }
            }

            $this->downloadImages($data, $post_id, isset($data['featured_image']));

            include_once(__DIR__ . '/classes/post-meta.php');
            $pm = new \SEOWriting\PostMeta($post_id);
            $pm->set($data);

            @set_time_limit($maxExecutionTime);

            add_post_meta($post_id, self::SETTINGS_GENERATOR_NAME_KEY, self::SETTINGS_GENERATOR_NAME, true);

            return [
                'result' => 1,
                'post_id' => $post_id,
                'url' => wp_get_shortlink($post_id),
                'slug' => get_permalink($post_id)
            ];
        }

        public function onSearchPages($where, $wp_query)
        {
            global $wpdb;
            if ($search_term = $wp_query->get('post_title_like')) {
                /** @phpstan-ignore-next-line */
                $where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'%' . esc_sql($wpdb->esc_like($search_term)) . '%\'';
            }

            return $where;
        }

        /**
         * @param string $query
         * @param int $limit
         */
        public function searchPages($query, $limit = 10)
        {
            if ($limit < 10) {
                $limit = 10;
            }

            add_filter('posts_where', [$this, 'onSearchPages'], 10, 2);
            $pages = new WP_Query([
                'post_type' => 'page',
                'post_title_like' => $query,
                'posts_per_page' => $limit,
                'post_status' => 'publish',
            ]);
            remove_filter('posts_where', [$this, 'onSearchPages']);

            $res = [];
            if ($pages->have_posts()) {
                while ($pages->have_posts()) {
                    $pages->the_post();
                    $res[] = [
                        'id' => get_the_ID(),
                        'title' => get_the_title(),
                    ];
                }
                wp_reset_postdata();
            }

            return $res;
        }


        public function getVersion()
        {
            return $this->version;
        }

        public function getPost($post_id)
        {
            $post = get_post($post_id);
            if (!$post || !($post instanceof WP_Post) || $post->post_status !== 'publish') {
                return false;
            }
            return [
                'id' => (int)$post->ID,
                'content' => $post->post_content,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID)
            ];
        }

        public function getPosts($page = 1, $per_page = 50)
        {
            $result = [];

            $query = new WP_Query([
                'post_type' => ['page', 'post'],
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'order' => 'DESC',
                'orderby' => 'ID'
            ]);
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $result[] = [
                        'id' => intval(get_the_ID()),
                        'content' => get_the_content(),
                        'title' => get_the_title(),
                        'url' => get_permalink()
                    ];
                }
            }
            wp_reset_postdata();

            return $result;
        }

        /**
         * @return array<array<string, int|string>>
         */
        public function getAuthors()
        {
            $users = get_users([
                'role__in' => ['administrator', 'author', 'editor']
            ]);
            $settings = $this->getSettings();
            $settingsUserID = isset($settings['user_id']) ? (int)$settings['user_id'] : 0;
            $result = [];
            foreach ($users as $user) {
                $data = [
                    'id' => (int)$user->ID,
                    'login' => $user->data->user_login,
                ];
                if ($settingsUserID > 0 && (int)$user->ID === $settingsUserID) {
                    $data['default'] = 1;
                }
                $result[] = $data;
            }

            return $result;
        }

        public function getPostTypes()
        {
            return array_merge(['post', 'page'], get_post_types(['public' => true, '_builtin' => false]));
        }

        /**
         * @return array<array<string, int|string>>
         */
        public function getCategories($page_type = 'post')
        {
            $taxonomies = get_object_taxonomies($page_type, 'names');
            $result = [];
            foreach ($taxonomies as $taxonomy) {
                $term_query = new WP_Term_Query([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                ]);

                if (!empty($term_query->terms)) {
                    foreach ($term_query->terms as $term) {
                        if ($term->taxonomy === 'post_tag' || $term->taxonomy === 'elementor_library_type') {
                            continue;
                        }
                        $result[] = [
                            'id' => (int)$term->term_id,
                            'name' => $term->name,
                            'parent' => (int)$term->parent
                        ];
                    }
                }
            }

            return $result;
        }

        /**
         * @return \SEOWriting\APIClient
         */
        public function getAPIClient()
        {
            if (is_null($this->api_client)) {
                require_once($this->plugin_path . 'classes/api-client.php');
                $this->api_client = new \SEOWriting\APIClient($this);
            }
            return $this->api_client;
        }

        public function adminPages()
        {
            include_once($this->plugin_path . 'classes/settings-form.php');

            $form = new \SEOWriting\SettingsForm($this);
            $form->init();
        }

        public function deactivate()
        {
            if ($this->isConnected()) {
                $this->disconnect();
            }
            $this->deleteSettings();
        }

        private function writeLog($s)
        {
            if (!is_array($s)) {
                $s = [
                    'source' => $s,
                ];
            }
            $s['now'] = date('Y-m-d H:i:s');
            if (!file_exists($this->log_file)) {
                @file_put_contents($this->log_file, "<?php die(); ?>\n\n");
            }
            @file_put_contents($this->log_file, seowriting_json_encode_unescaped($s) . "\n", FILE_APPEND);
        }

        /**
         * @static
         * @return SEOWriting
         */
        public static function i()
        {
            return new SEOWriting();
        }
    }

    SEOWriting::i();
}