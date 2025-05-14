<?php
namespace SEOWriting;

defined('WPINC') || exit;

/**
 *
 */
class HTML2Elementor
{

    /**
     * @var string
     */
    private $html;

    /**
     * @var int
     */
    private $id = 0;

    /**
     * @var bool
     */
    private $is_super_page = false;

    /**
     * @var bool
     */
    private $prepared = false;

    /**
     * @var array<mixed>
     */
    private $payload = [];

    /**
     * @param $html string
     * @return void
     */
    function __construct($html)
    {
        $this->html = '<?xml encoding="utf-8" ?><html><body>' . str_replace("\n", " ", $html) . '</body></html>';
        $this->is_super_page = strpos($this->html, 'styled-container') !== false;
    }

    /**
     * @param $list
     * @return array<mixed>
     */
    private function filter($list)
    {
        foreach ($list as $idx => &$item) {
            if ($item['elType'] !== 'container') {
                continue;
            }
            if (!isset($item['elements'])) {
                unset($list[$idx]);
                continue;
            }
            if (count($item['elements']) === 0) {
                unset($list[$idx]);
                continue;
            }
            $item['elements'] = $this->filter($item['elements']);
        }
        return array_filter(array_values($list));
    }

    /**
     * @return array<mixed>
     */
    function get()
    {
        if (!$this->prepared) {
            $this->prepare();
        }

        return $this->payload;
    }

    /**
     * @return int|string
     */
    private function id($as_int = false)
    {
        return $as_int ? ++$this->id : (string)++$this->id;
    }

    /**
     * @param $node
     * @return string
     */
    private function inner_html($node)
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    /**
     * @return void
     */
    private function load()
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML(mb_convert_encoding($this->html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors(false);
        if (!is_null($dom->documentElement) && isset($dom->documentElement->childNodes[0]) && !is_null($dom->documentElement->childNodes[0])) {
            $this->payload = $this->to_payload($dom->documentElement->childNodes[0]->childNodes);
        }
    }

    /**
     * @return void
     */
    private function prepare()
    {
        if (!class_exists('\DOMDocument')) {
            return;
        }

        $this->load();
        $this->payload = $this->filter($this->payload);
        $this->prepared = true;
    }

    /**
     * @param $s
     * @return array|string|string[]
     */
    private function replace($s)
    {
        return str_replace(
            ['"', '/', PHP_EOL],
            ['\"', '\/', ''],
            $s
        );
    }

    /**
     * @param $nodes
     * @return array<mixed>
     */
    private function to_payload($nodes, $level = 0)
    {
        if (is_null($nodes)) {
            return [];
        }

        $payloads = [];
        foreach ($nodes as $node) {
            if (is_null($node) || $node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /**
             * @var \DOMElement $node
             */
            $tag = strtolower($node->tagName);
            if ($tag === 'style') {
                continue;
            }
            $settings = [];
            $widget_type = '';

            $classes = '';
            if ($node->hasAttribute('class')) {
                $classes = trim($node->getAttribute('class'));
                if (strlen($classes) > 0) {
                    $settings['css_classes'] = $classes;
                    $settings['_css_classes'] = $classes; // yep
                    if (strpos($classes, 'three-columns') !== false
                        || strpos($classes, 'two-columns') !== false
                        || strpos($classes, 'gallery') !== false) {
                        $settings['container_type'] = 'grid';
                        $settings['grid_rows_grid'] = [
                            'unit' => 'fr',
                            'size' => 1,
                            'sizes' => []
                        ];
                    }
                }
            }

            $sticky_cta = strpos($classes, 'sticky-cta') !== false;
            $rating_stars = strpos($classes, 'rating-stars') !== false;
            $text = trim((string)$node->nodeValue);
            $stop = $tag === 'p'
                || $tag === 'ul'
                || $tag === 'iframe'
                || $tag === 'ol'
                || $tag === 'table'
                || $tag === 'blockquote'
                || $tag === 'form'
                || strpos($classes, 'rating-container') !== false
                ||  $sticky_cta;
            $unset_classes = $tag === 'table' || $sticky_cta || $rating_stars;
            $elements = $stop ? [] : $this->to_payload($node->childNodes, $level + 1);
            $div_with_text = count($elements) === 0 && $tag === 'div' && $text !== '';
            $stop = $stop || $div_with_text || $rating_stars;


            if ($tag === 'img') {
                $widget_type = 'image';
                $settings['image'] = [
                    'url' => $this->replace($node->getAttribute('src')),
                    'id' => $this->id(true),
                    'size' => '',
                    'alt' => trim($node->getAttribute('alt'))
                ];
            } else if ($stop) {
                $widget_type = 'text-editor';
                $attrs = [];
                if (!is_null($node->attributes)) {
                    foreach ($node->attributes as $attr) {
                        // @phpstan-ignore-next-line
                        $attrs[] = $attr->name . '="' . $attr->value . '"';
                    }
                }
                $settings['editor'] = '<' . $tag . ' ' . implode(' ', $attrs) . '>' . $this->inner_html($node) . '</' . $tag . '>';
            } else if ($tag === 'a') {
                $widget_type = 'button';
                $settings['text'] = $text;
                $settings['link'] = [
                    'url' => $node->getAttribute('href'),
                    'is_external' => $node->hasAttribute('target') && $node->getAttribute('target') === '_blank' ? 'on' : 'off',
                    'nofollow' => 'on'
                ];
            } else if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3' || $tag === 'h4' || $tag === 'h5' || $tag === 'h6') {
                $widget_type = 'heading';
                $settings['title'] = $text;
                $settings['header_size'] = $tag;
            }

            $payload = [
                'id' => $this->id(),
                'isInner' => !($level === 0),
                'elType' => $widget_type === '' ? 'container' : 'widget',
            ];
            if ($widget_type != '') {
                $payload['widgetType'] = $widget_type;
            } else {
                if ($level === 0 || ($this->is_super_page && $level === 1)) {
                    $settings['content_width'] = 'full';
                }
            }
            if ($unset_classes) {
                unset($settings['css_classes'], $settings['_css_classes']);
            }
            $payload['settings'] = $settings;
            $payload['elements'] = $elements;
            $payloads[] = $payload;
        }

        return $payloads;
    }

}