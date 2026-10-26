<?php

namespace SEOWriting;

use DOMDocument;

defined('WPINC') || exit;

require_once(ABSPATH . 'wp-admin/includes/plugin.php');
include_once __DIR__ . '/../utils.php';

class PostMeta
{
    private $post_id;
    private $meta_keys = null;

    const PLUGIN_ALL_IN_ONE = 'all-in-one-seo-pack/all_in_one_seo_pack.php';
    const PLUGIN_ALL_IN_ONE_PRO = 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php';
    const PLUGIN_ELEMENTOR = 'elementor/elementor.php';
    const PLUGIN_RANK_MATH = 'seo-by-rank-math/rank-math.php';
    const PLUGIN_SEO_FRAMEWORK = 'autodescription/autodescription.php';
    const PLUGIN_SEOPRESS = 'wp-seopress/seopress.php';
    const PLUGIN_SQUIRRLY_SEO = 'squirrly-seo/squirrly.php';
    const PLUGIN_YOAST = 'wordpress-seo/wp-seo.php';
    const PLUGIN_YOAST_PRO = 'wordpress-seo-premium/wp-seo-premium.php';

    public function __construct($post_id)
    {
        $this->post_id = $post_id;

        if (function_exists('get_registered_meta_keys')) {
            $this->meta_keys = array_keys(get_registered_meta_keys('post'));
        }
    }

    private function setValue($k, $v, $check_key = false)
    {
        if (!$check_key || is_null($this->meta_keys) || in_array($k, $this->meta_keys)) {
            $v = wp_strip_all_tags($v, true);
            if (strlen($v) === 0) {
                $_old_value = get_post_meta($this->post_id, $k, true);
                if (!empty($_old_value)) {
                    delete_post_meta($this->post_id, $k);
                }
            } else {
                update_post_meta($this->post_id, $k, $v);
            }
        }
    }

    public function set($data)
    {
        $title = isset($data['title']) ? $data['title'] : '';
        $description = isset($data['description']) ? $data['description'] : '';
        $main_keyword = isset($data['main_keyword']) ? $data['main_keyword'] : '';
        $keywords = isset($data['keywords']) ? $data['keywords'] : [];
        $all_keywords = count($keywords) > 0 ? implode(',', $keywords) : $main_keyword;

        if (
            is_plugin_active(self::PLUGIN_ELEMENTOR)
            && get_option('seowriting_split_to_elementor') === 'yes'
        ) {
            $this->setValue('_elementor_edit_mode', 'builder');
            $this->setValue('_elementor_template_type', 'wp-post');
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . self::PLUGIN_ELEMENTOR);
            $this->setValue('_elementor_version', $plugin_data['Version']);
            include_once __DIR__ . '/html2elementor.php';
            $html2elementor = new HTML2Elementor($data['html']);
            update_post_meta($this->post_id, '_elementor_data', str_replace('\"', '\\\"', str_replace('\/', '\\\/', seowriting_json_encode_unescaped($html2elementor->get()))));
        }

        if (is_plugin_active(self::PLUGIN_YOAST) || is_plugin_active(self::PLUGIN_YOAST_PRO)) {
            $this->setValue('_yoast_wpseo_title', $title);
            $this->setValue('_yoast_wpseo_metadesc', $description);
            $this->setValue('_yoast_wpseo_focuskw', $main_keyword);
            $list = [];
            foreach(array_slice($keywords, 1) as $item) {
                $list[] = [
                    'keyword' => $item,
                    'score' => 0,
                ];
            }
            $this->setValue('_yoast_wpseo_focuskeywords', seowriting_json_encode_unescaped($list));
        }

        if (is_plugin_active(self::PLUGIN_ALL_IN_ONE) || is_plugin_active(self::PLUGIN_ALL_IN_ONE_PRO)) {
            $this->setValue('_aioseo_title', $title);
            $this->setValue('_aioseo_description', $description);
            $this->setValue('_aioseo_keywords', $main_keyword);
            $this->setValue('_aioseo_og_title', $title);
            $this->setValue('_aioseo_og_description', $description);

            try {
                $path = WP_PLUGIN_DIR . '/' . (is_plugin_active(self::PLUGIN_ALL_IN_ONE) ? self::PLUGIN_ALL_IN_ONE : self::PLUGIN_ALL_IN_ONE_PRO);
                include_once($path);
                $list = [];
                foreach(array_slice($keywords, 1) as $item) {
                    $list[] = [
                        'keyphrase' => $item,
                        'analysis' => [],
                    ];
                }
                // @phpstan-ignore-next-line
                \AIOSEO\Plugin\Common\Models\Post::savePost($this->post_id, [
                    'title' => $title,
                    'description' => $description,
                    'keywords' => $main_keyword,
                    'keyphrases' => [
                        'focus' => [
                            'keyphrase' => $main_keyword,
                            'analysis' => [],
                        ],
                        'additional' => $list,
                    ],
                    'og_title' => $title,
                    'og_description' => $description,
                ]);
            } catch (\Exception $e) {

            }
        }

        if (is_plugin_active(self::PLUGIN_RANK_MATH)) {
            $this->setValue('rank_math_title', $title);
            $this->setValue('rank_math_description', $description);
            $this->setValue('rank_math_focus_keyword', $all_keywords);
        }

        if (is_plugin_active(self::PLUGIN_SEOPRESS)) {
            $this->setValue('_seopress_titles_title', $title);
            $this->setValue('_seopress_titles_desc', $description);
            $this->setValue('_seopress_analysis_target_kw', $all_keywords);
            $this->setValue('_seopress_social_fb_title', $title);
            $this->setValue('_seopress_social_fb_desc', $description);
            $this->setValue('_seopress_social_twitter_title', $title);
            $this->setValue('_seopress_social_twitter_desc', $description);
        }

        if (is_plugin_active(self::PLUGIN_SEO_FRAMEWORK)) {
            $this->setValue('_genesis_title', $title);
            $this->setValue('_genesis_description', $description);
            $this->setValue('_open_graph_title', $title);
            $this->setValue('_open_graph_description', $description);
            $this->setValue('_twitter_title', $title);
            $this->setValue('_twitter_description', $description);
        }

        if (is_plugin_active(self::PLUGIN_SQUIRRLY_SEO)) {
            $this->setValue('_sq_description', $description);
            $this->setValue('_sq_title', $title);
            $this->setValue('_sq_keywords', $main_keyword);
        }

    }

    /**
     * SEO title / description of an existing post (webhook action update_post) for the active SEO plugins: only the given values,
     * null - keep, '' - remove. Focus keywords, Open Graph fields and Elementor data are not touched (set() rewrites all of them).
     * All in One SEO keeps its values in its own table: only the title / description columns of an existing row are changed there
     * (Models\Post::savePost() would reset the other settings of the post - canonical url, robots - to their defaults).
     *
     * @param string|null $title
     * @param string|null $description
     * @return list<string> the SEO plugins that got the values
     */
    public function setSeo($title, $description)
    {
        $plugins = [
            'yoast' => [[self::PLUGIN_YOAST, self::PLUGIN_YOAST_PRO], '_yoast_wpseo_title', '_yoast_wpseo_metadesc'],
            'rank_math' => [[self::PLUGIN_RANK_MATH], 'rank_math_title', 'rank_math_description'],
            'seopress' => [[self::PLUGIN_SEOPRESS], '_seopress_titles_title', '_seopress_titles_desc'],
            'seo_framework' => [[self::PLUGIN_SEO_FRAMEWORK], '_genesis_title', '_genesis_description'],
            'squirrly' => [[self::PLUGIN_SQUIRRLY_SEO], '_sq_title', '_sq_description'],
            'aioseo' => [[self::PLUGIN_ALL_IN_ONE, self::PLUGIN_ALL_IN_ONE_PRO], '_aioseo_title', '_aioseo_description'],
        ];

        $updated = [];
        foreach ($plugins as $name => $plugin) {
            $active = false;
            foreach ($plugin[0] as $file) {
                if (is_plugin_active($file)) {
                    $active = true;
                }
            }
            if (!$active) {
                continue;
            }

            if (!is_null($title)) {
                $this->setValue($plugin[1], $title);
            }
            if (!is_null($description)) {
                $this->setValue($plugin[2], $description);
            }
            if ($name === 'aioseo') {
                $this->setAioseoRow($title, $description);
            }
            $updated[] = $name;
        }
        return $updated;
    }

    /**
     * @param string|null $title
     * @param string|null $description
     */
    private function setAioseoRow($title, $description)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';
        $fields = [];
        if (!is_null($title)) {
            $title = wp_strip_all_tags($title, true);
            $fields['title'] = (strlen($title) > 0) ? $title : null;
        }
        if (!is_null($description)) {
            $description = wp_strip_all_tags($description, true);
            $fields['description'] = (strlen($description) > 0) ? $description : null;
        }
        if ((count($fields) > 0) && ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table)) {
            $wpdb->update($table, $fields, ['post_id' => (int)$this->post_id]);
        }
    }
}
