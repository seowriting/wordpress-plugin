<?php

/**
 * @param $payload mixed
 * @return string
 */
function seowriting_json_encode_unescaped($payload)
{
    return (string)@json_encode($payload, JSON_UNESCAPED_UNICODE);
}
