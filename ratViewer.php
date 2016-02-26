<a href='rating_tool.php'>Rating Tool</a>
<?php
  #ini_set('display_errors',1);
  #error_reporting(E_ERROR | E_WARNING | E_PARSE );
  #error_reporting(E_ERROR | E_PARSE );
  



  #redrock  
  require_once('/hd1apps/data/intranet/html/private/adminconnecti.php');
  $mysqli->select_db("aprfc");
  

  $query = "SELECT usgs FROM ratings order by rating_shifted desc limit 1";
  $result = $mysqli->query($query) or die($mysqli->error);
  $row = $result->fetch_array();
  $default = $row['usgs'];
  $USGS = '';
  
  if(!($id = $_GET['USGS'])) $id = $default;
  if($_GET['admin'] == 'true'){
    $admin = true;
  }
  else{
    $admin = false;
  }  
  if(!($stage = $_GET['stage'])) $stage = 'x';
  $hcSeries = array();   
  $xtitle = 'Stage (ft)';
  $ytitle = 'Discharge (cfs)';	
  
  if(strlen($id)==5){
    $query = "SELECT rating_shifted,USGSratid,usgs,id FROM ratings where lid = '$id' order by rating_shifted desc";
  } 
  else{
    $query = "SELECT rating_shifted,USGSratid,usgs,id FROM ratings where usgs = $id order by rating_shifted desc";
  } 
  
  $result = $mysqli->query($query) or die($mysqli->error);
  $i = 0; 
  while($row = $result->fetch_array()){
    $USGS = $row['usgs'];
    $series = array();
    
    $series['name'] = $row['rating_shifted']."(".$row['USGSratid'].")";
    $query = "select stage,discharge from ratingtables where ratingID = {$row['id']} order by stage asc";
    $res = $mysqli->query($query) or die($mysqli->error);
    $values = array();
     
	if($stage == 'y'){
        $ytitle = 'Stage (ft)';
        $xtitle = 'Discharge (cfs)';	
	      while($ratrow = $res->fetch_array()){
		 	$values[] = array((float)$ratrow['discharge'],(float)$ratrow['stage']);
		}
    }
	else{
    while($ratrow = $res->fetch_array()){
       	$values[] = array((float)$ratrow['stage'],(float)$ratrow['discharge']);        
 	}
      }
      $series['data'] = $values;
      if($i>=2) $series['visible'] = false;
      $hcSeries[] = $series;        
      $i++;	              
  }  

#echo json_encode($hcSeries);

/**
 *  SET UP CRUD DB EDITING
 */
 
// function in functions.php
function delete_rating_data($primary, $xcrud){
    $db = Xcrud_db::get_instance();
    $xcrud->query('DELETE FROM ratingtables WHERE ratingID = '.$primary);
}
 
 
include('../xcrud_1_6_25/xcrud/xcrud.php');
$ratingDetails = Xcrud::get_instance();
$ratingDetails->table('ratings');
$ratingDetails->order_by('rating_shifted','desc');
$ratingDetails->columns('lid,usgs,rating_shifted,postingtime,source,USGSratid');
$ratingDetails->default_tab('Rating Information');
$ratingDetails->modal('raw_file');
if(strlen($id)==5){
    $ratingDetails->where('lid =',$id);
}
else{    
    $ratingDetails->where('usgs =',$USGS);
}    
$ratingDetails->limit('all');
if(!$admin) $ratingDetails->unset_edit();
if(!$admin) $ratingDetails->unset_remove();
$ratingDetails->readonly('postingtime','raw_file','raw_format');
$ratingDetails->after_remove('delete_rating_data');
$ratingDetails->change_type('rating_shifted','text','',40);
$ratingDetails->change_type('postingtime','text','',40);
$ratingDetails->change_type('raw_file','textarea','',20);
$ratingDetails->change_type('comment','textarea');







##########################################  WEB PAGE  ############################################
  ?>  
<!DOCTYPE html>
<html>
  <head>
     <meta http-equiv="content-type" content="text/html; charset=utf-8" />    
    <link rel="stylesheet" href="//code.jquery.com/ui/1.10.4/themes/smoothness/jquery-ui.css">
    <script src="//code.jquery.com/jquery-1.9.1.js"></script>
    <script src="//code.jquery.com/ui/1.10.4/jquery-ui.js"></script>
    <script src="http://code.highcharts.com/highcharts.js"></script>
    <script src="http://code.highcharts.com/modules/exporting.js"></script>
    <script src="http://code.highcharts.com/highcharts-more.js"></script>
    <script src="../../javascript/highcharts_reg.js"> </script>
    <script src="http://highslide-software.github.io/export-csv/export-csv.js"></script>


    <script>
    function switchAxis(value){
	value == 'y' ? value = 'x' : value = 'y';
    formObject = document.forms['riverform'];
    formObject.elements["stage"].value = value;
    formObject.submit();
 
	}	

    $(function () {
        $('#container').highcharts({
            chart: {
                zoomType: 'xy'
            },
            credits: false,
            yAxis:{
                minPadding : 0,
                offset: 5,
                title:{
                    text: '<?php echo $ytitle;?>'
                }
            },
            xAxis:{
			offset : 5,
                title:{
                    text: '<?php echo $xtitle;?>'
                }
            },

            title: {
                text:'Rating Viewer'
            },
            legend: {
                align: 'right',
                layout: 'vertical'
            },
            navigation: {
                buttonOptions: {
                    align: 'left'
                }
            },
            tooltip:{
                positioner: function () {
                    return{x:600,y:250};
                },
                exporting: {
                    csv: {
                        dateFormat: '%Y-%m-%d'
                    }
                  
                },
                
                style: {
                    color: '#333333',
                    fontSize: '9px',
                    padding: '4px'
                },
                crosshairs: [{
                    width: 1,
                    color: 'red'},{
                    width: 1,
                    color: 'red'
                }],
                shared: true,

                positioner: function () {
                        return { x: 80, y: 50 };
                   },
                //pointFormat: '<b>{point.y}</b>',
            },
        series: <?php echo json_encode($hcSeries); ?>

        });
        var chart = $('#container').highcharts(),
	    $button = $('#toggleAll');
        $button.click(function () {
            if(chart.series[2].visible) {
                for(var i =0; i<chart.series.length; i++){
                    chart.series[i].hide();
                }
                $button.html('Show All Curves');
            }
            else{
                for(var i = 0; i < chart.series.length; i++){
                    chart.series[i].show();
                }
                $button.html('Hide All Curves');
            }	
        });   

    });
   </script>
  </head>
  
  
  
  
  <body>
    <h3>Enter USGS Id to Plot:</h3>
    <form id="riverform" method="get" action="ratViewer.php">
      Start:<input name="USGS" value="<? echo $id ?>" size="10">
      <input type="hidden" name="stage" value="x">
      <input id="submit_button" type="submit" value="Submit">
    </form>

    <table class = 'standard'>
      <tr>
        <td>
          <div id="container" style="height: 600px; width: 1000px"></div>
		
        </td>                    
    </tr>
    <tr>
	<div id="legendtools">
		<button id="toggleAll">Show All Curves</button>
           <button onClick="switchAxis('<?php echo $stage;?>')">Swap Axis</button>
	</div>
    </table>
	<?php

    	echo $ratingDetails->render();
	?>
</body>



  





