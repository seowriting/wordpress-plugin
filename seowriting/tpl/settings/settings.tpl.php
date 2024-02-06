<?php
namespace SEOWriting;
defined( 'WPINC' ) || exit;

$this->form_action();
?>
<div class="seowriting_msg"><?php echo $this->showNotifications(); ?></div>
<h3 class="seowriting-title-short">
	<?php echo esc_html(__('Plugin Settings', 'seowriting')); ?>
</h3>
<table class="wp-list-table striped seowriting-table">
	<tbody>
		<tr>
			<th>Structured data</th>
			<td>
				<?php echo $this->render_select('sw_shema_type', ['microdata'=>'Microdata','json'=>'JSON-LD']); ?>
				<div class="seowriting-desc"><?php echo esc_html(__('It is important to note that this setting is exclusively designed for use with Schema markup for the FAQ section, ensuring more effective search engine interaction for your published content.', 'seowriting')); ?></div>
			</td>
		</tr>
	</tbody>
</table>
<?php $this->form_end(); ?>