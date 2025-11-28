<?php
namespace SEOWriting;
defined( 'WPINC' ) || exit;

$seowritingBtnTxt = __('Connect', 'seowriting');
$seowritingConnType = __('not', 'seowriting');
$seowritingConnected = false;
if($this->plugin->isConnected()){
	$seowritingBtnTxt = __('Disconnect', 'seowriting');
	$seowritingConnType = '';
	$seowritingConnected = true;
}
?>
<div class="seowriting_msg"><?php echo $this->showMessages(); ?></div>
<div class="conection-blok <?php echo $seowritingConnected ? 'connected' : '' ; ?>">
	<h3 class="conection-message">
        <?php
        /* translators: displaying connection type */
        echo esc_html(sprintf(__('Your site is %1s connected to SEOWRITING.AI', 'seowriting'), $seowritingConnType));
        ?>
    </h3>
    <a href="#<?php echo $seowritingConnected ? 'disconnect' : 'connect' ; ?>" id="seowriting_conection_button" class="button button-primary"><?php echo esc_html($seowritingBtnTxt); ?></a>
</div>