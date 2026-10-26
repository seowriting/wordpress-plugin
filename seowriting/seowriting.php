<?php

/**
 * @link              https://seowriting.ai/
 * @since             1.1.8
 * @package           SEOWriting
 *
 * @wordpress-plugin
 * Plugin Name:       SEOWriting
 * Description:       SEOWriting - AI Writing Tool Plugin For Text Generation
 * Version:           1.13.0
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
        public $version = '1.13.0';
        /**
         * @var \SEOWriting\APIClient|null
         */
        private $api_client = null;
        private $settings = null;
        private $log_file = __DIR__ . '/log.php';
        private $css_file = __DIR__ . '/style.css';
        private $default_css_file = __DIR__ . '/default.css';

        const SETTINGS_CSS_KEY = "seowriting_css";
        const SETTINGS_CSS_HASH_KEY = "seowriting_css_hash";
        const SETTINGS_GENERATOR_NAME = 'seowriting';
        const SETTINGS_GENERATOR_NAME_KEY = 'seowriting_generator';
        const SETTINGS_GENERATOR_PAGE_TYPE = 'seowriting_page_type';
        const SETTINGS_INIT = 'seowriting_init';
        const SETTINGS_KEY = 'seowriting_settings';
        const SETTINGS_PLUGIN_NAME_KEY = 'seowriting_plugin_name';
        const SETTINGS_PLUGIN_VERSION_KEY = 'seowriting_plugin_version';
        const SETTINGS_ACTIVATION_REDIRECT = 'seowriting_activation_redirect';
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
                add_action('admin_init', [$this, 'redirectAfterActivation']);

                register_deactivation_hook(__FILE__, [$this, 'deactivate']);
                register_activation_hook(__FILE__, [$this, 'activate']);
            }

            if (wp_doing_ajax()) {
                add_action('wp_ajax_seowriting-notify', [$this, 'ajaxWebhook']);
                add_action('wp_ajax_nopriv_seowriting-notify', [$this, 'ajaxWebhook']);
            }

            add_action('rest_api_init', [$this, 'initRest']);
            add_filter('wp_kses_allowed_html', [$this, 'ksesAllowedHtml'], 10, 2);

            add_filter('the_content', [$this, 'onContent'], 20);
            add_action("wp_head", [$this, 'printJSONLD'], 20);

            add_action('upgrader_process_complete', [$this, 'onUpdate'], 10, 2);

            add_action('requests-requests.before_parse', [$this, 'onAfterRequest'], 10, 6);
            add_action('plugins_loaded', [$this, 'onPluginsLoaded']);

            add_action('init', [$this, 'onInit']);
        }

        public function onPluginsLoaded()
        {
            /** @phpstan-ignore-next-line */
            $pluginName = (string)get_option(self::SETTINGS_PLUGIN_NAME_KEY);
            if (strlen($pluginName) > 0 && $pluginName !== basename(__DIR__)) {
                include_once __DIR__ . '/classes/settings-form.php';
                $form = new \SEOWriting\SettingsForm($this);
                $form->rename_plugin($pluginName);
            }
        }

        public function activate($redirect = true)
        {
            update_option(self::SETTINGS_PLUGIN_VERSION_KEY, $this->version);
            $this->setCSS($this->readCSS());
            if ($redirect) {
                add_option(self::SETTINGS_ACTIVATION_REDIRECT, true, '', 'no');
            }
        }

        public function redirectAfterActivation()
        {
            if (is_network_admin() || !get_option(self::SETTINGS_ACTIVATION_REDIRECT)) {
                return;
            }

            delete_option(self::SETTINGS_ACTIVATION_REDIRECT);
            wp_safe_redirect($this->getPageUrl('seowriting-setting'));
            exit;
        }

        /**
         * @return string
         */
        private function readCSS()
        {
            return (string)(is_readable($this->css_file) ? @file_get_contents($this->css_file) : (is_readable($this->default_css_file) ? @file_get_contents($this->default_css_file) : ''));
        }

        public function onAfterRequest(&$response, $url, $headers, $data, $type, $options)
        {
            if (strpos($url, 'seowriting') === false || strpos($url, '.zip') === false) {
                return;
            }
            if (
                !is_array($options)
                || !isset($options['filename'])
                || !is_string($options['filename'])
                || !is_readable($options['filename'])
            ) {
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
            $fs = seowriting_get_filesystem();
            $fs->mkdir($tmpDir);
            $newDir = $tmpDir . '/' . $pluginName;
            $zip = new ZipArchive();
            if ($zip->open($filename)) {
                $zip->extractTo($tmpDir);
                $zip->close();
                $fs->move($tmpDir . '/seowriting/', $newDir);
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
         * @param $upgrader_object
         * @param $options
         * @return bool
         */
        public function onUpdate($upgrader_object, $options)
        {
            if (
                !is_array($options)
                || !isset($options['action'])
                || $options['action'] !== 'update'
                || !isset($options['type'])
                || $options['type'] !== 'plugin'
                || !isset($options['plugins'])
                || !is_array($options['plugins'])
                || !isset($options['plugins'][0])
            ) {
                return false;
            }
            foreach ($options['plugins'] as $plugin) {
                if (is_string($plugin) && strpos($plugin, 'seowriting.php') !== false) {
                    update_option(self::SETTINGS_INIT, true);
                    return true;
                }
            }

            return false;
        }

        public function onInit()
        {
            if (!get_option(self::SETTINGS_INIT)) {
                return;
            }
            delete_option(self::SETTINGS_INIT);
            $this->activate(false);
            $this->getAPIClient()->update($this->version);
        }

        public function ksesAllowedHtml($allowed, $context)
        {
            if (!is_array($context) && ($context === 'post')) {
                $allowed['a']['rel'] = true;
                $allowed['a']['target'] = true;
                $allowed['div']['itemscope'] = true;
                $allowed['div']['itemprop'] = true;
                $allowed['div']['itemtype'] = true;
                $allowed['h3']['itemprop'] = true;
                $allowed['iframe'] = [
                    'src' => true,
                    'height' => true,
                    'width' => true,
                    'frameborder' => true,
                    'allowfullscreen' => true,
                    'allow' => true,
                    'loading' => true,
                    'referrerpolicy' => true,
                    'sandbox' => true,
                    'title' => true,
                    'onload' => true,
                    'class' => true
                ];
                $allowed['input'] = [
                    'class' => true,
                    'placeholder' => true,
                    'type' => true
                ];
                $allowed['option'] = [
                    'class' => true
                ];
                $allowed['select'] = [
                    'class' => true
                ];
                $allowed['textarea'] = [
                    'class' => true,
                    'placeholder' => true
                ];
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
         * @param WP_REST_Request<array<string, mixed>> $request
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
         * @param WP_REST_Request<array<string, mixed>> $request
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
            $post = @file_get_contents('php://input');
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
                    } elseif ($action === 'get_post_slug') {
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
                    } elseif ($action === 'search_pages') {
                        $rs = [
                            'result' => 1,
                            'pages' => $this->searchPages(sanitize_text_field($post['q']), isset($post['limit']) ? intval($post['limit']) : 10)
                        ];
                    } elseif ($action === 'update_post') {
                        $rs = $this->updatePost($post);
                    } elseif ($action === 'set_css') {
                        $err = $this->setCSS(sanitize_text_field($post['css']));
                        $rs = [
                            'result' => strlen($err) > 0 ? 0 : 1,
                            'error' => $err,
                        ];
                    } elseif ($action === 'get_stat') {
                        include_once __DIR__ . '/../../../wp-admin/includes/plugin.php';
                        $plugins = [];
                        if (function_exists('get_plugins')) {
                            foreach (get_plugins() as $pluginFile => $settings) {
                                $plugins[] = [
                                    'name' => seowriting_escape(explode('/', $pluginFile)[0]),
                                    'version' => seowriting_escape($settings['Version']),
                                    'active' => is_plugin_active($pluginFile),
                                    'author_uri' => seowriting_escape($settings['AuthorURI'])
                                ];
                            }
                        }
                        $theme = wp_get_theme();
                        $rs = [
                            'result' => 1,
                            'stat' => [
                                'engine' => [
                                    'name' => 'wordpress',
                                    'version' => seowriting_escape(get_bloginfo('version')),
                                ],
                                'php' => [
                                    'version' => seowriting_escape(PHP_VERSION),
                                ],
                                'web_server' => [
                                    'name' => seowriting_escape(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown'),
                                ],
                                'plugins' => $plugins,
                                'theme' => [
                                    'name' => $theme->get('Name'),
                                    'version' => $theme->get('Version'),
                                    'uri' => $theme->get('ThemeURI'),
                                ]
                            ]
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
         * The FAQ block is put back as plain text (str_replace). With preg_replace() the block was the replacement string,
         * where "$20" means "matched group 20": "under $200" was stored as "under 0"
         * @param string $content
         * @return string
         */
        private function clearSchemaSection($content)
        {
            if (preg_match('#<section itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage">(.*?)</section>#s', $content, $matches)) {
                $html = $matches[0];
                $html = str_replace('itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage"', 'class="schema-section"', $html);
                $items = array(' itemscope', ' itemprop="mainEntity"', ' itemprop="text"', ' itemprop="name"', ' itemprop="acceptedAnswer"', ' itemtype="https://schema.org/Question"', ' itemtype="https://schema.org/Answer"');
                $html = str_replace($items, "", $html);
                $pos = strpos($content, $matches[0]);
                if ($pos !== false) {
                    $content = substr_replace($content, $html, $pos, strlen($matches[0]));
                }
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

        /**
         * @param string $html
         * @param bool $anyH3 true: questions may be <h3> with attributes (Super Page), false: plain <h3> only
         * @return array{array<int, string>, array<int, string>, string}|false
         */
        private function qaList($html, $anyH3 = true)
        {
            $h3 = $anyH3 ? '<h3[^>]*>' : '<h3>';
            if (preg_match('#<section class="schema-section">(.*?)</section>#s', $html, $matches)) {
                $title = '';
                $fhtml = $matches[1];
                if (preg_match('#<h2>(.*?)</h2>#s', $fhtml, $titles)) {
                    $title = $titles[1];
                    $fhtml = str_replace($titles[0], '', $fhtml);
                }
                $fhtml = strip_tags($fhtml, "<h3><p><b>");
                preg_match_all('#' . $h3 . '(.*?)</h3>#s', $fhtml, $questions);
                $questions = $questions[1];
                $answers = preg_split('#' . $h3 . '.*?</h3>#s', $fhtml) ?: [];
                array_shift($answers);
                foreach ($answers as $idx => $answer) {
                    $answers[$idx] = trim(str_replace(PHP_EOL, "", wp_strip_all_tags($answer)));
                }
                return [$questions, $answers, $title];
            }

            return false;
        }

        /**
         * Super Page FAQ: keeps the markup and classes of the section (they carry its styles)
         * and adds only the FAQPage microdata
         * @param string $content
         * @return string
         */
        private function addFaqMicrodata($content)
        {
            $result = preg_replace_callback('#<section class="schema-section">(.*?)</section>#s', function ($matches) {
                $html = str_replace(
                    ['<div class="faq-item">', '<h3 class="faq-question">'],
                    [
                        '<div class="faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">',
                        '<h3 class="faq-question" itemprop="name">',
                    ],
                    $matches[1]
                );
                $html = preg_replace(
                    [
                        '#<div class="faq-answer">(.*?)</div>#s',
                        '#<p class="faq-answer">(.*?)</p>#s',
                    ],
                    [
                        '<div class="faq-answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><div itemprop="text">$1</div></div>',
                        '<p class="faq-answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><span itemprop="text">$1</span></p>',
                    ],
                    $html
                );
                if (!is_string($html)) {
                    return $matches[0];
                }
                // never print half of the markup: every question needs its name and its answer
                $count = substr_count($html, 'itemtype="https://schema.org/Question"');
                if ($count === 0
                    || substr_count($html, 'itemprop="name"') !== $count
                    || substr_count($html, 'itemtype="https://schema.org/Answer"') !== $count
                ) {
                    return $matches[0];
                }
                return '<section itemscope itemtype="https://schema.org/FAQPage">' . $html . '</section>';
            }, $content, 1);

            return is_string($result) ? $result : $content;
        }

        private function addStyles($content)
        {
            if ((is_single() || is_page()) && get_post_meta((int)get_the_ID(), self::SETTINGS_GENERATOR_NAME_KEY, true) == self::SETTINGS_GENERATOR_NAME && is_readable($this->css_file)) {
                $content = str_replace('styled-container', 'styled-container-' . md5((string)microtime(true)), '<style>' . $this->readCSS() . '</style>' . $content);
            }
            return $content;
        }

        public function onContent($content)
        {
            if (!is_single() && !is_page()) {
                return $content;
            }
            if ($this->isJSONSchema()) {
                return $this->addStyles($content);
            }
            // Super Page FAQ keeps its own markup: its classes carry the styles
            $isSuperPage = preg_match('#<section class="schema-section">(.*?)</section>#s', $content, $section)
                && strpos($section[1], '<div class="faq-item">') !== false
                && strpos($section[1], '<h3 class="faq-question">') !== false;
            $qa = $this->qaList($content, $isSuperPage);
            if (!isset($qa[2]) || !isset($qa[0][0]) || !isset($qa[1][0])) {
                return $this->addStyles($content);
            }
            $questions = $qa[0];
            $answers = $qa[1];
            $title = $qa[2];
            $count = count($questions);

            $isDisabled = !$this->isMicrodataSchema() && $this->isDisabledSchema();
            if ($isSuperPage) {
                return $this->addStyles($isDisabled ? $content : $this->addFaqMicrodata($content));
            }
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

            // $out goes in through a callback: as a replacement string "$20" in an answer would mean "matched group 20"
            $result = preg_replace_callback('#<section class="schema-section">(.*?)</section>#s', function () use ($out) {
                return $out;
            }, $content);

            return $this->addStyles(is_string($result) ? $result : $content);
        }

        private function deleteImages($post_id)
        {
            $images = get_children([
                'post_parent' => $post_id,
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'numberposts' => -1,
            ]);

            if (is_array($images)) {
                foreach ($images as $attachment) {
                    if (!is_object($attachment) || get_class($attachment) !== 'WP_Post') {
                        continue;
                    }
                    wp_delete_attachment($attachment->ID, true);
                }
            }
        }

        private function downloadImages(&$data, $post_id, $featured_image, $is_update = false)
        {
            $html = $data['html'];

            if ($is_update === true) {
                $this->deleteImages($post_id);
            }

            if (preg_match_all('/<img .*src="([^">]+)"[^>]*>/uU', $html, $matches)) {
                $data['images'] = [];
                include_once(ABSPATH . 'wp-admin/includes/image.php');
                include_once(ABSPATH . 'wp-admin/includes/file.php');
                include_once(ABSPATH . 'wp-admin/includes/media.php');
                $api = $this->getAPIClient();
                $images = [];
                foreach ($matches[1] as $i => $path) {
                    $alt = '';
                    if (preg_match('/ alt="([^"]*)"/u', $matches[0][$i], $_matches)) {
                        $alt = html_entity_decode($_matches[1], ENT_COMPAT | ENT_HTML401, self::MB_ENCODING);
                    }

                    $attachment_id = 0;
                    if (isset($images[$path])) {
                        $attachment_id = $images[$path];
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
                            $images[$path] = $id;

                            if (strlen($alt) > 0) {
                                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
                            }
                        }
                    } else {
                        $this->writeLog([
                            'type' => 'downloadImagesApiError',
                            'path' => $path,
                            'data' => $api->error,
                        ]);
                    }

                    if ($attachment_id > 0) {
                        if ($featured_image) {
                            set_post_thumbnail($post_id, $attachment_id);
                            $featured_image = false;

                            $html = $this->removeFeaturedImage($html, $matches[0][$i]);
                        } else {
                            $data['images'][wp_get_attachment_url($attachment_id)] = $attachment_id;
                            $html = str_replace($matches[0][$i],
                                get_image_tag($attachment_id, $alt, $alt, 'center', 'large'),
                                $html
                            );
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

        /**
         * Removes the image that became the featured image, together with the wrapper
         * (and the caption) that would stay empty without it
         * @param string $html
         * @param string $img the <img> tag as it is written in $html
         * @return string
         */
        private function removeFeaturedImage($html, $img)
        {
            $tag = preg_quote($img, '#');
            $result = preg_replace(
                [
                    '#<div class="img-container">\s*' . $tag . '\s*(?:<p class="img-caption">(?:(?!</?(?:p|div|section)\b).)*</p>\s*)?</div>#s',
                    '#<p>\s*' . $tag . '\s*</p>#s',
                ],
                '',
                $html
            );

            return str_replace($img, '', is_string($result) ? $result : $html);
        }

        /**
         * Item 5 of PATCH.md: the time zone of the post date. Both dates are sent (post_date in the time of the site,
         * post_date_gmt in UTC), a draft stays without a GMT date, edit_date lets an update set the dates,
         * and a post that is already public is not hidden by a time ahead
         */
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
            $content = wp_kses(
                $this->clearSchemaSection(
                    str_replace('itemscope="" itemprop=', 'itemscope itemprop=', $data['html'])
                ),
                wp_kses_allowed_html('post')
            );
            $data['html'] = trim($content);
            if (isset($data['author_id'])) {
                $user_id = (int)$data['author_id'];
            }
            $current_user = new WP_User($user_id);
            $post_type = isset($data['post_type']) ? $data['post_type'] : 'post';
            // post_time is a Unix time. WordPress reads post_date as the local time of the site and post_date_gmt as UTC:
            // gmdate() in post_date with no post_date_gmt moved the post by the UTC offset of the site
            // (west of UTC "Publish" became a scheduled post, east of UTC a scheduled post went out early)
            $post_date_gmt = gmdate('Y-m-d H:i:s', $post_time);
            $new_post = [
                'post_title' => sanitize_text_field($data['theme']),
                'post_content' => $content,
                'post_status' => $post_status,
                'post_date' => get_date_from_gmt($post_date_gmt),
                // a draft keeps the WordPress mark "no date yet": it gets its date when it is published
                'post_date_gmt' => ($post_status === 'draft') ? '0000-00-00 00:00:00' : $post_date_gmt,
                // without edit_date wp_update_post() drops both dates when the post to update is still a draft
                'edit_date' => ($post_status !== 'draft'),
                'post_author' => $user_id,
                'post_type' => $post_type,
                'post_excerpt' => isset($data['excerpt']) && (int)$data['excerpt'] === 1
                    ? (isset($data['description']) ? sanitize_text_field($data['description']) : '')
                    : '',
            ];

            if (in_array('category', get_object_taxonomies($post_type))) {
                $new_post['post_category'] = array_map('intval', explode(',', isset($data['category']) ? sanitize_text_field($data['category']) : '0'));
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

            $is_update = is_array($data) && isset($data['post_id']);
            if ($is_update) {
                $new_post['ID'] = intval($data['post_id']);
                // a post that is already public is not taken off the site: with a date ahead WordPress would make it
                // a scheduled post and hide it until then. Its content is updated, its status and both dates stay
                if (($post_status === 'future') && (get_post_status($new_post['ID']) === 'publish')) {
                    $new_post['post_status'] = 'publish';
                    unset($new_post['post_date'], $new_post['post_date_gmt'], $new_post['edit_date']);
                }
                $post_id = wp_update_post($new_post, true);
            } else {
                $post_id = wp_insert_post($new_post, true);
            }
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

            $this->downloadImages($data, $post_id, isset($data['featured_image']), $is_update);

            include_once(__DIR__ . '/classes/post-meta.php');
            $pm = new \SEOWriting\PostMeta($post_id);
            $pm->set($data);

            @set_time_limit($maxExecutionTime);

            add_post_meta($post_id, self::SETTINGS_GENERATOR_NAME_KEY, self::SETTINGS_GENERATOR_NAME, true);
            add_post_meta($post_id, self::SETTINGS_GENERATOR_PAGE_TYPE, isset($data['ct'])  ? seowriting_escape($data['ct']) : 'unk', true);

            return [
                'result' => 1,
                'post_id' => $post_id,
                'url' => wp_get_shortlink($post_id),
                'slug' => get_permalink($post_id)
            ];
        }

        /**
         * Update of an existing post from the SEO WRITING API (PUT /v1/site/{site_id}/posts/{post_id}), webhook action "update_post".
         * Only the given fields change: html (post content), post_title, seo_title / seo_description (active SEO plugins).
         * Status, dates, author, slug, categories, tags, excerpt, featured image and the images attached to the post stay as they are
         * (publishPost() resets them on an update and downloadImages() deletes the attached images). Images of the new HTML that are
         * not on this site are downloaded and attached, images already on the site are kept. Before the change the current state is
         * saved as a revision (when the site keeps revisions), so it can be restored in the WordPress editor.
         *
         * @param array<string, mixed> $data
         * @return array<string, mixed>
         */
        private function updatePost($data)
        {
            $post_id = (isset($data['post_id']) && is_numeric($data['post_id'])) ? intval($data['post_id']) : 0;
            $post = ($post_id > 0) ? get_post($post_id) : null;
            if (!($post instanceof WP_Post)
                || !in_array($post->post_type, $this->getPostTypes(), true)
                || in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)
            ) {
                return [
                    'result' => 0,
                    'error' => 'post_not_found',
                ];
            }

            $update = ['ID' => $post_id];
            if (isset($data['html'])) {
                if (get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder') {
                    // Elementor shows _elementor_data, not post_content: a new content would not appear on the page
                    return [
                        'result' => 0,
                        'error' => 'page_builder',
                    ];
                }

                // wp_kses() removes the <script> / <style> tags but keeps their text: they go with their content first
                $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', is_scalar($data['html']) ? (string)$data['html'] : '');
                $html = wp_kses(
                    $this->clearSchemaSection(str_replace('itemscope="" itemprop=', 'itemscope itemprop=', is_string($raw) ? $raw : '')),
                    $this->updateAllowedHtml()
                );
                $html = trim($this->removeForeignIframes($html));
                if ($html === '') {
                    return [
                        'result' => 0,
                        'error' => 'empty_content',
                    ];
                }

                @set_time_limit(120);
                $update['post_content'] = $this->importImages($html, $post_id);
            }

            if (isset($data['post_title'])) {
                $title = sanitize_text_field(is_scalar($data['post_title']) ? (string)$data['post_title'] : '');
                if ($title === '') {
                    return [
                        'result' => 0,
                        'error' => 'empty_title',
                    ];
                }
                $update['post_title'] = $title;
            }

            $revisions = wp_revisions_enabled($post);
            $revision_id = 0;
            if (count($update) > 1) {
                if ($revisions) {
                    // the state before the change; skipped by WordPress when the latest revision already has it
                    $rev = wp_save_post_revision($post_id);
                    if (is_int($rev) && ($rev > 0)) {
                        $revision_id = $rev;
                    } else {
                        $last = wp_get_post_revisions($post_id, ['numberposts' => 1]);
                        if (is_array($last) && (count($last) > 0)) {
                            reset($last);
                            $revision_id = (int)key($last);
                        }
                    }
                }

                // wp_update_post() keeps every field that is not passed and expects slashed values
                $rs = wp_update_post(wp_slash($update), true);
                if (is_wp_error($rs)) {
                    return [
                        'result' => 0,
                        'error' => $rs->get_error_message(),
                    ];
                }
            }

            $seo = [];
            if (isset($data['seo_title']) || isset($data['seo_description'])) {
                include_once(__DIR__ . '/classes/post-meta.php');
                $pm = new \SEOWriting\PostMeta($post_id);
                $seo = $pm->setSeo(
                    (isset($data['seo_title']) && is_scalar($data['seo_title'])) ? (string)$data['seo_title'] : null,
                    (isset($data['seo_description']) && is_scalar($data['seo_description'])) ? (string)$data['seo_description'] : null
                );
            }

            return [
                'result' => 1,
                'post_id' => $post_id,
                'url' => get_permalink($post_id),
                'revision_id' => $revision_id,
                'revisions' => $revisions ? 1 : 0,
                'seo' => $seo,
            ];
        }

        /**
         * Tags of an updated post: the ones WordPress allows in posts, plus iframe (removeForeignIframes() keeps YouTube / Vimeo only),
         * so the video players of an existing post survive an update
         *
         * @return array<string, array<string, bool>>
         */
        private function updateAllowedHtml()
        {
            $allowed = wp_kses_allowed_html('post');
            $allowed['iframe'] = [
                'src' => true,
                'width' => true,
                'height' => true,
                'title' => true,
                'frameborder' => true,
                'allow' => true,
                'allowfullscreen' => true,
                'loading' => true,
                'referrerpolicy' => true,
                'style' => true,
                'class' => true,
            ];
            return $allowed;
        }

        /**
         * @param string $html
         * @return string
         */
        private function removeForeignIframes($html)
        {
            $result = preg_replace_callback('#<iframe\b[^>]*>.*?</iframe>|<iframe\b[^>]*>#is', function ($matches) {
                if (preg_match('#\ssrc=["\']https://(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com)/#i', $matches[0])) {
                    return $matches[0];
                }
                return '';
            }, $html);
            return is_string($result) ? $result : $html;
        }

        /**
         * Images of an updated post: one that is not on this site is downloaded into the media library, attached to the post and put
         * in place of the original tag (as downloadImages()); one already on the site (relative url, the site or uploads host) stays as
         * it is. A failed download keeps the original tag. At most 10 downloads per update, the rest stay as they are.
         *
         * @param string $html
         * @param int $post_id
         * @return string
         */
        private function importImages($html, $post_id)
        {
            if (!preg_match_all('/<img .*src="([^">]+)"[^>]*>/uU', $html, $matches)) {
                return $html;
            }

            include_once(ABSPATH . 'wp-admin/includes/image.php');
            include_once(ABSPATH . 'wp-admin/includes/file.php');
            include_once(ABSPATH . 'wp-admin/includes/media.php');
            $api = $this->getAPIClient();
            $max_downloads = 10;
            $downloads = 0;
            $tags = [];

            foreach ($matches[1] as $i => $src) {
                $url = html_entity_decode($src, ENT_QUOTES, self::MB_ENCODING);
                if ($this->isLocalImage($url)) {
                    continue;
                }

                if (!isset($tags[$url])) {
                    if ($downloads >= $max_downloads) {
                        continue;
                    }
                    $downloads++;
                    $tags[$url] = '';

                    $alt = '';
                    if (preg_match('/ alt="([^"]*)"/u', $matches[0][$i], $_matches)) {
                        $alt = html_entity_decode($_matches[1], ENT_COMPAT | ENT_HTML401, self::MB_ENCODING);
                    }

                    $file = $api->loadImage($url, $alt);
                    if ($file) {
                        $id = media_handle_sideload($file, $post_id);
                        $api->deleteImage($file);

                        if (is_wp_error($id)) {
                            $this->writeLog([
                                'type' => 'updatePostImageWpError',
                                'data' => $id->get_error_messages(),
                            ]);
                        } else {
                            if (strlen($alt) > 0) {
                                update_post_meta($id, '_wp_attachment_image_alt', $alt);
                            }
                            $tags[$url] = get_image_tag($id, $alt, $alt, 'center', 'large');
                        }
                    } else {
                        $this->writeLog([
                            'type' => 'updatePostImageApiError',
                            'path' => $url,
                            'data' => $api->error,
                        ]);
                    }
                }

                if ($tags[$url] !== '') {
                    $html = str_replace($matches[0][$i], $tags[$url], $html);
                }
            }

            return $html;
        }

        /**
         * True for an image that is already on this site: a relative url, or the host of the site, its home or its uploads
         * (also a CDN set as the uploads url); "www." is ignored. Urls without a host (data:...) are kept as they are too.
         *
         * @param string $url
         * @return bool
         */
        private function isLocalImage($url)
        {
            if ((strpos($url, '//') !== 0) && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
                return true;
            }

            $host = parse_url((strpos($url, '//') === 0) ? 'https:' . $url : $url, PHP_URL_HOST);
            if (!is_string($host) || ($host === '')) {
                return true;
            }
            $host = preg_replace('/^www\./', '', strtolower($host));

            $own = [home_url(), site_url()];
            $uploads = wp_upload_dir(null, false);
            if (is_array($uploads) && !empty($uploads['baseurl'])) {
                $own[] = $uploads['baseurl'];
            }
            foreach ($own as $own_url) {
                $own_host = parse_url((string)$own_url, PHP_URL_HOST);
                if (is_string($own_host) && (preg_replace('/^www\./', '', strtolower($own_host)) === $host)) {
                    return true;
                }
            }
            return false;
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
         * @param string $css
         * @return string
         */
        public function setCSS($css)
        {
            $css = trim($css);
            if (strlen($css) === 0) {
                return "empty css";
            }

            delete_option(self::SETTINGS_CSS_KEY);
            if (intval(update_option(self::SETTINGS_CSS_KEY, $css)) === 1) {
                file_put_contents($this->css_file, $css);
            } else {
                return "cannot update css option";
            }
            delete_option(self::SETTINGS_CSS_HASH_KEY);
            if (!update_option(self::SETTINGS_CSS_HASH_KEY, md5($css))) {
                return "cannot update css hash option";
            }

            return "";
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
            delete_option(self::SETTINGS_ACTIVATION_REDIRECT);
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
            $s['now'] = gmdate('Y-m-d H:i:s');
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
