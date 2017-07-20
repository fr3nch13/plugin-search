<?php 
// File: plugins/search/View/Elements/search.ctp
$model = (isset($model)?$model:Inflector::singularize(Inflector::camelize($this->params->controller)));

$action = (isset($action)?$action:(isset($this->params->pass[0])?$this->params->pass[0]:'index'));
$search_fields = (isset($info['fields'])?$info['fields']:false);

$search_field_options = false;
$search_field_selected = false;
if($search_fields)
{
	$search_field_options = array(
	);
	
	$search_field_selected = '';

	foreach($search_fields as $search_field)
	{
		$search_field_parts = explode('.', $search_field);
		foreach($search_field_parts as $i => $search_field_part)
		{
			$search_field_parts[$i] = Inflector::humanize($search_field_parts[$i]);
			$search_field_parts[$i] = trim(implode(' ', preg_split('/(?=[A-Z])/',$search_field_parts[$i])));
		}
		$search_field_title = implode(' -> ', $search_field_parts);
		
		$search_field_options[$search_field] = $search_field_title;
	}
}
?>
<div class="top">
	<h1><?php echo __('Advanced Search'); ?></h1>
</div>
<div class="center">
	<div class="posts form">
	<?php echo $this->Form->create(null, array('url' => $info['path'], 'class' => 'advanced_search'));?>
	    <fieldset>
	        <legend><?php echo  __('Advanced Search'); ?></legend>
	    	<?php
			
				echo $this->Form->input('q', array(
					'label' => __('Search terms'),
					'type' => 'textarea',
					'between' => $this->Html->tag('p', __('To search for multiple strings, place each one on a new line.'), array('class' => 'info')),
					'div' => array('class' => 'half'),
				));
			
				echo $this->Form->input('f', array(
					'label' => __('Fields'),
					'type' => 'select',
					'multiple' => true,
					'options' => $search_field_options,
					'between' => $this->Html->tag('p', __('Select which field(s) to search.'), array('class' => 'info')),
					'div' => array('class' => 'half'),
					'style' => 'height: 144px;'
				));
	    	?>
	    </fieldset>
	<?php echo $this->Form->end(__('Search')); ?>
	</div>
</div>
<!-- // check to make sure serviceNow is unique -->
<script type="text/javascript">

$(document).ready(function()
{
	// drop the multi search cookie when submitting
	var cookie_name = '<?php echo "Search.$model.$action" ?>';
	$( "form.advanced_search" ).submit(function( event )
	{
		$.cookie(cookie_name, 'true');
	});
});//ready 
</script>