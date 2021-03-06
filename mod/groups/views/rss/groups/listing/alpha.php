<?php
/**
 * Renders a list of groups ordered alphabetically
 *
 * Note: this view has a corresponding view in the default view type, changes should be reflected
 */

echo elgg_list_entities([
	'type' => 'group',
	'order_by_metadata' => [
		'name' => 'name',
		'direction' => 'ASC',
	],
	'full_view' => false,
	'pagination' => false,
]);
