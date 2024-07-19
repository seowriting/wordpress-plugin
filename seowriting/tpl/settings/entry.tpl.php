<?php
namespace SEOWriting;
defined('WPINC') || exit;

$menu_list = array(
    'general' => __('General', 'seowriting'),
    'settings' => __('Settings', 'seowriting'),
);
?>

<div class="wrap">
    <h1 class="seowriting-h1">
        <?php
        echo esc_html__('SEOWriting');
        ?>
    </h1>
    <span class="seowriting-desc">
		v<?php echo esc_html($this->plugin->version); ?>
	</span>
    <hr class="wp-header-end">
</div>

<div class="seowriting-wrap">
    <h2 class="seowriting-header nav-tab-wrapper">
        <?php
        foreach ($menu_list as $tab => $val) {
            echo "<a class='seowriting-tab nav-tab' href='#$tab' data-seowriting-tab='$tab'>$val</a>";
        }
        ?>
    </h2>

    <div class="seowriting-body">
        <?php

        // include all tpl for faster UE
        foreach ($menu_list as $tab => $val) {
            echo "<div data-seowriting-layout='$tab'>";
            require $this->plugin->plugin_path . "tpl/settings/$tab.tpl.php";
            echo "</div>";
        }

        ?>
    </div>

</div>
