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
  
  if(!($id = $_GET['site'])) $id = $default;
  
  if(!($stage = $_GET['stage'])) $stage = 'x';
  $hcSeries = array(); 
  $measSeries = array();
 
  if(strlen($id)==5){
    $query = "SELECT rating_shifted,USGSratid,usgs,id FROM ratings where lid = '$id' order by rating_shifted desc limit 1";
  } 
  else{
    $query = "SELECT rating_shifted,USGSratid,usgs,id FROM ratings where usgs = $id order by rating_shifted desc limit 1";
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
  
  echo json_encode($hcSeries);               
?>