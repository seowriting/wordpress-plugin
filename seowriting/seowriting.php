<?php

/**
 * @link              https://seowriting.ai/
 * @since             1.1.8
 * @package           SEOWriting
 *
 * @wordpress-plugin
 * Plugin Name:       SEOWriting
 * Description:       SEOWriting - AI Writing Tool Plugin For Text Generation
 * Version:           1.1.9
 * Author:            SEOWriting
 * Author URI:        https://seowriting.ai/
 * License:           GPL-2.0 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       seowriting
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

include_once __DIR__ . '/utils.php';

if (!class_exists('SEOWriting')) {
    class SEOWriting {
        public $plugin_slug;
        public $plugin_path;
        public $version = '1.1.9';
        /**
         * @var \SEOWriting\APIClient|null
         */
        private $api_client = null;
        private $settings = null;
        private $log_path;

        const SETTINGS_KEY = 'seowriting_settings';
        const REST_VERSION = 1;
        const LOG_SIZE_KB = 128;
        const MB_ENCODING = 'UTF-8';

        public function __construct() {
            $this->plugin_slug = plugin_basename(__DIR__);
            $this->plugin_path = plugin_dir_path(__FILE__);

            if (is_admin()) {
                $this->adminPages();
                add_filter('plugin_action_links_'.plugin_basename($this->plugin_path. 'seowriting.php'), [$this, 'adminSettingsLink']);

                register_deactivation_hook(__FILE__, [$this, 'deactivate']);
            }

            if (wp_doing_ajax()){
                add_action('wp_ajax_seowriting-notify', [$this, 'ajaxWebhook']);
                add_action('wp_ajax_nopriv_seowriting-notify', [$this, 'ajaxWebhook']);
            }

            add_action('rest_api_init', [$this, 'initRest']);
            add_filter('wp_kses_allowed_html', [$this, 'ksesAllowedHtml'], 10, 2);

            add_filter( 'the_content', [$this, 'postContentFilter'], 20 );
            add_action("wp_head", [$this, 'shemasHeadFilter'], 20 );
        }

        public function ksesAllowedHtml($allowed, $context) {
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

        public function initRest() {
            register_rest_route($this->getRestNamespace(), '/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'restWebhook'],
                'permission_callback' => '__return_true'
            ]);
        }

        public function getRestNamespace() {
            return 'seowriting/v'.self::REST_VERSION;
        }

        /**
         * @param WP_REST_Request $request
         * @return WP_REST_Response
         */
        public function restWebhook($request) {
            $rs = $request->has_valid_params();
            if (is_wp_error($rs)) {
                $this->writeLog('restWebhook: ' . json_encode_unescaped($rs->get_error_messages()));
                return new WP_REST_Response(['message' => $rs->get_error_messages()], 400);
            }

            if (!$request->is_json_content_type()) {
                $content_type = $request->get_content_type();
                $error = 'Wrong Content-Type: '.(isset($content_type['value']) ? $content_type['value'] : '');

                $this->writeLog('restWebhook: '.$error);
                return new WP_REST_Response(['message' => $error], 400);
            }

            $ret = $this->webhook($request->get_json_params());
            return new WP_REST_Response($ret);
        }

        public function adminSettingsLink($links) {
            array_unshift($links,
                '<a href="'.esc_url($this->getPageUrl('seowriting-setting')).'">Settings</a>'
            );
            return $links;
        }

        public function getPageUrl($page) {
            $args = ['page' => $page];

            return add_query_arg($args, admin_url('options-general.php'));
        }

        public function getSettings() {
            if (is_null($this->settings)) {
                $this->settings = get_option(self::SETTINGS_KEY, []);
                if (!is_array($this->settings)) {
                    $this->settings = [];
                }
            }
            return $this->settings;
        }

        public function setSettings($data) {
            $modified = false;
            foreach ($data as $k=>$v) {
                if (is_null($v)) {
                    if (isset($this->settings[$k])) {
                        unset($this->settings[$k]);
                        $modified = true;
                    }
                }
                elseif (!isset($this->settings[$k]) || ($this->settings[$k] !== $v)) {
                    $this->settings[$k] = $v;
                    $modified = true;
                }
            }

            if ($modified) {
                if (count($this->settings) > 0) {
                    update_option(self::SETTINGS_KEY, $this->settings);
                }
                else {
                    delete_option(self::SETTINGS_KEY);
                }
            }
        }

        public function deleteSettings() {
            $this->settings = [];
            delete_option(self::SETTINGS_KEY);
        }

        /**
         * @return bool
         */
        public function isConnected() {
            $settings = $this->getSettings();
            return !empty($settings['api_key']);
        }

        public function connect($params) {
            return $this->getAPIClient()->connect($params);
        }

        public function disconnect() {
            return $this->getAPIClient()->disconnect();
        }

        private function getWebhookData() {
            $post = file_get_contents('php://input');
            if (!empty($post)) {
                $_data = @json_decode($post, true);
                if (is_array($_data)) {
                    return $_data;
                }
            }
            return [];
        }

        /**
         * @param array<string, string> $post
         * @return array<string, string>|null
         */
        private function webhook($post) {
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
                    }
                    elseif (($action === 'connect') && isset($post['api_key'])) {
                        $this->setSettings([
                            'api_key' => sanitize_text_field($post['api_key']),
                        ]);
                        $rs = [
                            'result' => 1,
                            'categories' => $this->getCategories()
                        ];
                    }
                    elseif ($action === 'disconnect') {
                        $this->deleteSettings();
                        $rs = [
                            'result' => 1
                        ];
                    }
                    elseif ($action === 'get_categories') {
                        $rs = [
                            'result' => 1,
                            'categories' => $this->getCategories()
                        ];
                    }
                    else {
                        $rs = [
                            'error' => 'Plugin does not support this feature'
                        ];
                    }

                    return array_merge($ret, $rs);
                }
            }
            return null;
        }

        public function ajaxWebhook() {
            $ret = $this->webhook($this->getWebhookData());
            if (is_array($ret)) {
                wp_send_json($ret);
            }
            exit();
        }

        public function renderContent($content){
            if (preg_match('#<section itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage">(.*?)</section>#s', $content, $matches)) {
                $fhtml = $matches[0];
                $fhtml = str_replace('itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage"', 'class="FAQPage"', $fhtml);

                $vowels = array(' itemscope', ' itemprop="mainEntity"', ' itemprop="text"', ' itemprop="name"', ' itemprop="acceptedAnswer"', ' itemtype="https://schema.org/Question"', ' itemtype="https://schema.org/Answer"');
                $fhtml = str_replace($vowels, "", $fhtml);
                $content = preg_replace('#<section itemscope itemprop="mainEntity" itemtype="https://schema.org/FAQPage">(.*?)</section>#s', $fhtml, $content);
            }
            return $content;
        }

        public function shemasHeadFilter( $content ){
            if ( is_single() ) {
                $post_id  = get_queried_object_id();
                $content = get_the_content( null, false, $post_id );
                $shema_type = get_option('sw_shema_type');
                $qa = $this->faqFilter($content);
                if(!empty($shema_type) && $shema_type === 'json' && $qa){
                    $questions = $qa[0];
                    $answers = $qa[1];

                    $items = '';
                    for ($i=0; $i < count($questions); $i++) {
                        if(isset($answers[$i]) && isset($questions[$i])){
                            $items .= '{'
                                        .'"@type": "Question",'
                                        .'"name": "'.$questions[$i].'",'
                                        .'"acceptedAnswer": {'
                                            .'"@type": "Answer",'
                                            .'"text": "'.$answers[$i].'"'
                                        .'}'
                                       .'}';
                            if($i != count($questions) - 1 ){
                                $items .= ',';
                            }
                        }
                    }
                    $out = '<script type="application/ld+json">'
                            .'{'
                            .'"@context": "https://schema.org",'
                            .'"@type": "FAQPage",'
                            .'"mainEntity": ['.$items.']'
                            .'}'
                            .'</script>';
                    echo $out;
                }
            }
        }

        private function faqFilter($html){
            if (preg_match('#<section class="FAQPage">(.*?)</section>#s', $html, $matches)) {
                $title = '';
                $fhtml = $matches[1];
                if(preg_match('#<h2>(.*?)</h2>#s', $fhtml, $titles)){
                    $title = $titles[1];
                    $fhtml = str_replace($titles[0], '', $fhtml);
                }
                $fhtml = strip_tags($fhtml, "<h3><p><b>");
                preg_match_all('#<h3>(.*?)</h3>#s', $fhtml, $questions);
                $questions = isset($questions[1]) ? $questions[1] : [];
                $answers = preg_split("#<h3>(.*?)</h3>#s", $fhtml);
                if (is_array($answers)) {
                    array_shift($answers);
                }
                return [$questions, $answers, $title];
            }
            return false;
        }

        public function postContentFilter( $content )
        {
            $shema_type = get_option('sw_shema_type');
            $qa = $this->faqFilter($content);
            if(is_single() && (empty($shema_type) || $shema_type === 'microdata') && $qa){
                $questions = $qa[0];
                $answers = $qa[1];
                $title = $qa[2];
                if(!empty($answers) && !empty($questions)){
                    $out = '<section itemscope itemtype="https://schema.org/FAQPage">';
                    $out .= '<h2>'.$title.'</h2>';
                    for ($i=0; $i < count($questions); $i++) {
                        if(isset($answers[$i]) && isset($questions[$i])){
                            $out .= '<div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">'
                                        .'<h3 itemprop="name">'.$questions[$i].'</h3>'
                                        .'<div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">'
                                            .'<div itemprop="text">'.$answers[$i].'</div>'
                                        .'</div>'
                                    .'</div>';
                        }
                    }
                    $out .= '</section>';

                    $content = preg_replace('#<section class="FAQPage">(.*?)</section>#s', $out, $content);
                }
            }
            return $content;
        }

        private function loadImages(&$data, $post_id, $featured_image) {
            $html = $data['html'];
            if (preg_match_all('/<img .*src="([^">]+)"[^>]*>/uU', $html, $matches)) {
                $data['images'] = [];
                include_once(ABSPATH.'wp-admin/includes/image.php');
                include_once(ABSPATH.'wp-admin/includes/file.php');
                include_once(ABSPATH.'wp-admin/includes/media.php');

                $api = $this->getAPIClient();
                $images = [];
                foreach ($matches[1] as $i=>$path) {
                    if ($api->checkImageUrl($path)) {
                        $alt = '';
                        if (preg_match('/ alt="([^"]*)"/u', $matches[0][$i], $_matches)) {
                            $alt = html_entity_decode($_matches[1], ENT_COMPAT | ENT_HTML401, self::MB_ENCODING);
                        }

                        $attachment_id = 0;
                        if (in_array($path, $images)) {
                            $attachment_id = array_search($path, $images);
                        }
                        elseif ($file = $api->loadImage($path, $alt)) {
                            $id = media_handle_sideload($file, $post_id);
                            $api->deleteImage($file);

                            if (is_wp_error($id)) {
                                $this->writeLog('media_handle_sideload(' . $file . ', ' . $post_id . '): ' . json_encode_unescaped($id->get_error_messages()));
                            }
                            else {
                                $attachment_id = $id;
                                $images[$id] = $path;

                                if (strlen($alt) > 0) {
                                    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
                                }
                            }
                        }
                        else {
                            $this->writeLog('loadImage('.$path.'): '.$api->error);
                        }

                        if ($attachment_id > 0) {
                            if ($featured_image) {
                                set_post_thumbnail($post_id, $attachment_id);
                                $featured_image = false;

                                $html = str_replace($matches[0][$i], '', $html);
                            }
                            else {
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

        /**
         * @param string $category
         * @return array<int>
         */
        private function getPostCategory($category) {
            if (strlen($category) > 0) {
                $categories = $this->getCategories();
                $ids = array_map('intval', explode(',', $category));

                $result = [];
                foreach ($categories as $cat) {
                    if (in_array($cat['id'], $ids)) {
                        $result[] = (int)$cat['id'];
                    }
                }

                if (count($result) > 0) {
                    return $result;
                }
            }
            return [0];
        }

        private function publishPost($user_id, $data) {
            $maxExecutionTime = (int)ini_get( 'max_execution_time' );
            @set_time_limit(120);

            $post_status = (isset($data['publish']) && intval($data['publish']) === 1) ? 'publish' : 'draft';
            $post_time = time();

            if (($post_status === 'publish') && isset($data['post_time'])) {
                $new_post_time = intval($data['post_time']);
                if ($new_post_time > $post_time) {
                    $post_time = $new_post_time;
                    $post_status = 'future';
                }
            }
            $content = wp_kses_post($this->renderContent($data['html']));
            $new_post = [
                'post_title' => sanitize_text_field($data['theme']),
                'post_content' => $content,
                'post_status' => $post_status,
                'post_date' => date('Y-m-d H:i:s', $post_time),
                'post_author' => $user_id,
                'post_type' => 'post',
                'post_category' => $this->getPostCategory(isset($data['category']) ? sanitize_text_field($data['category']) : ''),
            ];

            if (isset($data['post_slug'])) {
                $slug = sanitize_title($data['post_slug']);
                if (strlen($slug) > 0) {
                    $new_post['post_name'] = $slug;
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
                    $this->writeLog('Error wp_set_post_tags('.$post_id.'): '.$rs->get_error_message()
                        ."\nTags: ".implode(', ', $data['tags'])
                    );
                }
            }

            $this->loadImages($data, $post_id, isset($data['featured_image']));

            include_once(__DIR__.'/classes/post-meta.php');
            $pm = new \SEOWriting\PostMeta($post_id);
            $pm->set($data);

            @set_time_limit($maxExecutionTime);

            return [
                'result' => 1,
                'post_id' => $post_id,
                'url' => wp_get_shortlink($post_id),
            ];
        }

        /**
         * @return array<array<string, int|string>>
         */
        public function getCategories() {
            $categories = get_categories([
                'hide_empty' => 0,
                'number' => 100,
            ]);

            $array = [];
            foreach ($categories as $category) {
                /** @var WP_Term $category */
                $array[] = [
                    'id' => (int) $category->term_id,
                    'name' => $category->name,
                    'parent' => (int) $category->parent
                ];
            }
            return $array;
        }

        /**
         * @return \SEOWriting\APIClient
         */
        private function getAPIClient() {
            if (is_null($this->api_client)) {
                require_once($this->plugin_path.'classes/api-client.php');
                $this->api_client = new \SEOWriting\APIClient($this);
            }
            return $this->api_client;
        }

        public function adminPages() {
            include_once($this->plugin_path.'classes/settings-form.php');

            $form = new \SEOWriting\SettingsForm($this);
            $form->init();
        }

        public function deactivate() {
            if ($this->isConnected()) {
                $this->disconnect();
            }
            $this->deleteSettings();
        }

        private function writeLog($s) {
            if (!isset($this->log_path)) {
                $upload_dir = wp_upload_dir();

                $dir = $upload_dir['basedir'].DIRECTORY_SEPARATOR.'seowriting';
                $this->log_path = $dir.DIRECTORY_SEPARATOR.md5(ABSPATH.wp_salt()).'.log';

                if (!file_exists($dir)) {
                    @mkdir($dir);
                }
            }
            @file_put_contents($this->log_path,
                date('d.m.Y H:i:s').' '.$s."\n\n",
                ((file_exists($this->log_path) && (filesize($this->log_path) < (self::LOG_SIZE_KB*1024))) ? (FILE_APPEND | LOCK_EX) : 0)
            );
        }

        /**
         * @static
         * @return SEOWriting
         */
        public static function i() {
           return new SEOWriting();
        }
    }

    SEOWriting::i();
}