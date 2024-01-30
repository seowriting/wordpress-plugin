<?php

/**
 * @param $payload mixed
 * @return string
 */
function json_encode_unescaped($payload) {
    return (string)@json_encode($payload, JSON_UNESCAPED_UNICODE);
}
