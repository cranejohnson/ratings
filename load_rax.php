<?php

#One time script to import ratings that were exported in CSV format from the RAX database.

/* Include config file for paths etc.....   */
require_once('/usr/local/apps/scripts/bcj/hydroTools/config.inc.php');

$mysqli->select_db("aprfc");

$filename = 'rax_rating.csv';

$lines = file($filename, FILE_IGNORE_NEW_LINES);

$cutoff = strtotime('2012-2-1');

foreach($lines as $line){
    $postingtime = date('Y-m-d H:i',time());
    $bits = explode(',',$line);
    if($bits[0] == 'lid') continue;
    $data['rating_shifted'] = $bits[4];
    $data['lid'] = $bits[0];
    $data['raw_format'] = 'rax';
    $data['raw_file'] = $line;
    $data['source'] = 'raxDB';
    $data['interpolate'] = 'log';
    $data['comment'] = 'Imported from RAX db';
    preg_match('/"{{(.+)}}"/',$line,$matches);
    $xy = explode('},{',$matches[1]);
    
    if(strtotime($data['rating_shifted'])>$cutoff) {
        echo "skipping {$data['lid']} {$data['rating_shifted']}\n";
        continue;
    }    


    
    $discharge = 0;
    $error = false;
    foreach($xy as $pair){
        $v = explode(',',$pair);
        if($v[1]-$discharge < -100){
            echo "{$v[1]} - $discharge  :{$data['lid']}";
            $error = true;
            break;
        }
        $discharge = $v[1];
    }
    if($error){
        echo "Error with curve: {$data['lid']} {$data['rating_shifted']}\n";
        print_r($xy);
        continue;
    }    
 
    
    $insertquery = "Insert into ratings (lid,postingtime,rating_shifted,source,interpolate,raw_file,raw_format,comment) VALUES
            ('{$data['lid']}','$postingtime','{$data['rating_shifted']}',
            '{$data['source']}','{$data['interpolate']}','{$data['raw_file']}','{$data['raw_format']}','{$data['comment']}')";

    #echo $insertquery."<br>";
    
    $mysqli->query($insertquery);

    $ratid = $mysqli->insert_id; 

    if($mysqli->errno == 1062){
        echo"Duplicate curve for {$data['lid']} {$data['rating_shifted']}\n";
        continue;
    }
    if($mysqli->error){
        echo"Error entering curve  {$data['lid']} {$data['rating_shifted']}\n";
        continue;
    }else{
        echo"Entering curve  {$data['lid']} {$data['rating_shifted']}\n";
    }    
    
    
    $values = '';

    foreach($xy as $pair){
        $v = explode(',',$pair);
        $values .= "($ratid,0,".$pair."),";
    }
    
   

    $values = rtrim($values,",");
    $insertquery = "Insert into ratingtables (ratingID,shift,stage,discharge) values $values";

    $result = $mysqli->query($insertquery);
    
    if($mysqli->error){
        echo"Error entering curve  {$mysqli->error}\n";

    }else{
        echo"Entering values for curve  {$data['lid']} {$data['rating_shifted']}\n";
    }  


 
}    
    

?>