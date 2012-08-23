<?php
class Mappress_Pro extends Mappress {

	function Mappress_Pro() {
		parent::Mappress();

		// Add widget
		add_action('widgets_init', create_function('', 'return register_widget("Mappress_Widget");'));

		// Add mashup shortcode
		add_shortcode('mashup', array(&$this, 'shortcode_mashup'));

		// Custom field updating
		$options = Mappress_Options::get();
		if ($options->metaKey) {
			if ($options->metaSyncSave)
				add_action('save_post', array(&$this, 'save_post_update'));
			else
				add_action('save_post', array(&$this, 'save_post_create'));

			if ($options->metaSyncUpdate) {
				add_action('added_post_meta', array(&$this, 'post_meta_update'), 10, 4);
				add_action('updated_post_meta', array(&$this, 'post_meta_update'), 10, 4);
				add_action('deleted_post_meta', array(&$this, 'post_meta_update'), 10, 4);
			}
		}
	}

	/**
	* Create new maps when post is saved with custom field.  Existing maps will not be changed.
	*
	* @param mixed $post_ID
	*/
	function save_post_create($post_ID) {
		// Ignore the first call to save_post
		if (wp_is_post_revision($post_ID))
			return;

		$options = Mappress_Options::get();
		$this->create_meta_map($post_ID, $options->metaKey, false);
	}

	/**
	* Update maps when post is saved with custom field.  Existing maps are updated or deleted.
	*
	* @param mixed $post_ID
	*/
	function save_post_update($post_ID) {
		// Ignore the first call to save_post
		if (wp_is_post_revision($post_ID))
			return;

		$options = Mappress_Options::get();
		$this->create_meta_map($post_ID, $options->metaKey, true);
	}

	/**
	* Update maps when custom field is changed
	*
	* @param mixed $meta_id
	* @param mixed $object_id
	* @param mixed $meta_key
	* @param mixed $_meta_value
	*/
	function post_meta_update($meta_id, $object_id, $meta_key, $meta_value) {
		$options = Mappress_Options::get();

		if (!$options->metaKey || $meta_key != $options->metaKey)
			return;
		$this->create_meta_map($object_id, $options->metaKey, true);
	}


	/**
	* Get all POIs from a custom field for one post
	* @param string meta_key field key, field may contain address or shortcode-like POI attributes
	* @return object (pois, errors) - pois = array of geocoded POI objects or empty, errors = array of WP_Error or empty
	*/
	function get_meta_pois($postid, $meta_key) {
		global $wpdb;

		$pois = array();
		$errors = array();

		$metapois = $this->get_post_meta($postid, $meta_key);

		if ($metapois) {
			foreach((array)$metapois as $metapoi) {
				// If the field contains any double quotes we'll assume it's a shortcode and parse it
				if (strpos($metapoi, '"') > 0)
					$atts = shortcode_parse_atts($metapoi);
				else
					$atts = array("address" => $metapoi);

				if (!$atts || empty($atts)) {
					$errors = new WP_Error('geocode', sprintf(__('Unable to parse input: %s', 'mappress'), $metapoi));
					continue;
				}

				// Adjust attributes
				$atts = $this->scrub_atts($atts);

				// Create a POI and geocode it
				$poi = new Mappress_Poi($atts);
				$result = $poi->geocode();
				if (is_wp_error($result))
					$errors[] = $result;
				else
					$pois[] = $poi;
			}
		}

		return (object) array('pois' => $pois, 'errors' => $errors);
	}

	/**
	* Create a map from a custom field
	*
	* @param mixed $postid - post to create the map for
	* @param mixed $meta_key - custom field key containing POIs
	* @param boolean $update - if true, existing maps will be updated or deleted, otherwise only new maps are created
	* @param mixed $atts - attributes for NEW maps (existing maps are also updated)
	* @return mixed - false if no update performed, true if update w/o errors, array of error messages if update with errors
	*/
	function create_meta_map($postid, $meta_key, $update=false, $atts=null) {
		$options = Mappress_Options::get();

		// Check if there's an existing map
		$map = Mappress_Map::get_post_map($postid, null, $meta_key);

		// If map exists and update=false, return
		if ($map && !$update)
			return false;

		// Get the POIs
		$result = $this->get_meta_pois($postid, $meta_key);
		$meta_pois = $result->pois;
		$errors = $result->errors;

		// Update the geocoding errors field, if configured
		if ($options->metaKeyErrors) {
			delete_post_meta($postid, $options->metaKeyErrors);
			foreach ($errors as $error)
				add_post_meta($postid, $options->metaKeyErrors, $error->get_error_message('geocode'));
		}

		// If there's no valid POIs delete the existing map for the post (if any), then return
		if (empty($meta_pois)) {
			if ($map)
				Mappress_Map::delete($postid, $map->mapid);
			return true;
		}

		// Create new map if needed
		if (!$map)
			$map = new Mappress_Map($atts);

		// Update with new POIs
		$map->update(array('pois' => $meta_pois, 'title' => $meta_key, 'metaKey' => $meta_key, 'center' => array('lat' => 0, 'lng' => 0)));
		$map->save($postid);

		// Return array of error or success
		if (!empty($result->errors))
			return $result->errors;
		else
			return true;
	}

	/**
	* Replacement for WP get_post_meta.  Returns a list of values sorted by meta_id.
	*
	* @param mixed $postid
	* @param mixed $meta_key
	*/
	function get_post_meta($postid, $meta_key) {
		global $wpdb;

		// WP's get_post_meta() doesn't return a sorted list, so a custom select has to be used (which bypasses the cache, etc.)
		$result = $wpdb->get_col( $wpdb->prepare ("SELECT meta_value FROM $wpdb->postmeta WHERE post_id=$postid AND meta_key = '$meta_key' ORDER BY meta_id") );
		if (!$result)
			return false;
		else
			return $result;
	}

	/**
	* Process the mashup shortcode
	*
	*/
	function shortcode_mashup($atts='') {
		global $post;

		// No feeds
		if (is_feed())
			return;

		// If there's no post variable, there's nothing to do - this can happen when plugins call do_shortcode(), e.g. Relevanssi
		if (!$post)
			return;

		return $this->get_mashup($atts);
	}

	/**
	* Get a mashup map
	*
	*/
	function get_mashup($atts='', $content=null) {
		$mashup_defaults = array(
			'show' => 'all',                        // "all" = maps from ALL posts, "current" = maps from current posts, "query" = custom query
			'show_query' => null,                   // Custom query string
			'marker_title' => 'marker',             // post = show post title, marker = show marker title
			'marker_body' => 'marker',              // excerpt = post excerpt, marker = show marker body, none = suppress body
			'marker_link' => true,
			'tooltips' => false
		);

		$atts = $this->scrub_atts($atts);

		// Set defaults (for mashup options only - others are copied verbatim)
		$mashup_atts = shortcode_atts($mashup_defaults, $atts);
		$atts = array_merge($atts, $mashup_atts);

		// Gather the POIs
		$pois = array();

		// ALL POIs (if set, the other show parameters are ignored)
		if (isset($atts['show'])) {
			switch ($atts['show']) {
				case 'all' :
					// @TODO - should read this from table - on large blogs query may fail due to lack of memory
					$query = new WP_Query('posts_per_page=-1&post_type=any');
					$pois = $this->get_mashup_pois($query, $atts);
					break;

				case 'query' :
					if  (isset($atts['show_query'])) {
						// WP will replace "&" with "&amp" in shortcodes (thanks, wordpress) so convert back if needed
						$show_query = $atts['show_query'];
						$show_query = str_replace('&amp;', '&', $show_query);
						$show_query = str_replace('&#038;', '&', $show_query);

						// Here's the trick: parse the URL string into an array (WP does this in parse_query() anyway)
						parse_str($show_query, $parsed_query);

						// Then loop through the array and explode the 'intersection' parameters into sub-arrays
						// This permits passing the array parameters like category__in as an array, but everything else as a single value
						$array_params = array('category__in', 'category__not_in', 'category__and', 'tag__and', 'tag__in', 'tag__not_in', 'tag_slug__and', 'tag_slug__in', 'post__in', 'post__not_in', 'post_type');

						foreach($array_params as $array_param) {
							if (isset($parsed_query[$array_param]) && $parsed_query[$array_param])
								$parsed_query[$array_param] = explode(',', $parsed_query[$array_param]);
						}

						// Some examples:
						//  "tag=mytag&category__and=438,446"
						//  "tag=mytag&post_type=post,page"
						//
						// Note that WP seems to have some bugs of its own: if a post has tag=mytag and cats 438 and 446, this does NOT work:
						//    query=array('tag'=>'mytag','category__in'=>array(438,446));
						// But this does work:
						//    query=array('category__in'=>array(438,446));
						$query = new WP_Query($parsed_query);
						$pois = $this->get_mashup_pois($query, $atts);
					}
					break;

				default :
					// Default is show current posts
					global $wp_query;
					$pois = $this->get_mashup_pois($wp_query, $atts);
					break;
			}
		}

		// Build a new map with all of the POIs
		$mashup = new Mappress_Map($atts);
		$mashup->pois = $pois;

		return $mashup->display($atts);
	}

	/**
	* Returns POIs for maps in a post query
	*
	*/
	function get_mashup_pois($query, $atts) {
		global $post;
		global $wp_current_filter;

		$options = Mappress_Options::get();

		$defaults = array(
			'marker_title' => 'marker',
			'marker_body' => 'marker',
			'marker_link' => true
		);

		// Shortcode atts are in lowercase, so default to always using lowercase here
		$atts = array_change_key_case($atts);
		extract(shortcode_atts($defaults, $atts));

		$current_post = (isset ($post)) ? clone $post : null;    // If current post is set, save it to resotre later
		$query_posts = $query->posts;

		$mashup_pois = array();
		foreach((array)$query_posts as $query_post) {
			// Get all the maps for the current post
			$maps = Mappress_Map::get_post_map_list($query_post->ID);

			// Get permalink for parent post
			$permalink = get_permalink($query_post->ID);

			// Process each map
			foreach((array)$maps as $map) {

				// Process the POIs and set title/body if needed
				$pois = $map->pois;
				foreach ($pois as $poi) {
					if ($marker_title == 'post')
						$poi->title = $query_post->post_title;

					if ($marker_link) {
						$poi->url = $permalink;
					}

					switch($marker_body) {
						case 'excerpt' :
							// Set post globals to current query post so we can get the excerpt
							setup_postdata($query_post);
							$post = $query_post;
							$poi->body = get_the_excerpt();
							break;

						case 'none' :
							$poi->body = "";
							break;

						default :
							// Use original body
					}

					$mashup_pois[] = $poi;
				}

			}
		}

		// Restore the post globals so as not to interfere with The Loop
		wp_reset_postdata();
		$post = (isset ($current_post)) ? $current_post : $post;

		return $mashup_pois;
	}

	function _load_icons($url, $options) {
		// Re-read the user icons directory - but only when the API is first loaded for editable maps
		if ($options->editable) {
			$icons = array();
			if ($dir = opendir(dirname( __FILE__ ) . '/../icons')) {
				while (($file = readdir($dir)) !== false) {
					if($file == '.' || $file == '..' || $file == '.htaccess')
						continue;
					else
						$files[] = $file;
				}
				closedir($dir);
			}
			if (isset($files)) {
				natcasesort($files);

				// Convert to associative array
				foreach((array)$files as $file)
					$icons[$file] = array();
			}

			update_option('mappress_icons', $icons);
		}

		$standard_url = $url . '/pro/standard_icons';
		$user_url = $url . '/icons';
		$icons = get_option('mappress_icons');
		$icons = json_encode($icons);
		$script = "var mappIcons = new MappIcons('$standard_url', '$user_url', $icons)";
		echo "<script type='text/javascript'>/* <![CDATA[ */ $script /* ]]> */</script>";
	}
} // End Class Mappress_Pro


class Mappress_Widget extends WP_Widget {

	var $defaults = array(
		'show' => 'current',                    // "all" = maps from ALL posts, "current" = maps from current posts, "custom" = custom query
		'show_query' => null,                   // Custom query string
		'marker_title' => 'marker',             // post = show post title, marker = show marker title
		'marker_body' => 'marker',              // excerpt = show post excerpt, marker = show marker body
		'marker_link' => true,
		'widget_title' => 'MapPress Map',
		'width' => 200,
		'height' => 250,
		'poiList' => false,
		'directions' => 'none',
		'traffic' => false,
		'center' => array('lat' => 0, 'lng' => 0),
		'zoom' => null,
		'mapTypeId' => 'roadmap',
		'overviewMapControl' => false,
		'overviewMapControlOptions' => array('opened' => false)
	);

	function Mappress_Widget() {
		parent::WP_Widget(false, $name = 'MapPress Map');
	}

	function widget($args, $instance) {
		global $mappress;

		extract($args);

		echo $before_widget;
		echo $before_title . $instance['widget_title'] . $after_title;

		// Widget map settings
		$instance['initialopeninfo'] = false;
		echo $mappress->get_mashup($instance);
		echo $after_widget;
	}

	function update($new_instance, $old_instance) {
		// Set true/false/null
		$new_instance['marker_link'] = (isset($new_instance['marker_link'])) ? true : false;
		$new_instance['traffic'] = (isset($new_instance['traffic'])) ? true : false;
		$new_instance['poiList'] = (isset($new_instance['poiList'])) ? true : false;
		$new_instance['zoom'] = (isset($new_instance['zoom'])) ? (int) $new_instance['zoom'] : null;
		$new_instance['overviewMapControl'] = (isset($new_instance['overviewMapControl'])) ? true : false;
		$new_instance['overviewMapControlOptions']['opened'] = (isset($new_instance['overviewMapControlOptions']['opened'])) ? true : false;

		$new_instance['center']['lat'] = ($new_instance['center']['lat'] == "") ? 0 : $new_instance['center']['lat'];
		$new_instance['center']['lng'] = ($new_instance['center']['lng'] == "") ? 0 : $new_instance['center']['lng'];
		return $new_instance;
	}

	function form($instance) {
		extract(shortcode_atts($this->defaults, $instance));
		?>
			<p>
				<?php _e('Widget title', 'mappress'); ?>:
				<input class="widefat" id="<?php echo $this->get_field_id('widget_title'); ?>" name="<?php echo $this->get_field_name('widget_title'); ?>" type="text" value="<?php echo $widget_title ?>" />
			</p>

			<p>
				<?php _e('Map size', 'mappress'); ?>:
				<input size="3" id="<?php echo $this->get_field_id('width'); ?>" name="<?php echo $this->get_field_name('width'); ?>" type="text" value="<?php echo $width; ?>" />
				x <input size="3" id="<?php echo $this->get_field_id('height'); ?>" name="<?php echo $this->get_field_name('height'); ?>" type="text" value="<?php echo $height; ?>" />
			</p>

			<p>
				<?php _e('Show query', 'mappress'); ?>:<br/>
				<input type="radio" name="<?php echo $this->get_field_name('show'); ?>" value="current" <?php checked($show, 'current'); ?> /><?php _e('Current posts', 'mappress');?>
				<input type="radio" name="<?php echo $this->get_field_name('show'); ?>" value="all" <?php checked($show, 'all'); ?> /><?php _e('All posts', 'mappress');?>
				<br/><input type="radio" name="<?php echo $this->get_field_name('show'); ?>" value="query" <?php checked($show, 'query'); ?> /><?php _e('Custom query', 'mappress');?>
				<input type="text" style='width:100%' name="<?php echo $this->get_field_name('show_query'); ?>" value="<?php echo $show_query ?>" />

				<br/><i><?php echo "<a target='_none' href='http://codex.wordpress.org/Function_Reference/query_posts'>" . __('Learn about queries', 'mappress') . "</a>" ?></i>
			</p>

			<p>
				<?php _e('Marker title', 'mappress'); ?>:<br/>
				<input type="radio" name="<?php echo $this->get_field_name('marker_title'); ?>" value="marker" <?php checked($marker_title, 'marker'); ?> /><?php _e('Marker title', 'mappress'); ?>
				<input type="radio" name="<?php echo $this->get_field_name('marker_title'); ?>" value="post" <?php checked($marker_title, 'post'); ?> /><?php _e('Post title', 'mappress'); ?>
				<br/><input type="checkbox" name="<?php echo $this->get_field_name('marker_link'); ?>" <?php checked($marker_link); ?> /><?php _e('Link marker title to post', 'mappress');?>
			</p>

			<p>
				<?php _e('Marker body', 'mappress'); ?>:<br/>
				<input type="radio" name="<?php echo $this->get_field_name('marker_body'); ?>" value="marker" <?php checked($marker_body, 'marker'); ?> /><?php _e ('Marker', 'mappress'); ?>
				<input type="radio" name="<?php echo $this->get_field_name('marker_body'); ?>" value="excerpt" <?php checked($marker_body, 'excerpt'); ?> /><?php _e('Post excerpt', 'mappress'); ?>
				<br/><input type="radio" name="<?php echo $this->get_field_name('marker_body'); ?>" value="none" <?php checked($marker_body, 'none'); ?> /><?php _e('None', 'mappress'); ?>
			</p>

			<p>
				<?php _e('Directions', 'mappress'); ?>:<br/>
				<input type="radio" name="<?php echo $this->get_field_name('directions'); ?>" value="inline" <?php checked($directions, 'inline'); ?> /><?php _e('Inline', 'mappress'); ?>
				<input type="radio" name="<?php echo $this->get_field_name('directions'); ?>" value="google" <?php checked($directions, 'google'); ?> /><?php _e('Google', 'mappress'); ?>
				<input type="radio" name="<?php echo $this->get_field_name('directions'); ?>" value="none" <?php checked($directions, 'none'); ?> /><?php _e ('None', 'mappress'); ?>
			</p>

			<table>
				<tr>
					<td><?php _e('Center', 'mappress');?>:</td>
					<td>
						<input type="text" size="4" name="<?php echo $this->get_field_name('center][lat'); ?>" value="<?php echo $center['lat']; ?>" />,
						<input type="text" size="4" name="<?php echo $this->get_field_name('center][lng'); ?>" value="<?php echo $center['lng']; ?>" />
					</td>
				</tr>

				<tr>
					<td><?php _e('Zoom', 'mappress');?>:</td>
					<td>
						<select name="<?php echo $this->get_field_name('zoom'); ?>">
						<option <?php selected($zoom, null)?> value="">Automatic</option>
						<?php
							for ($i = 1; $i <= 20; $i++)
								echo "<option " . selected($zoom, $i, false) . " value='$i'>$i</option>";
						?>
						</select>
					</td>
				</tr>

				<tr>
					<td><?php _e('Map type', 'mappress');?>:</td>
					<td>
						<select name="<?php echo $this->get_field_name('mapTypeId'); ?>">
						<option <?php selected($mapTypeId, "roadmap")?> value="roadmap"><?php _e('Map')?></option>
						<option <?php selected($mapTypeId, "hybrid")?> value="hybrid"><?php _e('Hybrid')?></option>
						<option <?php selected($mapTypeId, "satellite")?> value="satellite"><?php _e('Satellite')?></option>
						<option <?php selected($mapTypeId, "terrain")?> value="terrain"><?php _e('Terrain')?></option>
						</select>
					</td>
				</tr>
			</table>

			<p>
				<input type="checkbox" name="<?php echo $this->get_field_name('poiList'); ?>" <?php checked($poiList); ?> />
				<?php _e('Show list of markers', 'mappress');?>
				<br/>
				<input type="checkbox" name="<?php echo $this->get_field_name('traffic'); ?>" <?php checked($traffic); ?> />
				<?php _e('Show traffic', 'mappress');?>
				<br/>
				<input type="checkbox" name="<?php echo $this->get_field_name('overviewMapControl'); ?>" <?php checked($overviewMapControl); ?> />
				<?php _e('Overview map control', 'mappress');?>
				<br/>
				<input type="checkbox" name="<?php echo $this->get_field_name('overviewMapControlOptions][opened'); ?>" <?php checked($overviewMapControlOptions['opened']); ?> />
				<?php _e('Open overview map', 'mappress');?>
			</p>


			</p>

		<?php
	} // End class Mappress_Pro
}

class Mappress_Pro_Settings extends Mappress_Settings {
    function __construct() {        
        parent::__construct();
    }
    
    function set_options($input) {        
        // Set metakey to provided value or null
        $input['metaKey'] = (isset($input['metaKey']) && !empty($input['metaKey'])) ? $input['metaKey'] : null;

        // Minimum default map size is 200
        foreach( (array)$input['mapSizes'] as $i => $size ) {
            $input['mapSizes'][$i]['width'] = max(200, (int)$input['mapSizes'][$i]['width']);
            $input['mapSizes'][$i]['height'] = max(200, (int)$input['mapSizes'][$i]['height']);
        }
        
        return parent::set_options($input);
    }

    function set_meta_key() {
        global $wpdb;

        // Get list of custom fields from all posts; ignore wordpress standard hidden fields
        $meta_keys = $wpdb->get_col( "
            SELECT DISTINCT meta_key
            FROM $wpdb->postmeta
            WHERE meta_key NOT in ('_edit_last', '_edit_lock', '_encloseme', '_pingme', '_thumbnail_id')
            AND meta_key NOT LIKE ('\_wp%')" );

        // Add an empty entry
        $meta_keys = array_merge(array(''), $meta_keys);

        echo __("Field for addresses", 'mappress');
        echo ": <select name='mappress_options[metaKey]'>";
        foreach ($meta_keys as $meta_key)
            echo "<option " . selected($meta_key, $this->options->metaKey, false) . " value='$meta_key'>$meta_key</option>";
        echo "</select>";

        echo "<br/>";
        echo __("Field for errors", 'mappress');
        echo ": <select name='mappress_options[metaKeyErrors]'>";
        foreach ($meta_keys as $meta_key)
            echo "<option " . selected($meta_key, $this->options->metaKeyErrors, false) . " value='$meta_key'>$meta_key</option>";
        echo "</select>";

        echo "<br/>" . Mappress::checkbox($this->options->metaSyncSave, 'mappress_options[metaSyncSave]');
        _e("Update map when post is updated", 'mappress');

        echo "<br/>" . Mappress::checkbox($this->options->metaSyncUpdate, 'mappress_options[metaSyncUpdate]');
        _e('Update map when address is changed by a program', 'mappress');
    }

    function set_poi_list() {
        echo Mappress::checkbox($this->options->poiList, 'mappress_options[poiList]');
        _e("Show a list of markers under each map", 'mappress');
    }

    function set_poi_list_template() {
        $tags = array("icon", "title", "body", "directions", "address", "correctedaddress", "address1", "address2");
        echo "Supported tags: ";
        foreach ($tags as $tag) {
            echo "[$tag] ";
        }
        echo "<br/>";
        echo "<textarea type='text' rows='4' cols='80' name='mappress_options[poiListTemplate]'>";
        echo esc_attr($this->options->poiListTemplate);
        echo "</textarea>";
    }

    function set_control() {
        echo Mappress::checkbox($this->options->control, 'mappress_options[control]');
        _e("Display powered by link", 'mappress');
    }

    function set_map_sizes() {
        echo __('Enter default map sizes', 'mappress');
        echo ": <br/>";

        echo "<table class='mapp-table'>";
        echo "<tr><th>" . __('Width(px)', 'mappress') . "</th><th>" . __('Height(px)', 'mappress') . "</th></tr>";
        foreach($this->options->mapSizes as $i => $size) {
            echo "<tr>";
            echo "<td><input type='text' size='3' name='mappress_options[mapSizes][$i][width]' value='{$size['width']}' /></td>";
            echo "<td><input type='text' size='3' name='mappress_options[mapSizes][$i][height]' value='{$size['height']}' /></td>";
            echo "</tr>";
        }
        echo "</table>";
    }  
} // End class Mappress_Pro_Settings
?>
