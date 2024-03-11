<?php
namespace SEOWriting;
defined( 'WPINC' ) || exit;

$btn_txt = __('Connect', 'seowriting');
$conection_type = __('not', 'seowriting');
$connected = false;
if($this->plugin->isConnected()){
	$btn_txt = __('Disconnect', 'seowriting');
	$conection_type = '';
	$connected = true;
}
?>
<div class="seowriting_msg"><?php echo $this->showMessages(); ?></div>
<div class="conection-blok <?php echo $connected ? 'connected' : '' ; ?>">
	<h3 class="conection-message"><?php echo esc_html(sprintf(__('Your site is %1s connected to SEOWRITING.AI', 'seowriting'), $conection_type));?></h3>
    <a href="#<?php echo $connected ? 'disconnect' : 'connect' ; ?>" id="seowriting_conection_button" class="button button-primary"><?php echo esc_html($btn_txt); ?></a>
</div>