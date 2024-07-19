<?php

namespace SEOWriting;

defined('WPINC') || exit;

include_once __DIR__ . '/../utils.php';

class APIClient
{
    /**
     * @var \SEOWriting
     */
    private $plugin;
    private $base_url = 'https://seowriting.ai/api/v1/';
    private $site_url;
    private $ssl_verify = false;
    private $http_timeout = 120;
    /**
     * @var string
     */
    public $error = '';

    const MAX_IMAGE_SIZE_KB = 1024;
    const MAX_FILENAME_LENGTH = 100;

    /**
     * @param \SEOWriting $plugin
     */
    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->site_url = substr($this->base_url, 0, (int)strpos($this->base_url, '/api/v1/'));
    }

    private function getSign($data, $secret)
    {
        $_data = $data;
        if (isset($_data['sign'])) {
            unset($_data['sign']);
        }
        ksort($_data);

        foreach ($_data as $k => $v) {
            if (is_array($v)) {
                $_data[$k] = 'Array';
            }
        }

        return hash_hmac('sha256', implode('|', $_data), $secret);
    }

    public function checkSign($data, $secret)
    {
        if (isset($data['sign'])) {
            return ($data['sign'] === $this->getSign($data, $secret));
        }
        return false;
    }

    private function request($endpoint, $body = [], $headers = [])
    {
        $headers = array_merge($headers, [
            'Content-Type' => 'application/json',
        ]);

        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'timeout' => $this->http_timeout,
            'sslverify' => $this->ssl_verify,
        ];

        if (!empty($body)) {
            $args['body'] = seowriting_json_encode_unescaped($body);
        }

        $url = $this->base_url . $endpoint;

        return wp_remote_request($url, $args);
    }

    public function rename($data)
    {
        $settings = $this->plugin->getSettings();
        $data['api_key'] = $settings['api_key'];
        if (function_exists('rest_url')) {
            $data['rest'] = rest_url($this->plugin->getRestNamespace());
        }
        $this->request('rename', $data);
        return true;
    }

    /**
     * @param string $newVersion
     * @return bool
     */
    public function update($newVersion)
    {
        $settings = $this->plugin->getSettings();
        $this->request('update', [
            'api_key' => $settings['api_key'],
            'new_version' => $newVersion,
        ]);

        return true;
    }

    /**
     * @param \WP_User $wpUser
     * @return array<string, int|string>
     */
    public function connect($wpUser)
    {
        $secret = md5(time() . mt_rand());

        $_data = [
            'name' => $wpUser['user_email'],
            'secret' => $secret,
            'webhook' => $wpUser['webhook'],
        ];
        if (isset($wpUser['success_url'])) {
            $_data['success_url'] = $wpUser['success_url'];
        }
        if (isset($wpUser['failure_url'])) {
            $_data['failure_url'] = $wpUser['failure_url'];
        }
        if (function_exists('rest_url')) {
            $_data['rest'] = rest_url($this->plugin->getRestNamespace());
        }

        $response = $this->request('connect', $_data);
        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return [
                'status' => 0,
                'error' => $this->error,
            ];
        }

        $result = wp_remote_retrieve_body($response);
        $data = @json_decode($result, true);
        if (is_array($data) && isset($data['status'])) {
            if ($data['status'] === 1) {
                $this->plugin->setSettings([
                    'user_id' => $wpUser['user_id'],
                    'name' => $wpUser['user_email'],
                    'secret' => $secret,
                    'api_key' => '',
                ]);
            }
            return $data;
        }

        $this->error = 'Error json_decode: ' . $result;
        return [
            'status' => 0,
            'error' => $this->error,
        ];
    }

    /**
     * @param $status string
     * @param $data
     */
    public function changePostStatus($status, $data)
    {
        return $this->request('post_' . $status, $data);
    }

    public function disconnect()
    {
        $settings = $this->plugin->getSettings();

        $response = $this->request('disconnect', [
            'api_key' => $settings['api_key'],
        ]);

        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return [
                'status' => 0,
                'error' => $this->error,
            ];
        }

        $result = wp_remote_retrieve_body($response);

        $data = @json_decode($result, true);
        if (is_array($data)) {
            $this->plugin->deleteSettings();
            return $data;
        }

        $this->error = 'Error json_decode: ' . $result;
        return [
            'status' => 0,
            'error' => $this->error,
        ];
    }

    public function checkImageUrl($url)
    {
        if (strpos($url, $this->site_url) === 0) {
            return preg_match('#^/docs/[0-9]+/[0-9a-z]+/[0-9]+/[0-9a-z]+\.(jpg|gif|png|webp)$#', substr($url, strlen($this->site_url)));
        }
        return false;
    }

    public function loadImage($url, $filename = '')
    {
        $settings = $this->plugin->getSettings();

        $args = [
            'headers' => [
                'API-Auth' => $settings['api_key']
            ],
            'timeout' => $this->http_timeout,
            'sslverify' => $this->ssl_verify,
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return false;
        } elseif (wp_remote_retrieve_response_code($response) === 200) {
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            $size = wp_remote_retrieve_header($response, 'content-length');
            if (is_array($size)) {
                $size = $size[0];
            }
            $size = (int)$size;
            $mimes = get_allowed_mime_types();

            if (($size > 0) && ($size <= (self::MAX_IMAGE_SIZE_KB * 1024)) && in_array($content_type, $mimes)) {
                $tmp_name = wp_tempnam();
                if (@file_put_contents($tmp_name, wp_remote_retrieve_body($response))) {
                    $image_size = @getimagesize($tmp_name);

                    if ($image_size && ($image_size['mime'] === $content_type)) {
                        $name = basename($url);
                        if (strlen($filename) > 0) {
                            $filename = trim($filename, " \n\r\t.?!;:/\\#");

                            if (strlen($filename) > 0) {
                                $ext = substr($name, (int)strrpos($name, '.'));
                                $max_length = self::MAX_FILENAME_LENGTH - strlen($ext);

                                if (mb_strlen($filename, \SEOWriting::MB_ENCODING) > $max_length) {
                                    $_name = mb_substr($filename, 0, $max_length, \SEOWriting::MB_ENCODING);

                                    if (preg_match('/^\s/us', mb_substr($filename, $max_length, 1, \SEOWriting::MB_ENCODING))) {
                                        $filename = trim($_name);
                                    } else {
                                        $filename = preg_replace('/^(.+)\s+\S+$/us', '\\1', $_name);
                                    }
                                }

                                $name = $filename . $ext;
                            }
                        }

                        return [
                            'name' => sanitize_file_name($name),
                            'type' => $image_size['mime'],
                            'tmp_name' => $tmp_name,
                            'error' => UPLOAD_ERR_OK,
                            'size' => $size
                        ];
                    }
                } else {
                    $this->error = 'file_put_contents(' . $tmp_name . ') ' . $size . ' bytes';
                }
                @unlink($tmp_name);
            }
        } else {
            $this->error = 'response_code=' . wp_remote_retrieve_response_code($response);
        }

        return false;
    }

    public function deleteImage($file)
    {
        if (isset($file['tmp_name']) && file_exists($file['tmp_name'])) {
            @unlink($file['tmp_name']);
        }
    }
}
