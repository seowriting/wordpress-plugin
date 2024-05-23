<?php
namespace SEOWriting;
defined( 'WPINC' ) || exit;
include_once __DIR__ . '/../../classes/post-meta.php';

$this->form_action();
?>
<div class="seowriting_msg"><?php echo $this->showNotifications(); ?></div>
<h3 class="seowriting-title-short">
	<?php echo esc_html__('Plugin Settings', 'seowriting'); ?>
</h3>
<table class="wp-list-table striped seowriting-table">
	<tbody>
		<tr>
			<th>Structured data</th>
			<td>
				<?php echo $this->render_select('sw_shema_type', ['microdata'=>'Microdata','json'=>'JSON-LD', 'off' => 'Off']); ?>
				<div class="seowriting-desc"><?php echo esc_html__('It is important to note that this setting is exclusively designed for use with Schema markup for the FAQ section, ensuring more effective search engine interaction for your published content.', 'seowriting'); ?></div>
			</td>
		</tr>
    <?php
    if (is_plugin_active(PostMeta::PLUGIN_ELEMENTOR)) {
        ?>
        <tr>
            <th>Elementor</th>
            <td>
                <div class="mb-1">Split incoming post into blocks:</div>
                <?php echo $this->render_select('seowriting_split_to_elementor', ['no'=>'No', 'yes'=>'Yes']); ?>
                <div class="seowriting-desc"><?php echo esc_html__('If "No" is selected, Elementor will not be used when creating the post.', 'seowriting'); ?></div>
            </td>
        </tr>
        <?php
    }
    ?>
        <tr>
            <th>Debug</th>
            <td>
                <div class="mb-1">Enable debugging mode:</div>
                <?php echo $this->render_select('seowriting_debug', ['no'=>'No','yes'=>'Yes']); ?>
                <div class="seowriting-desc"><?php
                    echo esc_html__('We may ask you to enable debugging mode if you contact us', 'seowriting'),
                        '<a href="'.esc_url('https://seowriting.canny.io').'" target="blank">',
                        esc_html__('here', 'seowriting'),
                        '</a>';
                    ?></div>
            </td>
        </tr>
	</tbody>
</table>
<?php $this->form_end(); ?>