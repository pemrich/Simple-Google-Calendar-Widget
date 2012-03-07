<?php
/*
Plugin Name: Simple Google Calendar Widget
Description: Widget that displays events from a public google calendar
Author: Nico Boehr
Version: 0.2
*/

function convert_smart_quotes($string) 
{ 
    $search = array(chr(145), 
                    chr(146), 
                    chr(147), 
                    chr(148), 
                    chr(151),
                    chr(39) . "s"); 
 
    $replace = array('&lsquo;', 
                     '&rsquo;', 
                     '&ldquo;', 
                     '&rdquo;', 
                     '&mdash;',
                     '&rsquo;s'); 
 
    return str_replace($search, $replace, $string); 
}

function get_page_title($url){
	ini_set('user_agent', 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.0.11) Gecko/2009060215 Firefox/3.0.11');
	if( !($data = file_get_contents($url)) ) return false;

	if( preg_match_all("/<title>(.*)<\/title>/s", $data, $t))  {
		return trim($t[1][0]);
	} else {
		return false;
	}
}

function clean_up_content($contentString) {
	$URLStringFormat = '<a href="%s"';
    if($instance['targetblank']) {
        $URLStringFormat = $URLStringFormat . ' target="_blank" ';
    }
	$URLStringFormat = $URLStringFormat . '>%s</a>';
	$URLMailStringFormat = '<a href="%s">%s</a>';
	$editedString = str_replace("\n", " \n ", $contentString);
	$explodedString = explode(" ", $editedString);
	foreach($explodedString as $key => $chunk) {
	    if((parse_url($chunk,PHP_URL_SCHEME) !== NULL) && (parse_url($chunk,PHP_URL_HOST) !== NULL)) {
	        if(strtolower(parse_url($chunk,PHP_URL_SCHEME)) == 'mailto') {
	            $emailAddress = parse_url($chunk,PHP_URL_USER) . "@" . parse_url($chunk,PHP_URL_HOST);
	            $explodedString[$key] = sprintf($URLMailStringFormat,$chunk,$emailAddress);
	        } else {
				$chunkPageTitle = get_page_title($chunk);
	            $explodedString[$key] = sprintf($URLStringFormat,$chunk,$chunkPageTitle);
	        }
	    } else {
	        $explodedString[$key] = convert_smart_quotes($chunk);
	    }
	}
	$parsedString = implode(" ",$explodedString);
	$explodedString = explode(" \n ",$parsedString);
	foreach($explodedString as $key => $line) {
		$line = '<p class="sgcw_paragraph">' . $line . "</p>";
		$explodedString[$key] = $line;
	}
	$parsedString = implode("", $explodedString);
	
	return $parsedString;
}

class Simple_Gcal_Widget extends WP_Widget 
{
    
    public function __construct()
    {
        // load our textdomain
        load_plugin_textdomain('simple_gcal', false, basename( dirname( __FILE__ ) ) . '/languages' );
        
        parent::__construct('Simple_Gcal_Widget', 'Simple Google Calendar Widget', array('description' => __('Displays events from a public Google Calendar', 'simple_gcal')));
    }
    
    private function getTransientId()
    {
        return 'wp_gcal_widget_'.$this->id;
    }
    
    private function getCalendarUrl($calId, $count)
    {
        // Where $calId is the calendar ID and $count is the number of events to list
		// Set in the Widget config pane
		return 'https://www.google.com/calendar/feeds/'.$calId.'/public/full?orderby=starttime&sortorder=ascending&max-results='. $count . '&futureevents=true&singleevents=true';
    }
    
	
    private function getData($instance)
    {
        $widgetId = $this->id;
        $calId = $instance['calendar_id'];
        $transientId = $this->getTransientId();
        
        if(false === ($data = get_transient($transientId))) {
            $data = $this->fetch($calId, $instance['event_count']);
            set_transient($transientId, $data, $instance['cache_time']*60);
        }
        
        return $data;
    }
    
    private function clearData()
    {
        return delete_transient($this->getTransientId());
    }
    
    private function fetch($calId, $count)
    {
        $url = $this->getCalendarUrl($calId, $count);
        $httpData = wp_remote_get($url);
        
        if(!$httpData) {
            return false;
        }
        
        $xml = new SimpleXmlElement($httpData['body']);
        
        if(!$xml) {
            return false;
        }
        
        $out = array();
        $i = 0;
        foreach($xml->entry as $e)
        {
       
            $gd = $e->children('http://schemas.google.com/g/2005');
            $out[$i] = new StdClass;
            $when = $gd->when->attributes();
            $where = $gd->where->attributes();
			$content = (string)$e->content;
            $out[$i]->title = (string)$e->title;
            $out[$i]->from = strtotime((string)$when->startTime);
            $out[$i]->to = strtotime((string)$when->endTime);
            $out[$i]->where = (string)$where->valueString;
			$out[$i]->content = clean_up_content($content);
            foreach($e->link as $l) {
                $type = $l->attributes()->type;
                $href = $l->attributes()->href;
                if($type == 'text/html') {
                    $out[$i]->htmlLink = (string)$href;
                    break;
                }
            }
            $i++;
            
        }
        
        return $out;
    }

    public function widget($args, $instance) 
    {
        $title = apply_filters('widget_title', $instance['title']);
        echo $args['before_widget']; 
        if(isset($instance['title'])) {
            echo $args['before_title'], $instance['title'], $args['after_title'];
        }
        
        $data = $this->getData($instance);
        echo '<ol class="eventlist">';
        foreach($data as $e) {
            echo '<li><span class="date">', strftime(__('<span class="day">%d</span>%b', 'simple_gcal'), $e->from), '</span><h4>';
			if($instance['title_is_link']) {
				echo '<a href="', htmlspecialchars($e->htmlLink),'" class="eventlink" ';
            	if($instance['targetblank']) {
                	echo 'target="_blank" ';
            	}
			
            	if(!empty($e->where)) {
                	echo 'title="', sprintf(__('Location: %s', 'simple_gcal'), htmlspecialchars($e->where)), '" ';
            	}
            	echo '>', htmlspecialchars($e->title), '</a>';
			} else {
				echo htmlspecialchars($e->title);
			}
			echo '</h4>';
        	if(!empty($e->where) && $instance['showlocation']) {
            	echo '<p class="sgcw_location">', sprintf(__('Location: %s', 'simple_gcal'), htmlspecialchars($e->where)), '</p>';
        	}
			if($instance['showcontent'] && $e->content !== '') {
				echo $e->content;
			}
            echo '</li>';
        }
        echo '</ol>';
        echo '<br class="clear" />';
        echo $args['after_widget']; 
    }

    public function update($new_instance, $old_instance) 
    {
        $instance = $old_instance;
        $instance['title'] = strip_tags($new_instance['title']);
		$instance['title_is_link'] = $new_instance['title_is_link']==1?1:0;
        
        $instance['calendar_id'] = htmlspecialchars($new_instance['calendar_id']);
        
        $instance['targetblank'] = $new_instance['targetblank']==1?1:0;
		
		$instance['showcontent'] = $new_instance['showcontent']==1?1:0;
		$instance['showlocation'] = $new_instance['showlocation']==1?1:0;
        
        $instance['cache_time'] = $new_instance['cache_time'];
        if(is_numeric($new_instance['cache_time']) && $new_instance['cache_time'] > 1) {
            $instance['cache_time'] = $new_instance['cache_time'];
        } else {
            $instance['cache_time'] = 60;
        }
        
        $instance['event_count'] = $new_instance['event_count'];
        if(is_numeric($new_instance['event_count']) && $new_instance['event_count'] > 1) {
            $instance['event_count'] = $new_instance['event_count'];
        } else {
            $instance['event_count'] = 5;
        }
        
        // delete our transient cache
        $this->clearData();
        
        return $instance;
    }

    public function form($instance) 
    {
        $default = array(
            'title' => __('Events', 'simple_gcal'),
            'cache_time' => 60
        );
        $instance = wp_parse_args((array) $instance, $default);
        
        ?>
        <p>
          <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:', 'simple_gcal'); ?></label> 
          <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo attribute_escape($instance['title']); ?>" />
        </p>
        <p>
          <label for="<?php echo $this->get_field_id('calendar_id'); ?>"><?php _e('Calendar ID:', 'simple_gcal'); ?></label> 
          <input class="widefat" id="<?php echo $this->get_field_id('calendar_id'); ?>" name="<?php echo $this->get_field_name('calendar_id'); ?>" type="text" value="<?php echo attribute_escape($instance['calendar_id']); ?>" />
        </p>
        <p>
          <label for="<?php echo $this->get_field_id('targetblank'); ?>"><?php _e('Open event details in new window:', 'simple_gcal'); ?></label> 
          <input name="<?php echo $this->get_field_name('targetblank'); ?>" type="hidden" value="0" />
          <input id="<?php echo $this->get_field_id('targetblank'); ?>" name="<?php echo $this->get_field_name('targetblank'); ?>" type="checkbox" value="1" <?php if($instance['targetblank'] == 1) { echo 'checked="checked" '; } ?>/>
        </p>
        <p>
          <label for="<?php echo $this->get_field_id('title_is_link'); ?>"><?php _e('Event title links to calendar page?', 'simple_gcal'); ?></label> 
          <input name="<?php echo $this->get_field_name('title_is_link'); ?>" type="hidden" value="0" />
          <input id="<?php echo $this->get_field_id('title_is_link'); ?>" name="<?php echo $this->get_field_name('title_is_link'); ?>" type="checkbox" value="1" <?php if($instance['title_is_link'] == 1) { echo 'checked="checked" '; } ?>/>
        </p>
        <p>
          <label for="<?php echo $this->get_field_id('showlocation'); ?>"><?php _e('Show event location?', 'simple_gcal'); ?></label> 
          <input name="<?php echo $this->get_field_name('showlocation'); ?>" type="hidden" value="0" />
          <input id="<?php echo $this->get_field_id('showlocation'); ?>" name="<?php echo $this->get_field_name('showlocation'); ?>" type="checkbox" value="1" <?php if($instance['showlocation'] == 1) { echo 'checked="checked" '; } ?>/>
        </p>
          <label for="<?php echo $this->get_field_id('showcontent'); ?>"><?php _e('Show event description?', 'simple_gcal'); ?></label> 
          <input name="<?php echo $this->get_field_name('showcontent'); ?>" type="hidden" value="0" />
          <input id="<?php echo $this->get_field_id('showcontent'); ?>" name="<?php echo $this->get_field_name('showcontent'); ?>" type="checkbox" value="1" <?php if($instance['showcontent'] == 1) { echo 'checked="checked" '; } ?>/>
        </p>
        <p>
          <label for="<?php echo $this->get_field_id('event_count'); ?>"><?php _e('Number of events displayed:', 'simple_gcal'); ?></label> 
          <input class="widefat" id="<?php echo $this->get_field_id('event_count'); ?>" name="<?php echo $this->get_field_name('event_count'); ?>" type="text" value="<?php echo attribute_escape($instance['event_count']); ?>" />
        </p>
        <p>
          <label for="<?php echo $this->get_field_id('cache_time'); ?>"><?php _e('Cache expiration time in minutes:', 'simple_gcal'); ?></label> 
          <input class="widefat" id="<?php echo $this->get_field_id('cache_time'); ?>" name="<?php echo $this->get_field_name('cache_time'); ?>" type="text" value="<?php echo attribute_escape($instance['cache_time']); ?>" />
        </p>
        <p>
            <?php _e('Need <a href="http://wordpress.org/extend/plugins/simple-google-calendar-widget/" target="_blank">help</a>?', 'simple_gcal'); ?>
        </p>
        <?php
    }

}

add_action('widgets_init', create_function('', 'return register_widget("Simple_Gcal_Widget");'));
