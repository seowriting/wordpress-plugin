<?php
namespace SEOWriting;
defined( 'WPINC' ) || exit;
include_once __DIR__ . '/../../classes/post-meta.php';

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
    <?php
    if (is_plugin_active(PostMeta::PLUGIN_ELEMENTOR)) {
        ?>
        <tr>
            <th>Elementor</th>
            <td>
                <div class="mb-1">Split incoming post into blocks:</div>
                <?php echo $this->render_select('seowriting_split_to_elementor', ['yes'=>'Yes','no'=>'No']); ?>
                <div class="seowriting-desc"><?php echo esc_html(__('If "No" is selected, Elementor will not be used when creating the post.', 'seowriting')); ?></div>
            </td>
        </tr>
        <?php
    }
    ?>
	</tbody>
</table>
<?php $this->form_end(); ?>