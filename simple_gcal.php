<?php
/*
Plugin Name: Simple Google Calendar Widget
Description: Widget that displays events from a public google calendar
Author: Nico Boehr
Version: 0.2
*/
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
            $out[$i]->title = (string)$e->title;
            $out[$i]->from = strtotime((string)$when->startTime);
            $out[$i]->to = strtotime((string)$when->endTime);
            $out[$i]->where = (string)$where->valueString;
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
            echo '<li><span class="date">', strftime(__('<span class="day">%d</span>%b', 'simple_gcal'), $e->from), '</span>';
            echo '<a href="', htmlspecialchars($e->htmlLink),'" class="eventlink" ';
            if($instance['targetblank']) {
                echo 'target="_blank" ';
            }
            if(!empty($e->where)) {
                echo 'title="', sprintf(__('Location: %s', 'simple_gcal'), htmlspecialchars($e->where)), '" ';
            }
            echo '>', htmlspecialchars($e->title), '</a>';
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
        
        $instance['calendar_id'] = htmlspecialchars($new_instance['calendar_id']);
        
        $instance['targetblank'] = $new_instance['targetblank']==1?1:0;
        
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
