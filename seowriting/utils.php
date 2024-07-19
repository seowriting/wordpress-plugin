<?php

function seowriting_add_file_to_zip($dir, $zip, $rootPath = '')
{
    $files = scandir($dir);
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        $filePath = $dir . '/' . $file;
        $zipPath = $rootPath ? $rootPath . '/' . $file : $file;
        if (is_dir($filePath)) {
            $zip->addEmptyDir($zipPath);
            seowriting_add_file_to_zip($filePath, $zip, $zipPath);
        } else {
            $zip->addFile($filePath, $zipPath);
        }
    }
}

function seowriting_delete_dir($dirPath)
{
    if (!is_dir($dirPath)) {
        throw new InvalidArgumentException("$dirPath must be a directory");
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_dir($file)) {
                seowriting_delete_dir($file);
            } else {
                unlink($file);
            }
        }
    }
    rmdir($dirPath);
}

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
