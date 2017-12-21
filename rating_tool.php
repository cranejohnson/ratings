 <?php
/**
 * Php Script to handle APRFC ratings curves
 *
 * This script mainly consists of a 'riverSite' object which has methods to:
 * 1. Check for newly update USGS ratings
 * 2. Ingest USGS rdb ratings and store them in a mysql db table(s)
 * 3. Format the rating for WHFS and hydrodisplay
 * 4. Keep track of historical ratings
 * 5. On command export a rating in both USGS RDB format, WHFS format and hydrodisplay format to re-ingest into AWIPS
 * 6. Be able to plot historic ratings side by side
 * 7. more......
 * @package APRFC Rating Tool
 * @author Crane Johnson <benjamin.johnson@noaa.gov>
 * @version 0.1
 */

ini_set('memory_limit', '512M');
set_time_limit(300);
date_default_timezone_set('UTC');


/* Include config file for paths etc.....   */
require_once('/usr/local/apps/scripts/bcj/hydroTools/config.inc.php');
require_once "/var/www/html/tools/PHPMailer/PHPMailerAutoload.php";

define("LOG_TYPE","DB");

/* Include Pear log package                 */
require_once 'Log.php';

/* Include Files for php graphing           */
require_once '/usr/local/apps/scripts/bcj/jpgraph/src/jpgraph.php';
require_once '/usr/local/apps/scripts/bcj/jpgraph/src/jpgraph_line.php';
require_once '/usr/local/apps/scripts/bcj/jpgraph/src/jpgraph_scatter.php';
require_once '/usr/local/apps/scripts/bcj/jpgraph/src/jpgraph_date.php';

/* Include rating library file */
require_once 'rating_lib.php';


define("RATINGS_DEPOT","http://waterdata.usgs.gov/nwisweb/get_ratings");

/**
 * Setup PEAR logging utility
 */

//Console gets more information, file and sql only get info and above errors
$consoleMask = Log::MAX(PEAR_LOG_DEBUG);
$fileMask = Log::MAX(PEAR_LOG_INFO);
$sqlMask = Log::MAX(PEAR_LOG_INFO);

$sessionId = 'ID:'.time();
if(LOG_TYPE == 'DB'){
    $conf = array('dsn' => "mysqli://".DB_USER.":".DB_PASSWORD."@".DB_HOST."/".DB_DATABASE,
            'identLimit' => 255);

    $sql = Log::factory('sql', 'log_table', __file__, $conf);
    $sql->setMask($sqlMask);
    $console = Log::factory('console','',$sessionId);
    $console->setMask($consoleMask);
    $logger = Log::singleton('composite');
    if(php_sapi_name() === 'cli') $logger->addChild($console);
    $logger->addChild($sql);
}
if(LOG_TYPE == 'FILE'){
    $script = basename(__FILE__, '.php');
    $file = Log::factory('file',LOG_DIRECTORY.$script.'.log',$sessionId);
    $file->setMask($fileMask);
    $console = Log::factory('console','',$sessionId);
    $console->setMask($consoleMask);
    $logger = Log::singleton('composite');
    $logger->addChild($console);
    $logger->addChild($file);
}
if(LOG_TYPE == 'NULL'){
    $logger = Log::singleton('null');
}

/**
 * Define the debugging state, defaults to false
 */

if(isset($_POST['debug'])){
	$debug = $_POST['debug'];
}




$mysqli->select_db("aprfc");
$action = null;
$site = null;
$debug = 'false';
$startTime = date('Y-m-d H:i:s',time()-5);





/**
 *  SET UP CRUD DB EDITING
 */

if(isset($_SERVER['REQUEST_METHOD'])){

    include('/var/www/html/tools/xcrud_1_6_25/xcrud/xcrud.php');

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

    #if($debug == 'false')$log_results->start_minimized(true);

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


}







/**
 * sendEmail
 *
 * This function sends out a rating curve update status email
 *
 * @access public
 * @param logger object Error logging object
 * @param mysqli object mysqli database object
 * @param updatedSites array of sites updated
 * @param overRideEmail string email address(s) to use instead of the default
 * @return nothing
 *
 */
function sendEmail($logger,$mysqli,$updatedSites,$Files,$overRideEmail = false){
    global $startTime;
    $recipients = array(
       'dave.streubel@noaa.gov' => 'Dave',
       'scott.lindsey@noaa.gov' => 'Scott',
       'edward.moran@noaa.gov' => 'Ted',
       'celine.vanbreukelen@noaa.gov' => 'Celine',
       'andrew.dixon@noaa.gov' => 'Andy',
       'karen.endres@noaa.gov' => 'Karen',
       'Aaron.Jacobs@noaa.gov' => 'Aaron',
       'jessica.cherry@noaa.gov' => 'Jessie');
  
    //If an overRideEmail is provided use that
    if($overRideEmail){
        $recipients = $overRideEmail;
    }

    if(count($updatedSites) > 0){
        $list = implode(',',$updatedSites);
    }
    elseif(count($updatedSites)==0){
        $list = 'NONE';
    }
    else{
        $list = $updatedSites[0];
    }


    $query = "select * from log_table where ident like '%rating_tool.php%' and logtime > '$startTime' and priority < 7 order by logtime asc";
    $result = $mysqli->query($query);



    $message = "The following sites had rating curves updated: $list\r\n\r\n";
    $message .="For detailed log information go here:\r\n";
    $message .="    1.  http://140.90.218.62/tools/ratings/rating_tool.php?site=&debug=true\r\n";
    $message .="    2.  /awips/hydroapps/local/ratings/whfs_import.log\r\n\r\n\r\n";
    foreach($updatedSites as $riversite){
        $riversite = new riverSite($logger,$mysqli,$riversite);
        $message .=  "LID: ".$riversite->lid." USGS:".$riversite->usgs."\r\n";
        $message .= "         Plot the last two rating curves http://140.90.218.62/tools/ratings/ratViewer.php?USGS=".$riversite->usgs." (NWS Internal)\r\n";
        $message .= "         Link to usgs rating curve http://waterdata.usgs.gov/nwisweb/data/ratings/exsa/USGS.".$riversite->usgs.".exsa.rdb\r\n\r\n";

    }


    $mail = new PHPMailer;
    
    $mail->FromName = 'nws.ar.aprfc';
    $mail->addAddress('benjamin.johnson@noaa.gov','Crane');

    foreach($recipients as $email => $name)
    {
        $mail->AddAddress($email, $name);
    }
    $mail->AddCC('jostman@usgs.gov','Johnse');
    $mail->Subject = "Rating curves updated: $list";
    $mail->Body = $message;
    foreach($Files as $file){
        $mail->addAttachment($file);
    }
    if(!$mail->send()) {
        echo 'Message could not be sent.';
        echo 'Mailer Error: ' . $mail->ErrorInfo;
        $logger->log("Failed to send Rating Email: ".$mail->ErrorInfo,PEAR_LOG_INFO);
    } else {
         $logger->log("Rating update email sent.",PEAR_LOG_INFO);

    }

}


/**
 * loadArchive
 *
 * This function gets a list of updated USGS ratings curves from the ratings depot
 *
 * @param logger object Error logging object
 * @param mysqli object mysqli
 * @param $archivedir string archive directory where rdb files are located
 * @return nothing
 */

function loadArchive($logger,$mysqli,$archivedir){
    $ratingsUpdated = array();
    foreach(scandir($archivedir) as $file){
        if($file == '.' | $file == '..') continue;
        $location = $archivedir."/".$file;
    	$site = new riverSite($logger,$mysqli);
    	if($site->loadLocalRDB($location)){
      		if($site->dbInsertRating()){
				$ratingsUpdated[] = $ratid;      }
      		else{
        		$logger->log("Failed to load archive rating from file $file",PEAR_LOG_ERR);
      		}
    	}
    }
    $logger->log("Loaded $i ratings from the archive directory:$archivedir",PEAR_LOG_INFO);

}



/**
 * get_updated_USGS_ratings
 *
 * This function gets a list of updated exsa USGS ratings curves from the ratings depot
 *
 * @access public
 * @param logger object Error logging object
 * @param period integer the number of hours back to look for changes
 *                      defaults to 168 hours (7 days)
 * @param sitefilter string grep pattern string to match against lines
 *                      default is '^(15|16)' filters for HI and AK
 * @return sites  array of usgs ratings that are in the usgs update file
 *
 */

function get_updated_USGS_ratings($logger,$period = 168,$sitefilter = '^(15|16)'){
    $sites = array();
    $url = RATINGS_DEPOT."?period=$period";
    #$url = "http://waterdata.usgs.gov/nwisweb/get_ratings?period=$period";
    $textdata = file_GET_contents($url);
    if(!$textdata){
      $logger->log("Unable to open usgs rating curve update file at $url",PEAR_LOG_ERR);
      return $sites;
    }

    $data = explode("\n", $textdata);
    $i = 0;
    foreach($data as $row){
        $info = array();
        if(!preg_match('/^USGS/',$row)) continue;
        $parts = preg_split('/\t/',$row);
        if(!preg_match("/$sitefilter/",$parts[1])) continue;
        if($parts[2] != 'exsa') continue;
        $info['usgs'] = (int)trim($parts[1]);
        $info[]['update_time'] = $parts[3];
        $info[]['rdb_link'] = $parts[4];
        $sites[] =  $info['usgs'];
        $i++;
    }
    $logger->log("$i Alaska or Hawaii sites listed in the USGS updated rating file ($period hrs)",PEAR_LOG_INFO);
    return $sites;
}

/**
 * loadUSGSWebRatings
 *
 * This function loads updated USGS ratings in db
 *
 * @access public
 * @param logger object Error logging object
 * @param mysqli object mysqli database object
 * @param period integer the number of hours back to look for changes
 *                      defaults to 168 hours (7 days)
 * @param sitefilter string grep pattern string to match against lines
 *                      default is '^(15|20)' filters for HI and AK
 * @return array List of ratings that were updated
 */
function loadUSGSWebRatings($logger,$mysqli,$period = 168,$sitefilter = '^(15|20)'){
  $usgsListing = get_updated_USGS_ratings($logger);
  $ratingsUpdated = array();

  foreach($usgsListing as $siteID){
        $site = new riverSite($logger,$mysqli,$siteID);
        if($site->getWebRating()){
            if($ratid = $site->dbInsertRating()){
                $ratingsUpdated[] = $site->usgs;
            }
        }
    }
    $logger->log(count($ratingsUpdated)." rating curves were updated in the system",PEAR_LOG_INFO);

    return $ratingsUpdated;
}



/**
 * Function that calculate average or mean value of array
 * @param (array) $arr
 * @return float average
 */
function average($arr){
	if (!is_array($arr)) return 0;
	return array_sum($arr)/count($arr);
}

/**
 * Calculate variance of array
 * @param (array) $aValues
 * @return float variance
 */
function variance($aValues, $bSample = false){
	$fMean = array_sum($aValues) / count($aValues);
	$fVariance = 0.0;
	foreach ($aValues as $i){
		$fVariance += pow($i - $fMean, 2);
	}
	$fVariance /= ( $bSample ? count($aValues) - 1 : count($aValues) );
	return (float)sqrt($fVariance);
}



/**
 * Create rating curve graphing
 * @param (object) $ratings rating curve object
 * @param  array $curves2plot  list of curves to plot, typically 0,1  (two most recent)
 * @return
 */

 function plotCurves($rCurves,$curves2plot){
    // Create a new timer instance
	$datemax = strtotime('today midnight')+24*3600;
	$datemin = $datemax-8*24*3600;
	$timer = new JpgTimer();

	$created = date("F j, Y, g:i a");

	// Start the timer
	$timer->Push();
    #print_r($rCurves);
    //Generage the graphs using JpGraph library
	$graph = new Graph(800,500);
	$graph->SetMargin(80,50,40,10);
	$graph->SetScale('linlin');
	$graph->legend->SetFont(FF_ARIAL,FS_BOLD,12);
    $graph->legend->SetLayout(LEGEND_VERT);
	$graph->img->SetAntiAliasing(false);
	$graph->title->SetFont(FF_ARIAL,FS_BOLD,12);
    $title = "Rating Curves for: ".$rCurves->lid." (".$rCurves->usgs.") \n(".sizeof($rCurves->ratings)." ratings in db shown in grey)";
	$graph->title->Set($title);
	$graph->SetBox(true,'black',2);
	$graph->SetClipping(true);
    $grey = 80;
    $i = 0;
	$graph->footer->right->Set('Time(ms): ');
	$graph->footer->right->SetFont(FF_ARIAL,FS_NORMAL,10);
	$graph->footer->left->set('Created:'.$created." UTC");
	$graph->footer->SetTimer($timer);
    for($y=sizeof($rCurves->ratings)-1;$y>=0;$y--){
        $curve = $y;
        $graphData = array();
        foreach($rCurves->ratings[$curve]['values'] as $array){
            $graphData[1][] = $array['stage'];
            $graphData[0][] = $array['discharge'];
        }
        if(sizeof($graphData[1])==0) continue;
        $line = new LinePlot($graphData[1],$graphData[0]);
		$graph->Add($line);
        if($y==0){
            $weight = 3;
            $color = 'red';
            $line->SetLegend("Current Rating:".$rCurves->ratings[$curve]['rating_shifted']);
        }
        elseif($y==1){
            $weight = 3;
            $color = 'blue';
            $line->SetLegend("Previous Rating:".$rCurves->ratings[$curve]['rating_shifted']);
        }
        else{
            $weight = 1;
            $color = array($grey,$grey,$grey);
            $grey = $grey + 5;
            if($grey > 220) $grey = 220;
        }
		$line->SetColor($color);
		$line->SetWeight($weight);
        $i++;

    }
	$graph->xaxis->SetFont(FF_ARIAL,FS_BOLD,10);
	$graph->xaxis->SetPos('min');
	$graph->xgrid->SetLineStyle('dashed');
	$graph->xgrid->SetColor('gray');
	$graph->xgrid->Show();
	$graph->xaxis->SetTitle('Discharge (cfs)','middle');


	$graph->yaxis->SetTitle('Stage (ft)','middle');
	$graph->yaxis->SetFont(FF_ARIAL,FS_BOLD,12);
	$graph->yaxis->title->SetFont(FF_ARIAL,FS_BOLD,14);
	$graph->yaxis->scale->SetGrace(20,0);
	#$graph->yaxis->SetTitlemargin(70);
	$graph->SetFrame(true,'darkblue',0);

    $fileName = "/var/www/html/cache/".$rCurves->usgs."_".$rCurves->lid.date('_dMy').".png";
    $graph->Stroke($fileName);
    return($fileName);
}


/**
 * ############################  MAIN PROGRAM LOGIC  ####################################
 */
$logger->log("START",PEAR_LOG_INFO);

$sites = array();

$sendemail = 'false';
$sendTo = array();
$checkForNew = false;


if (isset($_POST["action"])){
    $action = $_POST['action'];
}

if (isset($_POST["sendTo"])){
	$sendTo =$_POST['sendTo'];
}

if (isset($_POST["site"])){
    $sites = explode(',',strtoupper($_POST['site']));
}

if (isset($_POST["sendemail"])){
    $sendemail = $_POST['sendemail'];
}

if($action == 'checkUSGS') $checkForNew = true;


//Check if the script is run from command line....if so just checkForNew sites from USGS
if(php_sapi_name() == 'cli') {
    $action = 'checkForAllNew';
	$sendTo = array('chpsOC','awips');
    $sendemail = 'true';
	$checkForAllNew = 'true';
	$logger->log("Running from command line",PEAR_LOG_INFO);
}

//This is the basic action to perform for cronjobs
if($action == 'checkForAllNew'){

    $logger->log("Checking for USGS rating curves.",PEAR_LOG_INFO);
    //Load any new ratings into rating database and get a list of sites that were
    //updated.
    $sites = loadUSGSWebRatings($logger,$mysqli);
}


if($action == 'allCurves'){
    //Determine which sites need to have ratings sent to chps
    $query = 'SELECT DISTINCT lid FROM `ratings` WHERE lid <> "NULL" order by lid';
    $result = $mysqli->query($query);
    $sendTo = array('fewsSA');
    if(!$result){
        $logger->log("Failed to query database for all ratings going to CHPS",PEAR_LOG_DEBUG);
    }

    while($row = $result->fetch_assoc()){
        array_push($sites,$row['lid']);
    }
}

if($action == 'importFile'){
	//Loop through each file
	for($i=0; $i<count($_FILES['upload']['name']); $i++) {

	//Get the temp file path
	  $tmpFilePath = $_FILES['upload']['tmp_name'][$i];
	  $logger->log("working on file:".$_FILES['upload']['name'][$i]." size:".$_FILES['upload']['size'][$i],PEAR_LOG_INFO);

	  //Make sure we have a filepath
	  if ($tmpFilePath != ""){
		//Setup our new file path
		$newFilePath = "./uploaded_ratings/".$_FILES['upload']['name'][$i];
		//Upload the file into the temp dir
		if(move_uploaded_file($tmpFilePath, $newFilePath)) {

			$logger->log("Moved file:".$_FILES['upload']['name'][$i]." to archive directory",PEAR_LOG_INFO);
            $path_parts = pathinfo($newFilePath);

			$site = new riverSite($logger,$mysqli);
		    if($path_parts['extension'] == 'xml'){
                if(!$site->import_piXML(file_GET_contents($newFilePath))){
                    $logger->log("Failed to read piXML: ".$_FILES['upload']['name'][$i],PEAR_LOG_ERR);
                    //continue to next file!!!!!!!!
                    continue;
                }
            }
            elseif($path_parts['extension'] == 'rdb'){
                if(!$site->loadLocalRDB($newFilePath)){
                    continue;
                }    
             }
            elseif($path_parts['extension'] == 'csv'){
                if(!$site->loadCSV(file_GET_contents($newFilePath))){
                    $logger->log("Failed to read RDB: ".$_FILES['upload']['name'][$i],PEAR_LOG_ERR);
                    //continue to next file!!!!!!!!
                    continue;
                }
             } 
            else{
                $logger->log("Unknown File type: ".$_FILES['upload']['name'][$i],PEAR_LOG_ERR);
            }


			if($site->dbInsertRating()){
				array_push($sites,$site->lid);
			}
			else{
				$logger->log("Failed to push into database.",PEAR_LOG_ERR);
			}

		}
		else{
			$logger->log("Failed to move file:".$_FILES['upload']['name'][$i]." to archive directory",PEAR_LOG_ERR);
		}
	  }
	}
}


//Array of sites that are updated
$sitesUpdated = array();
$graphFiles = array();

//Process each site

foreach($sites as $site){
	echo "working on $site<br>";
    if(!$site) {
        $logger->log("No site specified to update!",PEAR_LOG_NOTICE);
    }
    else{

        $riversite = new riverSite($logger,$mysqli,$site);
        $logger->log("Updating {$riversite->lid}",PEAR_LOG_INFO);
	//Check the USGS site and see if a new curve is available
	if($checkForNew){
		echo "Checking for USGS rating.<br>";
		if($riversite->getWebRating()){
			if($ratid = $riversite->dbInsertRating()){
				$ratingsUpdated[] = $site->usgs;
			}
		}
		else{
			$logger->log("Failed to get USGS web rating for $site",PEAR_LOG_WARNING);
		}
        }
        $sentto = array();

	//Get ratings from local database and send them to where then need to go
        if($riversite->getDBRatings()>0){
	    //Graph the curves with jpgraph and add the file path/name to the array
            $graphFiles[]=plotCurves($riversite,array(0,1));
            
            if(in_array('chpsOC',$sendTo)){
                //Send the rating to chps
                if($riversite->ratingToChps('oc')) $sentto[] = 'CHPS OC';
            }
			
            if(in_array('awips',$sendTo)){
                //Send the rating to AWIPS
                if($riversite->ratingToAwips()) $sentto[] = 'AWIPS';
            }
            if(in_array('fewsSA',$sendTo)){
				//Send the rating to a fews sa
                if($riversite->ratingToChps('sa')) $sentto[] = 'CHPS Fews SA';

            }

            if(count($sentto) > 0) $sitesUpdated[] = $site;

        }
        else{
            $logger->log("No ratings found for site {$riversite->lid} {$riversite->usgs}",PEAR_LOG_WARNING);
        }
        $logger->log("COMPLETED updating {$riversite->lid}",PEAR_LOG_INFO);
    }
}


if($sendemail == 'true') sendEmail($logger,$mysqli,$sitesUpdated,$graphFiles);

$coeSites = array('CRHA2','UCHA2','MCDA2','CHLA2','CHFA2','TAFA2');
$coeUpdated = array_intersect($coeSites,$sitesUpdated);
$coeFiles = array();
//$coeRecipients = array('cepoaencwhh@usace.army.mil' =>'HH');

foreach($graphFiles as $file){
        foreach($coeSites as $s){
		if(strpos($file,$s) !==false) $coeFiles[] = $file;
        }
}

#if(count($coeUpdated) > 0) sendEmail($logger,$mysqli,$coeUpdated,$coeFiles,$coeRecipients);





if($action == 'stability') {

    $query = 'select usgs,count(*) as cnt from ratings group by usgs having count(*) > 1';
    $result = $mysqli->query($query);
    while($row = $result->fetch_assoc()){
        $site = new riverSite($logger,$mysqli,$row['usgs']);
        $site->getDBRatings();
        $stability = $site->checkStability();
        echo "{$site->usgs} {$stability['totalvar']}   {$stability['recentvar']}<br>";
        $query = "update ratings_config set ratVar = {$stability['totalvar']},maxDelta = {$stability['recentvar']} where lid = '{$site->lid}'";
        $mysqli->query($query);
        if($mysqli->error){
            $logger->log("Error inserting stats:".$mysqli->error,PEAR_LOG_ERR);
            echo $query."<br>";
        }
    }
}

$logger->log("END",PEAR_LOG_INFO);

if(php_sapi_name() === 'cli') exit;

?>



<!DOCTYPE html>
<html>
	<head>
	<meta http-equiv="content-type" content="text/html; charset=utf-8" />
    <script src="//code.jquery.com/jquery-1.9.1.js"></script>
    <script src="//code.jquery.com/ui/1.10.4/jquery-ui.js"></script>

  	</head>

	<body>

   <!-- <form action="xml_upload.php" method="post" enctype="multipart/form-data">
    </form>-->
    <form id="riverform" method="get" action="ratViewer.php">
      Enter USGS or NWS id to plot rating curve:<input name="USGS" value="" size="10">
      <input type="hidden" name="stage" value="x">
      <input id="submit_button" type="submit" value="Plot Rating Curves">
    </form>
	<h3>1. Select a process to update rating curves(s):</h3>
    <form id="riverform"  method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>" enctype="multipart/form-data">
        <input type="radio" name="action" value="importFile" id="fileRadio" >Import pi-XML, RDB or csv rating curves (max 20 files): <input name="upload[]" type="file" id="fileSelect" multiple="multiple" onchange="document.getElementById('fileRadio').checked = true;" /><br>
		<input type="radio" name="action" value="allCurves">Send the most recent rating curve for each site in the database to Fews SA<br>
    	<input type="radio" name="action" value="checkForAllNew">Check for new USGS Ratings for ALL SITES<br>
		<input type="radio" name="action" value="checkUSGS">Check USGS rating Depot for a Specific Site(s):<br>
        <input type="radio" name="action" value="sendFromDB">Send existing rating curves from database for Site(s): <br><br>
		Sites:<input type='text' name='site' size="50" value="">

        <h3>2. Send most recent curves to:</h3>
		<input type="checkbox" name="sendTo[]" value="chpsOC" checked>CHPS OC
        <input type="checkbox" name="sendTo[]" value="fewsSA">CHPS Fews SA
		<input type="checkbox" name="sendTo[]" value="awips" checked>AWIPS (HydroDisplay and WHFS)<br>
		<h3>3. Diagnostics</h3>
		</br>
		<input type="checkbox" name="debug" value="true">Show Debug Log<br>
        <input type="checkbox" name="sendemail" value="true">Send email with process log<br>
		<br>



      	<input id="submit_button" type="submit" value="Submit">
    </form>
    Note: Up to 5 min delay for curves to be sent to AWIPS after stage.  Check log for results.
	<hr>
	<?php
        echo $config_table->render();
    	echo $log_results->render();
	?>

	</body>

