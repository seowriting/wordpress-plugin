<?php

/**
 * @param $payload
 * @return string
 */
function seowriting_escape($payload)
{
    return esc_html(sanitize_text_field($payload));
}

/**
 * @param $payload mixed
 * @return string
 */
function seowriting_json_encode_unescaped($payload)
{
    return (string)@json_encode($payload, JSON_UNESCAPED_UNICODE);
}
