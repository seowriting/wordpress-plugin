<?php
namespace SEOWriting;
defined('WPINC') || exit;

$seowritingMenuList = array(
    'general' => __('General', 'seowriting'),
    'settings' => __('Settings', 'seowriting'),
);
?>

<div class="wrap">
    <h1 class="seowriting-h1">
        <?php
        echo esc_html__('SEOWriting', 'seowriting');
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
        foreach ($seowritingMenuList as $seowritingTab => $seowritingVal) {
            $seowritingTab = esc_html($seowritingTab);
            $seowritingVal = esc_html($seowritingVal);
            echo "<a class='seowriting-tab nav-tab' href='#$seowritingTab' data-seowriting-tab='$seowritingTab'>$seowritingVal</a>";
        }
        ?>
    </h2>

    <div class="seowriting-body">
        <?php

        // include all tpl for faster UE
        foreach ($seowritingMenuList as $seowritingTab => $seowritingVal) {
            $seowritingTab = esc_html($seowritingTab);
            echo "<div data-seowriting-layout='$seowritingTab'>";
            require $this->plugin->plugin_path . "tpl/settings/$seowritingTab.tpl.php";
            echo "</div>";
        }

        ?>
    </div>

</div>
