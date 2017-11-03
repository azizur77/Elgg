<?php
/**
 * Elgg file plugin
 *
 * @package ElggFile
 */

/**
 * File plugin initialization functions.
 */
function file_init() {

	// register a library of helper functions
	elgg_register_library('elgg:file', __DIR__ . '/lib/file.php');

	// Site navigation
	$item = new ElggMenuItem('file', elgg_echo('file'), 'file/all');
	elgg_register_menu_item('site', $item);

	// Extend CSS
	elgg_extend_view('elgg.css', 'file/css');

	// add enclosure to rss item
	elgg_extend_view('extensions/item', 'file/enclosure');

	// extend group main page
	elgg_extend_view('groups/tool_latest', 'file/group_module');

	// Register a page handler, so we can have nice URLs
	elgg_register_page_handler('file', 'file_page_handler');

	// Register URL handlers for files
	elgg_register_plugin_hook_handler('entity:url', 'object', 'file_set_url');
	elgg_register_plugin_hook_handler('entity:icon:url', 'object', 'file_set_icon_url');

	// Register for notifications
	elgg_register_notification_event('object', 'file', ['create']);
	elgg_register_plugin_hook_handler('prepare', 'notification:create:object:file', 'file_prepare_notification');

	// add the group files tool option
	add_group_tool_option('file', elgg_echo('groups:enablefiles'), true);

	// add a file link to owner blocks
	elgg_register_plugin_hook_handler('register', 'menu:owner_block', 'file_owner_block_menu');

	// cleanup thumbnails on delete. high priority because we want to try to make sure the
	// deletion will actually occur before we go through with this.
	elgg_register_event_handler('delete', 'object', 'file_handle_object_delete', 999);

	// embed support
	$item = ElggMenuItem::factory([
		'name' => 'file',
		'text' => elgg_echo('file'),
		'priority' => 10,
		'data' => [
			'options' => [
				'type' => 'object',
				'subtype' => 'file',
			],
		],
	]);
	elgg_register_menu_item('embed', $item);

	$item = ElggMenuItem::factory([
		'name' => 'file_upload',
		'text' => elgg_echo('file:upload'),
		'priority' => 100,
		'data' => [
			'view' => 'embed/file_upload/content',
		],
	]);

	elgg_register_menu_item('embed', $item);

	elgg_extend_view('theme_sandbox/icons', 'file/theme_sandbox/icons/files');

	// allow to be liked
	elgg_register_plugin_hook_handler('likes:is_likable', 'object:file', 'Elgg\Values::getTrue');

	elgg_register_plugin_hook_handler('entity:icon:sizes', 'object', 'file_set_custom_icon_sizes');
	elgg_register_plugin_hook_handler('entity:icon:file', 'object', 'file_set_icon_file');
}

/**
 * Dispatches file pages.
 * URLs take the form of
 *  All files:       file/all
 *  User's files:    file/owner/<username>
 *  Friends' files:  file/friends/<username>
 *  View file:       file/view/<guid>/<title>
 *  New file:        file/add/<guid>
 *  Edit file:       file/edit/<guid>
 *  Group files:     file/group/<guid>/all
 *
 * Title is ignored
 *
 * @param array $page
 * @return bool
 */
function file_page_handler($page) {

	if (!isset($page[0])) {
		$page[0] = 'all';
	}

	$page_type = $page[0];
	switch ($page_type) {
		case 'owner':
			file_register_toggle();
			echo elgg_view_resource('file/owner');
			break;
		case 'friends':
			file_register_toggle();
			echo elgg_view_resource('file/friends');
			break;
		case 'view':
			echo elgg_view_resource('file/view', [
				'guid' => $page[1],
			]);
			break;
		case 'add':
			echo elgg_view_resource('file/upload');
			break;
		case 'edit':
			echo elgg_view_resource('file/edit', [
				'guid' => $page[1],
			]);
			break;
		case 'search':
			file_register_toggle();
			echo elgg_view_resource('file/search');
			break;
		case 'group':
			file_register_toggle();
			echo elgg_view_resource('file/owner');
			break;
		case 'all':
			file_register_toggle();
			echo elgg_view_resource('file/all');
			break;
		default:
			return false;
	}
	return true;
}

/**
 * Adds a toggle to filter menu for switching between list and gallery views
 */
function file_register_toggle() {

	if (get_input('list_type', 'list') == 'list') {
		$list_type = 'gallery';
		$icon = elgg_view_icon('grid');
	} else {
		$list_type = 'list';
		$icon = elgg_view_icon('list');
	}

	$url = elgg_http_add_url_query_elements(current_page_url(), ['list_type' => $list_type]);
	
	elgg_register_menu_item('filter:file', [
		'name' => 'file_list',
		'text' => $icon,
		'href' => $url,
		'title' => elgg_echo("file:list:$list_type"),
		'priority' => 1000,
	]);
}

/**
 * Prepare a notification message about a new file
 *
 * @param string                          $hook         Hook name
 * @param string                          $type         Hook type
 * @param Elgg\Notifications\Notification $notification The notification to prepare
 * @param array                           $params       Hook parameters
 * @return Elgg\Notifications\Notification
 */
function file_prepare_notification($hook, $type, $notification, $params) {
	$entity = $params['event']->getObject();
	$owner = $params['event']->getActor();
	$recipient = $params['recipient'];
	$language = $params['language'];
	$method = $params['method'];

	$descr = $entity->description;
	$title = $entity->getDisplayName();

	$notification->subject = elgg_echo('file:notify:subject', [$title], $language);
	$notification->body = elgg_echo('file:notify:body', [
		$owner->getDisplayName(),
		$title,
		$descr,
		$entity->getURL()
	], $language);
	$notification->summary = elgg_echo('file:notify:summary', [$title], $language);
	$notification->url = $entity->getURL();
	return $notification;
}

/**
 * Add a menu item to the user ownerblock
 */
function file_owner_block_menu($hook, $type, $return, $params) {
	if (elgg_instanceof($params['entity'], 'user')) {
		$url = "file/owner/{$params['entity']->username}";
		$item = new ElggMenuItem('file', elgg_echo('file'), $url);
		$return[] = $item;
	} else {
		if ($params['entity']->file_enable != "no") {
			$url = "file/group/{$params['entity']->guid}/all";
			$item = new ElggMenuItem('file', elgg_echo('file:group'), $url);
			$return[] = $item;
		}
	}

	return $return;
}

/**
 * Registers page menu items for file type filtering and returns a view
 *
 * @param int  $container_guid The GUID of the container of the files
 * @param bool $friends        Whether we're looking at the container or the container's friends
 *
 * @return string The typecloud
 */
function file_get_type_cloud($container_guid = "", $friends = false) {

	$container_guids = $container_guid;
	$container = get_entity($container_guid);

	if ($friends && $container) {
		// tags interface does not support pulling tags on friends' content so
		// we need to grab all friends
		$friend_entities = $container->getFriends(['limit' => 0]);
		if ($friend_entities) {
			$friend_guids = [];
			foreach ($friend_entities as $friend) {
				$friend_guids[] = $friend->getGUID();
			}
		}
		$container_guids = $friend_guids;
	}

	elgg_register_tag_metadata_name('simpletype');
	$options = [
		'type' => 'object',
		'subtype' => 'file',
		'container_guids' => $container_guids,
		'threshold' => 0,
		'limit' => 10,
		'tag_names' => ['simpletype']
	];
	$types = elgg_get_tags($options);

	if ($types) {
		$all = new stdClass;
		$all->tag = 'all';
		elgg_register_menu_item('page', [
			'name' => 'file:all',
			'text' => elgg_echo('all'),
			'href' =>  file_type_cloud_get_url($all, $friends),
		]);
		
		foreach ($types as $type) {
			elgg_register_menu_item('page', [
				'name' => "file:$type->tag",
				'text' => elgg_echo("file:type:$type->tag"),
				'href' =>  file_type_cloud_get_url($type, $friends),
			]);
		}
	}
	
	// returning the view is needed for BC
	$params = [
		'friends' => $friends,
		'types' => $types,
	];

	return elgg_view('file/typecloud', $params);
}

function file_type_cloud_get_url($type, $friends) {
	$url = elgg_get_site_url() . 'file/search?subtype=file';

	if ($type->tag != "all") {
		$url .= "&md_type=simpletype&tag=" . urlencode($type->tag);
	}

	if ($friends) {
		$url .= "&friends=$friends";
	}

	if ($type->tag == "image") {
		$url .= "&list_type=gallery";
	}

	if (elgg_get_page_owner_guid()) {
		$url .= "&page_owner=" . elgg_get_page_owner_guid();
	}

	return $url;
}

/**
 * Populates the ->getUrl() method for file objects
 *
 * @param string $hook
 * @param string $type
 * @param string $url
 * @param array  $params
 * @return string File URL
 */
function file_set_url($hook, $type, $url, $params) {
	$entity = $params['entity'];
	if (elgg_instanceof($entity, 'object', 'file')) {
		$title = elgg_get_friendly_title($entity->getDisplayName());
		return "file/view/" . $entity->getGUID() . "/" . $title;
	}
}

/**
 * Override the default entity icon for files
 *
 * Plugins can override or extend the icons using the plugin hook: 'file:icon:url', 'override'
 *
 * @param string $hook
 * @param string $type
 * @param string $url
 * @param array  $params
 * @return string Relative URL
 */
function file_set_icon_url($hook, $type, $url, $params) {
	$file = $params['entity'];
	$size = elgg_extract('size', $params, 'large');
	if (elgg_instanceof($file, 'object', 'file')) {
		// thumbnails get first priority
		if ($file->hasIcon($size)) {
			return $file->getIcon($size)->getInlineURL(true);
		}

		$mapping = [
			'application/excel' => 'excel',
			'application/msword' => 'word',
			'application/ogg' => 'music',
			'application/pdf' => 'pdf',
			'application/powerpoint' => 'ppt',
			'application/vnd.ms-excel' => 'excel',
			'application/vnd.ms-powerpoint' => 'ppt',
			'application/vnd.oasis.opendocument.text' => 'openoffice',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'ppt',
			'application/x-gzip' => 'archive',
			'application/x-rar-compressed' => 'archive',
			'application/x-stuffit' => 'archive',
			'application/zip' => 'archive',
			'text/directory' => 'vcard',
			'text/v-card' => 'vcard',
			'application' => 'application',
			'audio' => 'music',
			'text' => 'text',
			'video' => 'video',
		];

		$mime = $file->getMimeType();
		if ($mime) {
			$base_type = substr($mime, 0, strpos($mime, '/'));
		} else {
			$mime = 'none';
			$base_type = 'none';
		}

		if (isset($mapping[$mime])) {
			$type = $mapping[$mime];
		} elseif (isset($mapping[$base_type])) {
			$type = $mapping[$base_type];
		} else {
			$type = 'general';
		}

		if ($size == 'large') {
			$ext = '_lrg';
		} else {
			$ext = '';
		}

		$url = elgg_get_simplecache_url("file/icons/{$type}{$ext}.gif");
		$url = elgg_trigger_plugin_hook('file:icon:url', 'override', $params, $url);
		return $url;
	}
}

/**
 * Handle an object being deleted
 *
 * @param string     $event Event name
 * @param string     $type  Event type
 * @param ElggObject $file  The object deleted
 * @return void
 */
function file_handle_object_delete($event, $type, ElggObject $file) {
	if (!$file instanceof ElggFile) {
		return;
	}
	if (!$file->guid) {
		// this is an ElggFile used as temporary API
		return;
	}

	$file->deleteIcon();
}

/**
 * Set custom icon sizes for file objects
 *
 * @param string $hook   "entity:icon:url"
 * @param string $type   "object"
 * @param array  $return Sizes
 * @param array  $params Hook params
 * @return array
 */
function file_set_custom_icon_sizes($hook, $type, $return, $params) {

	$entity_subtype = elgg_extract('entity_subtype', $params);
	if ($entity_subtype !== 'file') {
		return;
	}

	$return['small'] = [
		'w' => 60,
		'h' => 60,
		'square' => true,
		'upscale' => true,
	];
	$return['medium'] = [
		'w' => 153,
		'h' => 153,
		'square' => true,
		'upscale' => true,
	];
	$return['large'] = [
		'w' => 600,
		'h' => 600,
		'upscale' => false,
	];
	
	return $return;
}

/**
 * Set custom file thumbnail location
 *
 * @param string    $hook   "entity:icon:file"
 * @param string    $type   "object"
 * @param \ElggIcon $icon   Icon file
 * @param array     $params Hook params
 * @return \ElggIcon
 */
function file_set_icon_file($hook, $type, $icon, $params) {

	$entity = elgg_extract('entity', $params);
	$size = elgg_extract('size', $params, 'large');

	if (!($entity instanceof \ElggFile)) {
		return;
	}
	
	switch ($size) {
		case 'small' :
			$filename_prefix = 'thumb';
			$metadata_name = 'thumbnail';
			break;

		case 'medium' :
			$filename_prefix = 'smallthumb';
			$metadata_name = 'smallthumb';
			break;

		default :
			$filename_prefix = "{$size}thumb";
			$metadata_name = $filename_prefix;
			break;
	}

	$icon->owner_guid = $entity->owner_guid;
	if (isset($entity->$metadata_name)) {
		$icon->setFilename($entity->$metadata_name);
	} else {
		$filename = pathinfo($entity->getFilenameOnFilestore(), PATHINFO_FILENAME);
		$filename = "file/{$filename_prefix}{$filename}.jpg";
		$icon->setFilename($filename);
	}
	
	return $icon;
}

return function() {
	elgg_register_event_handler('init', 'system', 'file_init');
};
