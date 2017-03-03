<?php


/**
 * Define the debugging state, defaults to false
 */

$debug = 'false';

if(isset($_POST['debug'])){
	$debug = $_POST['debug'];
}


/**
 *  SET UP CRUD DB EDITING
 */


    #include('/var/www/html/tools/xcrud_1_6_25/xcrud/xcrud.php');
    include('./resources/xcrud/xcrud.php');


    $log_results = Xcrud::get_instance();

    $log_results->table('log_table');
    $log_results->table_name('Rating Update Log');
    $log_results->where("ident like '%".$_SERVER['PHP_SELF']."%' or  ident like '%push2Ldad.php%'");
    if($debug != 'true') $log_results->where('priority <','7');
    $log_results->order_by('id','desc');
    $log_results->unset_edit();
    $log_results->unset_remove();
    $log_results->columns('id,ident,logtime,priority,message',false);
    $log_results->column_cut(80,'message'); // separate columns
    $log_results->highlight_row('priority','<=','3','#FF6666');
    $log_results->highlight_row('priority','=','4','#FFFFCC');
    $log_results->highlight_row('priority','=','5','#DBFFDB');
    $log_results->column_class('id,logtime,priority', 'align-center');
    $log_results->relation('priority','log_levels','id','name');

    if($debug == 'false')$log_results->start_minimized(true);

    $config_table = Xcrud::get_instance();
    $config_table->default_tab('Site Config');
    $config_table->table('ratings_config');
    $config_table->columns('lid,usgs,Ratings,ratVar,maxDelta,toCHPS',false);
    $config_table->column_width('Ratings,ratVar,maxDelta,toCHPS','20px');
    $config_table->search_pattern( '\n', '<br>' );
    #$config_table->readonly('ratVar,maxDelta');
    $config_table->table_name('Ratings to CHPS Configuration');
    $config_table->order_by('lid','asc');
    $config_table->label(array('ratVar' => 'Rating Variability Index',
                            'maxDelta' => 'Recent Change (%)'));

    $config_table->unset_remove();
    $config_table->highlight_row('toCHPS','=',true,'#DBFFDB');
    $config_table->column_class('lid,usgs,toCHPS,Ratings,ratVar,maxDelta', 'align-center');
    $config_table->subselect('Ratings','select count(*) from ratings where lid ={lid}');
    $config_table->create_action('sendtoCHPS', 'send_to_CHPS'); // action callback, function publish_action() in functions.php
    $config_table->create_action('noSend', 'do_not_sendtoCHPS');

    $config_table->button('#', 'noSend', 'icon-close glyphicon glyphicon-remove', 'xcrud-action',
        array(  // set action vars to the button
            'data-task' => 'action',
            'data-action' => 'sendtoCHPS',
            'data-primary' => '{lid}'),
        array(  // set condition ( when button must be shown)
            'toCHPS',
            '!=',
            '1')
    );
    $config_table->button('#', 'published', 'icon-checkmark glyphicon glyphicon-ok', 'xcrud-action',
        array(
            'data-task' => 'action',
            'data-action' => 'noSend',
            'data-primary' => '{lid}'),
        array(
            'toCHPS',
            '=',
            '1')
    );
    $config_table->column_cut(20); // separate columns
    $config_table->start_minimized(true);
    $config_table->limit('all');
    $config_table->column_pattern('lid', '<a href="ratViewer.php?USGS={lid}" target="_blank" >{value}</a>');
    $config_table->column_width('lid,usgs,toCHPS','20px');




        echo $config_table->render();
    	echo $log_results->render();
?>

