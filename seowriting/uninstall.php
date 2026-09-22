<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

include_once(__DIR__ . '/seowriting.php');
SEOWriting::i()->deactivate();
