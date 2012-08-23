<!DOCTYPE html> 
<html>
    <head>
    </head>    
    <body>
    <?php
        $args = parse_url($_GET['mapp_map']);
        $map = new MapPress_Map($args);  
        $map->display();          
    ?>
    <noscript>
        <p><?php _e( 'Please enable javascript to view this map.', 'mappress' ); ?></p>
    </noscript>
    </body>
</html>
