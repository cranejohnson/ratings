<?php

/**
 * RiverSite - Class to handle river sites with rating curves stored in DB
 *
 * @package Riversite
 */

class RiverSite{

    public $usgs = '';
    public $lid = '';
    public $toCHPS = '';


    public function __construct($logger,$db=null,$site=false) {
        // Check the db resource
        $this->logger = $logger;
        if($db){
            if (!$db->ping()) {
                $this->logger->log("Not a valid database connection",PEAR_LOG_ERR);
            }
            $this->_db = $db;
            if($site) $this->getConfigInfo($site);
        }
        else{
            $this->_db = null;
        }
        

    }

	/**
	 * getConfigInfo
	 *
	 * This method gets basic configuration information about a site from the mysql db
	 * and assigns it as object properties
	 *
	 * @access private
	 * @param string site Site id - can be either NWSLID of USGS ID
	 * @return boolean Returns true if the site was found in the db table
	 */

    private function getConfigInfo($siteID){
        if($this->_db == null) return false;

        //If the site id > 8 this must be a usgs identifier
        if(strlen($siteID) >= 8){
            $query = "Select lid,toCHPS from ratings_config where usgs = $siteID";
            $result = $this->_db->query($query);

            if($result->num_rows == 0){
                $this->logger->log("No NWS LID for USGS site $siteID",PEAR_LOG_DEBUG);
                $this->usgs = $siteID;
                return false;
            }
            $row = $result->fetch_assoc();
            $this->usgs = $siteID;
            $this->lid = $row['lid'];
            $this->toCHPS = $row['toCHPS'];
            return true;
        }

        //If the site id == 5 this must be a nws lid identifier
        if(strlen($siteID) == 5){
            $query = "Select usgs,toCHPS from ratings_config where lid = '$siteID'";
            $result = $this->_db->query($query);
            if($result->num_rows == 0){
                $this->logger->log("No USGS ID for  NWS site $siteID",PEAR_LOG_DEBUG);
                $this->lid = $siteID;
                return false;
            }
            $row = $result->fetch_assoc();
            $this->lid = $siteID;
            $this->usgs = $row['usgs'];
            $this->toCHPS = $row['toCHPS'];
            return true;
        }
    }


	/**
	 * getDBRatings
	 *
	 * This method gets all of the ratings for a site from the database and populates them
	 * into the site object.  Ratings are returned in descending order by date shifted.
     * The newest rating is always the first.
	 *
	 * @access public
	 * @return int Returns the number of ratings loaded into the object
	 */

    public function getDBRatings(){
        $ratings = array();
        $this->ratings = array();

		if(strlen($this->lid)==5){
			$query = "Select * from ratings where lid = '{$this->lid}' order by rating_shifted desc";
		}

        elseif($this->usgs > 0){
			$query = "Select * from ratings where usgs = '{$this->usgs}' order by rating_shifted desc";
		}
        else{
			$this->logger->log("No rating lid specified in getDBRatings subroutine.",PEAR_LOG_ERR);
		}

        //Get a list of ratings from the database

        $result = $this->_db->query($query);
        if(!$result){
            $this->logger->log("No ratings in DB for {$this->lid} {$this->usgs}",PEAR_LOG_DEBUG);
        }
        $i=0;
        while($row = $result->fetch_assoc()){
            $i++;
            $ratings[] = $row['id'];
            $this->loadDBRating($row['id']);
        }
        return $i;
  }

	/**
	 * getWebRating
	 *
	 * This method gets an RDB rating table for NWIS and loads into in the site object
	 *
	 * @access public
	 * @return boolean Returns true if the NWIS rating was loaded into the object
	 */

    public function getWebRating(){

        $url = "http://waterdata.usgs.gov/nwisweb/data/ratings/exsa/USGS.".$this->usgs.".exsa.rdb";
        $this->source = 'USGS Online';

        $this->logger->log("Url for RDB data for USGS site {$this->usgs} is $url",PEAR_LOG_DEBUG);
        if(!$this->usgs){
            $this->logger->log("Rating needs a USGS id defined to download it from the web",PEAR_LOG_WARNING);
            return false;
        }

        $textdata = @file_get_contents($url);
        if(!$textdata) {
            $this->logger->log("Failed to download USGS data for {$this->usgs}",PEAR_LOG_ERR);
            return false;
        }

		$this->logger->log("Downloaded RDB data for USGS site {$this->usgs}",PEAR_LOG_DEBUG);

		//Load the RDB rating into the object and return
        return $this->loadRDB($textdata);;
    }

	/**
	 * loadDBRating
	 *
	 * This method loads a particular rating from the database into the site object.
	 *
	 * @access private
	 * @param integer id Rating id to be loaded
	 * @return boolean Returns true if the rating was loaded into the site object
	 */

    private function loadDBRating($id){
        $rating = array();
        $rating['id'] = $id;

        //Get Rating info from Database
        #$query = "Select USGSratid,rating_shifted,postingtime,source,raw_file,interpolate,minStage,maxStage from ratings where id = $id";
        $query = "Select * from ratings where id = $id";

        $result = $this->_db->query($query);
        if(!$result){
            $this->logger->log("Problem getting rating from DB:".$this->_db->error,PEAR_LOG_ERR);
            return false;
        }

        $row = $result->fetch_assoc();
        foreach($row as $k => $v) $rating[$k] = $v;

        $query = "Select stage,shift,discharge from ratingtables where ratingID = $id";
        $result = $this->_db->query($query);
        if(!$result){
            $this->logger->log("Problem getting rating from DB:".$this->_db->error,PEAR_LOG_ERR);
            return false;
        }
        while($row = $result->fetch_assoc()){
            $rating['values'][]  = $row;
        }
        $this->ratings[] = $rating;
		return true;

    }

    /**
	 * loadPiXML
	 *
	 * This private method loads an PiXML rating into the site object.
	 *
	 * @access private
	 * @param string piXML the piXML rating file string
	 * @return boolean Returns true the rdb information was loaded into the site object
	 */

    function import_piXML($xml){
        $rating = array();
        $rating['raw_file'] = "<xmp>".$xml."</xmp>";
        $this->logger->log("Working on rating.",PEAR_LOG_INFO);
        // Load the XML returned into an object for easy parsing
        $xml_tree = simplexml_load_string($xml);
        if ($xml_tree === FALSE)
        {
            $this->logger->log("Failed to load xml data.",PEAR_LOG_ERR);
            return false;
        }
        $tz = (string)$xml_tree->timeZone;

        foreach ($xml_tree->ratingCurve as $xmlrating){
            $this->lid = (string)$xmlrating->header->locationId;
            $date = $xmlrating->header->startDate['date']." ".$xmlrating->header->startDate['time'];

            $rating['rating_shifted'] = $date;
			$rating['raw_format'] = 'piXML';
            $stageUnit = strtolower((string)$xmlrating->header->stageUnit);
            if($stageUnit != 'ft'){
                $this->logger->log("Rating needs to be in feet.",PEAR_LOG_ERR);
                return false;
            }
            $dischargeUnit = strtolower((string)$xmlrating->header->dischargeUnit);
            if($dischargeUnit != 'cfs'){
                $this->logger->log("Rating needs to be in cfs.",PEAR_LOG_ERR);
                return false;
            }
            $rating['comment'] = (string)$xmlrating->header->comment;
            $this->source = (string)$xmlrating->header->sourceOrganisation;

            if((string)$xmlrating->table->interpolationMethod == 'linear'){
                $rating['interpolate'] = 'lin';
            }
            else{
                $rating['interpolate'] = 'log';
            }

            $rating['minStage'] = (string)$xmlrating->table->minStage;
            $rating['maxStage'] = (string)$xmlrating->table->maxStage;

            //Set the max stage to 9999 if specefied as 'INF' in pixml file
            if(strtoupper($rating['maxStage']) == 'INF')  $rating['maxStage'] = 9999;
            $rating['USGSratid'] = "NULL";
            foreach($xmlrating->table->row as $value){
                $array['stage'] = (string)$value['stage'];
                $array['shift'] = 0;
				if($value['logScaleStageOffset']) {
                    $array['logScaleStageOffset'] = (string)$value['logScaleStageOffset'];
                }
                else{
                    $array['logScaleStageOffset'] = 0;
                }
                $array['discharge'] = (string)$value['discharge'];
                $rating['values'][] = $array;
            }

            $this->ratings[] = $rating;

        }
        return true;

    }



	/**
	 * loadRDB
	 *
	 * This private method loads an RDB rating into the site object.
	 *
	 * @access private
	 * @param string rdb the rdb rating file string
	 * @return boolean Returns true the rdb information was loaded into the site object
	 */

    private function loadRDB($rdb){

        $dataheader = 2;
        $commentLines = '#';
        $rating = array();
        $rating['raw_file'] = $rdb;

        $rating['raw_format'] = 'USGSrdb';
		$rating['comment'] = 'Imported from USGS automatically';
		$rating['minStage'] = 0;
		$rating['maxStage'] = 9999;
        //Look through the RDB file and extract: USGS ID, Rating Number and Shift Date
        if(preg_match_all("/RATING ID=\"(.+?)\"/",$rdb,$matches)) $rating['USGSratid'] = "'".$matches[1][0]."'";
        if(preg_match_all("/STATION NAME=\"(.+?)\"/",$rdb,$matches)) $rating['USGSname'] = $matches[1][0];
        if(preg_match_all("/RATING EXPANSION=\"(.+?)\"/",$rdb,$matches)) $rating['interpolate'] = substr($matches[1][0],0,3);
        if(preg_match_all("/RATING SHIFTED=\"(.+?)\"/",$rdb,$matches)){
          $rating['rating_shifted'] =  date('Y-m-d H:i',strtotime($matches[1][0]));
        }
        //Get the USGS ID number from RDB file and set the object ID
        if(preg_match_all("/NUMBER=\"(.+?)\"/",$rdb,$matches)){
            if(is_int($this->usgs) && ($this->usgs != trim($matches[1][0]))){
                $this->logger->log("Opened RDB file did not match the site selected",PEAR_LOG_WARNING);
            }
            if(!is_int($this->usgs)){
                $this->getConfigInfo((int)$matches[1][0]);
            }
        }
      else{
          $this->logger->log("Failed to get USGS ID from RDB file for $location",PEAR_LOG_ERR);
          return false;
        }

        //Explode rdb file into lines
        $data = explode("\n", $rdb);
        if(count($data) == 0){
            $this->logger->log("No rating curve information for {$this->usgs}",PEAR_LOG_ERR);
            return false;
        }
        $reading_header = true;
        $i =0;

        //Read through rdb file lines and ignore header information
        foreach($data as $row){
            if(preg_match("/^$commentLines/",$row)) continue;
            $i++;
            if($i <= $dataheader) continue;
            $parts = preg_split('/\s+/',$row);
            if(count($parts) <3) continue;
            //Only use stage values that are on the 1/10 of a foot interval to minimize
            //data stored in the database
            if(floor($parts[0]*10) == ($parts[0]*10)){
                $array['stage'] = $parts[0];
                $array['shift'] = $parts[1];
                $array['discharge'] = $parts[2];
                $rating['values'][] = $array;
            }
        }
        //Append this current rating to the ratings loaded for the site object
        $this->ratings[] = $rating;
        return true;
    }

	/**
	 * loadCSV
	 *
	 * This private method loads an RDB rating into the site object.
	 *
	 * @access private
	 * @param string csv the csv rating file string
	 * @return boolean Returns true the rdb information was loaded into the site object
	 */

    function loadCSV($csv){

        $commentLines = '#';
        $rating = array();
        $rating['raw_file'] = $csv;
        $rating['values'] = array();
        $rating['raw_format'] = 'CSVupload';
		$rating['minStage'] = 0;
		$rating['maxStage'] = 9999;
        $rating['interpolate'] = 'log';
        $rating['comment'] = '';
        $rating['USGSratid'] = 'null';
        $this->source = '';

        //Look through the RDB file and extract: USGS ID, Rating Number and Shift Date

        if(preg_match_all("/RATING SHIFTED= *\"(.+?)\"/",$csv,$matches)){
            $rating['rating_shifted'] =  date('Y-m-d H:i',strtotime($matches[1][0]));
            $this->logger->log("CSV file shift date:".$rating['rating_shifted'],PEAR_LOG_INFO);
        }
        else{
            $this->logger->log("Failed to get the shift date from csv file",PEAR_LOG_ERR);
            return false;
        }

        //Get the NWSLID from CSV file and set the object ID
        preg_match_all("/NWSLID= *\"(.+?)\"/",$csv,$matches);
        if(strlen($matches[1][0])>0){
            $nwslid = strtoupper($matches[1][0]);
            $this->lid = $nwslid;
            $this->logger->log("CSV file id:".$nwslid,PEAR_LOG_INFO);
        }
        else{
          $this->logger->log("Failed to get NWSLID from csv file",PEAR_LOG_ERR);
          return false;
        }

        //Explode csv file into lines
        $data = explode("\n", $csv);
        if(count($data) == 0){
            $this->logger->log("No rating curve information for {$this->usgs}",PEAR_LOG_ERR);
            return false;
        }
        $reading_header = true;
        $i =0;

        //Read through rdb file lines and ignore header information
        foreach($data as $row){
            if(preg_match("/^$commentLines/",$row)) continue;
            $i++;
            $parts = preg_split('/,/',$row);

            if(count($parts) <2) continue;

            //Only use stage values that are on the 1/10 of a foot interval to minimize
            //data stored in the database

            $array['stage'] = trim($parts[0]);
            $array['discharge'] = trim($parts[1]);
            $rating['values'][] = $array;

        }
        //Append this current rating to the ratings loaded for the site object
        $this->ratings[] = $rating;
        return true;
    }


	/**
	 * loadlocalRDB
	 *
	 * This method reads a local rdb file and loads it into the site object
	 *
	 * @access public
	 * @param string location the file location that holds the rdb information
	 * @return boolean Returns true if the local rdb file was loaded
	 */

    public function loadLocalRDB($location){
        $this->source = 'RDB Archive';
        $textdata = file_get_contents($location);
        if(!$textdata) {
            $this->logger->log("Failed to open RDB file $location.",PEAR_LOG_ERR);
            return false;
        }
        return $this->loadRDB($textdata);
    }

	/**
	 * dbInsertRating
	 *
	 * This loads the first rating in the site object into the database
	 * This function handles duplicate ratings based the usgs id
	 * and the rating_shifted time.
	 *
	 * @access public
	 * @param string location the file location that holds the rdb information
	 * @return boolean Returns true if the rating object was loaded into the database
	 */

    public function dbInsertRating(){
        $postingtime = date('Y-m-d H:i',time());
        $values = '';

        // Insert rating information into the 'ratings' table
        $rawFile = $this->_db->real_escape_string($this->ratings[0]['raw_file']);

        $insertquery = "Insert into ratings (usgs,lid,postingtime,rating_shifted,source,USGSratid,interpolate,minStage,maxStage,raw_file,raw_format,comment) VALUES
            ('{$this->usgs}','{$this->lid}','$postingtime','{$this->ratings[0]['rating_shifted']}',
			'{$this->source}',{$this->ratings[0]['USGSratid']},'{$this->ratings[0]['interpolate']}','{$this->ratings[0]['minStage']}','{$this->ratings[0]['maxStage']}','$rawFile','{$this->ratings[0]['raw_format']}','{$this->ratings[0]['comment']}')";

        #echo $insertquery."<br>";
        $this->_db->query($insertquery);

        $ratid = $this->_db->insert_id;

        if($this->_db->errno == 1062){
            $this->logger->log("dbInsertRating duplicate rating for {$this->usgs} {$this->lid}",PEAR_LOG_DEBUG);
            return false;      //Duplicate value exit out of ingest function
        }
        if($this->_db->error){
            $this->logger->log("dbInsertRating error for {$this->usgs} {$this->lid} {$this->_db->error}",PEAR_LOG_ERR);
            #echo $insertquery."<br>";
            return false;
        }



		$columns = array();
        // Insert rating table into the 'ratingtables' table
		foreach(array_keys($this->ratings[0]['values'][0]) as $key){
			$columns[] = "$key";
		}

        for($i=0;$i<count($this->ratings[0]['values']);$i++){
			$vals = array();
			foreach($columns as $col){
				$vals[] .= $this->ratings[0]['values'][$i][$col];
			}
	        $values .= "($ratid,".implode($vals,',')."),";
        }

		$values = rtrim($values,",");
        $insertquery = "Insert into ratingtables (ratingID,".implode($columns,",").") values $values";



		#echo $insertquery;

        $result = $this->_db->query($insertquery);



        if(($this->_db->error )&&($this->_db->errno != 1062)){
            $this->logger->log("Db error inserting rating:".$this->_db->error,PEAR_LOG_ERR);
            $this->_db->query("delete from ratings where id = $ratid");
            $this->logger->log("dbInsertRating error rolled back id=$ratid",PEAR_LOG_ERR);
            return false;
        }

        if($this->_db->error){
            $this->logger->log("dbInsertRating error for {$this->usgs} {$this->lid} :".$this->_db->error,PEAR_LOG_ERR);
            #echo $insertquery."<br>";
            return false;
        }

        $this->logger->log("Rating (id=$ratid) inserted into DB for {$this->usgs} {$this->lid}",PEAR_LOG_INFO);

        return $ratid;
    }


	/**
	 * whfsFormat
	 *
	 * Converts the rating loaded in the site object to whfs input format
	 *
	 * @access public
	 * @return string Returns the rating in whfs insert format
	 */

    public function whfsFormat(){
        /**
         * Sample Format to ingest in to WHFS  filename 'UCHA2.sql'
         * //delete from rating
         * //where lid = 'UCHA2';\
         * //insert into rating
         * //values ('UCHA2', 14.20, 148.0);
         */

        $whfsFormat = '';
        $whfsFormat .= "delete from rating where lid = '{$this->lid}';\n";
        $shiftdate = date('Y-m-d',strtotime($this->ratings[0]['rating_shifted']));
        $whfsFormat .= "update riverstat set ratedat = '$shiftdate' where lid = '{$this->lid}';\n";
        $whfsFormat .= "update riverstat set usgs_ratenum = '{$this->ratings[0]['USGSratid']}' where lid = '{$this->lid}';\n";
        foreach($this->ratings[0]['values'] as $array){
            $whfsFormat .= "insert into rating\n";
            $whfsFormat .= "values('{$this->lid}',{$array['stage']},{$array['discharge']});\n";
        }
        return $whfsFormat;
    }

    /**
	 * piXMLformat
	 *
	 * Converts the rating loaded in the site object to piXML format
	 *
	 * @access public
	 * @return string Returns the rating in whfs insert format
	 */

    public function chpsPiXMLFormat(){
        /**
         * See http://fews.wldelft.nl/shcemas/version1.0/pi-schemas/pi_ratings.xsd for piXML format information
         */
        $daysBack = 15;
        $minStage = $this->ratings[0]['values'][0]['stage'];
        $maxStage = 'INF';
        $stageUnit = 'FT';
        $dischargeUnit = 'CFS';

        $piXML = new DOMDocument('1.0', 'utf-8');
        $root = $piXML->createElementNS('http://www.wldelft.nl/fews/PI','RatingCurves');
        $piXML->appendchild($root);


        $root->setAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:schemaLocation',
            'http://www.wldelft.nl/fews/PI http://chps1/schemas/pi-schemas/pi_ratingcurves.xsd');

        $root->setAttributeNS(
            '',
            'version',
            '1.2');


        $element = $piXML->createElement('timeZone','0.0');
        $root->appendChild($element);

        $rating = $piXML->createElement('ratingCurve');

        //Build the piXML header
        $header = $piXML->createElement('header');
        $loc = $piXML->createElement('locationId',$this->lid);
        $header->appendChild($loc);
        $start = $piXML->createElement('startDate');
        $startAtt = $piXML->createAttribute('date');
        $startAtt->value = date('Y-m-d',strtotime($this->ratings[0]['rating_shifted']." -$daysBack days"));
        $start->appendChild($startAtt);
        $startAtt = $piXML->createAttribute('time');
        $startAtt->value = date('H:i:s',strtotime($this->ratings[0]['rating_shifted']));
        $start->appendChild($startAtt);
        $header->appendChild($start);
        $stgUnit = $piXML->createElement('stageUnit','FT');
        $header->appendChild($stgUnit);
        $dsgUnit = $piXML->createElement('dischargeUnit','CFS');
        $header->appendChild($dsgUnit);
        $source = $piXML->createElement('sourceOrganisation',$this->ratings[0]['source']);
        $header->appendChild($source);
        $rating->appendChild($header);


        //Build the piXML rating table
        $table = $piXML->createElement('table');
        if($this->ratings[0]['interpolate'] == 'lin'){
             $chpsMethod = 'linear';
        }else{
            $chpsMethod = 'logarithmic';
        }
        $intMeth = $piXML->createElement('interpolationMethod',$chpsMethod);
        $table->appendChild($intMeth);
        $minS = $piXML->createElement('minStage',$minStage);
        $table->appendChild($minS);
        $maxS = $piXML->createElement('maxStage',$maxStage);
        $table->appendChild($maxS);
        foreach($this->ratings[0]['values'] as $array){
            $row = $piXML->createElement('row');
            $rowAtt = $piXML->createAttribute('stage');
            $rowAtt->value = $array['stage'];
            $row->appendChild($rowAtt);
            $rowAtt = $piXML->createAttribute('discharge');
            $rowAtt->value = $array['discharge'];
            $row->appendChild($rowAtt);
            $table->appendChild($row);
        }

        $rating->appendChild($table);

        $root->appendChild($rating);
        $piXML->preserveWhiteSpace = false;
        $piXML->formatOutput = true;
        $xml = $piXML->saveXML();

    return $xml;

    }


	/**
	 * hydroDspFormat
	 *
	 * Converts the rating loaded in the site object to hydroDspFormat input format
	 *
	 * @access public
	 * @return string Returns the rating in whfs insert format
	 */

    public function hydroDspFormat(){
        /**
         * Sample Format to ingest in to hydrodisplay
         * //UCHA2 15493000 55 17.0 20141031 chena r nr two rivers ak
         * //14.20 148
         * //14.40 217
         *
         */

        $shiftdate = date('Ymd',strtotime($this->ratings[0]['rating_shifted']));
        $hdFormat = '';
        $hdFormat .= $this->lid." ".$this->usgs." ".count($this->ratings[0]['values'])." ".$this->ratings[0]['USGSratid']." ".$shiftdate." Location Name\n";
        foreach($this->ratings[0]['values'] as $array){
            $hdFormat .= $array['stage']." ".$array['discharge']."\n";
        }

        return $hdFormat;
    }



	/**
	 * ratingsToChps
	 *
	 * Sends a rating curve formats to Chps via the Ldad
	 *     -RBD formatOutput   'rating_rdb_USGS.15097000.141120'
     *     -TXT formatOutput   'rating_txt_USGS.15097000.141120'
	 *
	 * @access public
	 * @return boolean Returns true if file transfer is successful
     *
     *  NEED TO RE-FACTOR THIS AND IMRPOVE
	 */
	public function ratingToChps($sa_oc){
        $success = true;
		//If the site object does not have ratings loaded, then load ratings
		if(!isset($this->ratings[0])){
			$this->getDBRatings();
		}
        if(!$this->toCHPS) {
            $this->logger->log("no piXML rating {$this->lid}, this site is not configured for CHPS",PEAR_LOG_INFO);
            return false;
        }
        //Send the CHPS pixml file to the LDAD
        //this file gets moved over to chps for ingest directly

        $piXMLfilename = TO_LDAD."rating_pixml".$sa_oc."_".$this->lid.".".date('Ymd',strtotime($this->ratings[0]['rating_shifted'])).".xml";
		if(file_put_contents($piXMLfilename,$this->chpsPiXMLFormat())){
            chmod($piXMLfilename,0777);
            $this->logger->log("Saved piXML rating in the toLDAD directory {$this->lid}",PEAR_LOG_INFO);
        }else{
            $this->logger->log("Failed to save piXML rating {$this->lid}",PEAR_LOG_ERR);
            $success = false;
        }

        return $success;
  	}





	/**
	 * ratingsToAwips
	 *
	 * Sends three rating curve formats to Awips via the Ldad
	 *     -WHFS formatOutput  'rating_whfs_ucha2.sql'
	 *     -RBD formatOutput   'rating_rdb_USGS.15097000.141120'
	 *     -Hydro Display Format 'rating_hydrodisplay_ucha2'
	 *
	 * @access public
     * @param string 'oc' to transfer to OC 'sa' to transfer to fews SA
	 * @return boolean Returns true if file transfer is successful
     *
     *  NEED TO RE-FACTOR THIS AND IMRPOVE
	 */
	public function ratingToAwips(){
		//If the site object does not have ratings loaded, then load ratings
		if(!isset($this->ratings[0])){
			$this->getDBRatings();
		}

        $success = true;

        //If there is a NWS lid established stage send the hydrodisplay and whfs formated rating curves to the ldad
        if($this->lid){
            $HDfilename = TO_LDAD."rating_hydrodisplay_".strtolower($this->lid);
            if(file_put_contents($HDfilename,$this->hydroDspFormat())){
                chmod($HDfilename,0777);
                $this->logger->log("Saved hydrodisplay rating in the toLDAD directory {$this->usgs}",PEAR_LOG_INFO);
            }else{
                $this->logger->log("Failed to save hydroDspFormat rating {$this->usgs} ",PEAR_LOG_ERR);
                $success = false;
            }
            $whfsfilename = TO_LDAD."rating_whfs_".$this->lid.".sql";
            if(file_put_contents($whfsfilename,$this->whfsFormat())){
                chmod($whfsfilename,0777);
                $this->logger->log("Saved whfsFormat rating in the toLDAD directory {$this->usgs}",PEAR_LOG_INFO);
            }else{
                $this->logger->log("Failed to save whfsFormat rating {$this->usgs} ",PEAR_LOG_ERR);
                $success = false;
            }
        }

        //Send the RDB file to the LDAD
        if(strlen($this->ratings[0]['raw_file'])>0 && ($this->ratings[0]['raw_format'] == 'USGSrdb')) {
            $rdbfilename = TO_LDAD."rating_rdb_USGS".$this->usgs.".".date('Ymd',strtotime($this->ratings[0]['rating_shifted']));
            if(file_put_contents($rdbfilename,$this->ratings[0]['raw_file'])){
                chmod($rdbfilename,0777);
                $this->logger->log("Saved rdb rating in the toLDAD directory {$this->usgs}",PEAR_LOG_INFO);
            }else{
                $this->logger->log("Failed to save rdb rating {$this->usgs}",PEAR_LOG_ERR);
                $success = false;
            }
        }
        else{
            $this->logger->log("No RDB rating available for {$this->lid}",PEAR_LOG_INFO);
        }
        return $success;
  	}

	/**
	 * checkStability  - checks stability of the rating curves
 	 *
	 * @access public
	 * @return array Returns array that (totalvar,recentvar)
	 */
    public function checkStability(){

        //Look through rating curves and calculated the max stage to use for comparison
        $maxStage = 9999;
        $minStage = -9999;
        $maxQ = 9999;
        $minQ = -9999;
        $stddev = array();
        $diff = array();
        $allratings = array();
        $y=0;
        foreach($this->ratings as $rating){
            $stages = array();
            $discharges = array();
            foreach($rating['values'] as $point){
                    $stages[] = $point['stage'];
                    $discharges[] = $point['discharge'];
                    $allratings[$point['stage']]['all'][] = $point['discharge'];
                    $allratings[$point['stage']]["$y"] = $point['discharge'];
            }
            $y++;
        }
        $numStage = count($allratings);

        $y=0;
        foreach($allratings as $stage){
            $y++;
            if($y<($numStage/3)) continue;

            $d = 0;
            if(isset($stage[0]) && isset($stage[1])){
                $d = abs($stage[0]-$stage[1]);
            }
            $avg = average($stage['all']);
            $stddev[] = variance($stage['all'])/$avg*100;
            $diff[] = ($d/$stage[0])*100;

        }


        return array('totalvar' => max($stddev),
                     'recentvar' => max($diff));
    }


	public function __destruct() {

   }


}

?>