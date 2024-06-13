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

    private function elementorReplace($s)
    {
        return str_replace(
            ['"', '/', PHP_EOL],
            ['\"', '\/', ''],
            $s
        );
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
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            libxml_use_internal_errors(false);
            $hNames = ['h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true];
            $dom->loadHTML('<?xml encoding="utf-8" ?><html><body><div>' . $data['html'] . '</div></body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $elementorSettings = [];
            $id = 1;
            $elements = is_null($dom->documentElement)
                ? null
                : $dom->documentElement->childNodes[0]->childNodes[0]->childNodes;
            if (!is_null($elements)) {
                foreach ($elements as $element) {
                    $tagName = $element->tagName;
                    if (is_null($tagName)) {
                        continue;
                    }
                    if (isset($hNames[$tagName])) {
                        $tagWidgetType = 'heading';
                        $tagSettings = [
                            'title' => $element->textContent,
                        ];
                        if ($tagName !== 'h1') {
                            $tagSettings['header_size'] = $tagName;
                        }
                    } elseif ($tagName === 'img') {
                        $src = $element->getAttribute('src');
                        $tagWidgetType = 'image';
                        $tagSettings = [
                            'image' => [
                                'url' => $this->elementorReplace($src),
                                'id' => $data['images'][$src],
                                'size' => '',
                                'alt' => $element->getAttribute('alt'),
                                'source' => 'library',
                            ]
                        ];
                    } else {
                        if ($tagName === 'p' && substr($element->textContent, 0, 23) === 'https://www.youtube.com') {
                            $tagWidgetType = 'video';
                            $tagSettings = [
                                'youtube_url' => $this->elementorReplace($element->textContent),
                            ];
                        } else {
                            $tagWidgetType = 'text-editor';
                            $tagSettings = [
                                'editor' => $this->elementorReplace($dom->saveHTML($element)),
                            ];
                        }
                    }
                    $elementorSettings[] = [
                        'id' => (string)$id,
                        'elType' => 'container',
                        'settings' => [],
                        'elements' => [
                            [
                                'id' => (string)++$id,
                                'elType' => 'widget',
                                'settings' => $tagSettings,
                                'elements' => [],
                                'widgetType' => $tagWidgetType,
                            ]
                        ],
                        'isInner' => false,
                    ];
                    $id++;
                }
            }
            update_post_meta($this->post_id, '_elementor_data', seowriting_json_encode_unescaped($elementorSettings));
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
}
