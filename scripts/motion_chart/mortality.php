<?php
/*
 *  Grab our mortality indicator data with SPARQL, put it into MySQL where it can be manipulated and 
 *  output into a csv ready for inport into Google Fusion Tables to drive a motion chart. 
 *  This has been hacked together fast to serve a specific purpose and has not yet been cleaned up, in any way.  
 *  Don't judge me.
 */

/////////////////////////////  SETUP
include_once("arc/ARC2.php");

/* configuration */ 
$config = array(
  /* remote endpoint */
  'remote_store_endpoint' => 'http://ec2-79-125-59-17.eu-west-1.compute.amazonaws.com:8890/sparql',
);

/* instantiation */
$store = ARC2::getRemoteStore($config);
$db = new PDO('mysql:host=localhost;dbname=nic_indicators;charset=UTF-8', 'root', '');

//////////////////////////////////////////////////////////////////////////
/* main */

//cache_to_db();
write_csv();

//////////////////////////////////////////////////////////////////////////

function write_csv() {
  global $store;
  global $db;

  // get all area codes
  $q = "
      SELECT DISTINCT areaname
      FROM  nic_indicators
       ";
  $area_res = $db->query($q);
  $years = range('2002', '2009');
  $genders = array('M', 'F');
  $indicators = get_indicators();
  
  $areas = array();
  foreach($area_res as $area) {
    $areas[] = $area;
  }
  
  // loop thorugh permutations and construct rows 
  // for each area there should be years*genders rows 
  $count = 0;
  foreach ($areas as $area) {
    foreach ($years as $year) {
      foreach ($genders as $gender) {
        $count++;
/*  TODO - some sanity checking here - we don't always have both M and F values though
        $count_q = " SELECT count(*)
         FROM  nic_indicators
         WHERE gender = '$gender'
         AND   year = '$year'
         AND   areaname = '{$area['areaname']}'
         ";
        $count_res = $db->query($q);
        
        //$count = $count_res->fetchColumn();
*/
        $q = "
             SELECT icdcode, value
             FROM  nic_indicators
             WHERE gender = '$gender'
             AND   year = '$year'
             AND   areaname = '" .mysql_real_escape_string($area['areaname']). "'
              ";
              print_r($q);
              
        $res = $db->query($q);
        foreach($res as $row) {
      //    print_r($row);
          $result[$area['areaname']][$year][$gender][$row['icdcode']] = $row['value'];

       }
     //   exit();
      }
    }
//    if ($count > 3) break;
  }
//  print_r($result);

  // construct csv file
  // header
  $str = '"Area","Year","Gender",';
  foreach ($indicators as $indicator) {
     $str .= '"'.$indicator['title']. '",';
  }
  $str = substr($str, 0, strlen($str)-1);
  $str .= "\n";
 
  
  // csv rows
  $count = 0;
  foreach ($areas as $area) {
     foreach ($years as $year) {
       foreach ($genders as $gender) {      // this is a row in the csv
         
         $str .= '"' .$area['areaname']. '","' .$year. '","' .$gender. '",';
         // each indicator is a column
         foreach ($indicators as $indicator) {
     /*
           echo 'area='.$area['areaname'];
            echo ' ';
            echo 'year='.$year;
             echo ' ';
             echo 'gender='.$gender;
              echo ' ';
           echo 'icd='.$indicator['icdlabel'];
           echo '          ';
           
           */
           
           if (isset($result[$area['areaname']][$year][$gender][$indicator['icdlabel']])) {
             $value = $result[$area['areaname']][$year][$gender][$indicator['icdlabel']];
             $str .= '"' .$value. '",';
           }
           else $str .= '"",';
         }
         $str = substr($str, 0, strlen($str)-1);
         $str .= "\n";
         $count++;
       }
     }
//     if ($count > 3) break;
   }

 //  echo $str;
   
   $fp = fopen('data.txt', 'w');
   fwrite($fp, $str);
   fclose($fp);

}

function get_indicators() {
  global $store;
  global $db;
  // get all indicators
  $q = "
       SELECT DISTINCT ?title ?icdlabel
       WHERE { ?s a  <http://datashuttle.org/Indicator> ;
       <http://purl.org/dc/elements/1.1/title> ?title ;
       <http://datashuttle.org/icdRange> ?icd .

       ?icd rdfs:label ?icdlabel
       }
       ";
  $indicators = $store->query($q, 'rows');
  return $indicators;
}

function cache_to_db() {
  global $store;
  global $db;

  $indicators = get_indicators();
  
  // lets get some actual data
  $icd_results = array();
  foreach ($indicators as $indicator) {
    $q = '
      SELECT ?s ?lad ?areaname ?year ?icdcode sum(xsd:int(?gendervalue)) as ?value
            WHERE { 
              ?s
                 <http://purl.org/NET/scovo#dimension> ?lad ;

                 <http://purl.org/NET/scovo#dimension> [
                   a <http://reference.data.gov.uk/def/intervals/CalendarYear> ;
                   rdfs:label ?year
                 ] ;

                 <http://purl.org/NET/scovo#dimension> [
                   a <http://purl.org/linked-data/sdmx/2009/code#Sex> ;
                   rdfs:label ?gender
                 ] ;

                 <http://purl.org/NET/scovo#dimension> [
                   a <http://purl.bioontology.org/ontology/ICD10> ;
                   rdfs:label ?icdcode
                 ] ;

                 <http://www.w3.org/1999/02/22-rdf-syntax-ns#value> ?gendervalue
               .

               graph ?lad {
                 ?lad <http://www.w3.org/2004/02/skos/core#altLabel> ?areaname ;
                   a <http://statistics.data.gov.uk/def/administrative-geography/LocalAuthorityDistrict> .

               }
               FILTER(?icdcode = "'  .$indicator['icdlabel']. '")
            }
        ';
        $icd_results = $store->query($q, 'rows');


        $sql = "INSERT INTO  nic_indicators (  areaname ,  year ,  icdcode ,  value ,  id ) 
        VALUES (
        :areaname,  :year,  :icdcode,  :value,  :id
        )";
        $q = $db->prepare($sql);


        // start transaction
        $db->beginTransaction();

        foreach( $icd_results as $result) {
          $q->execute(array(':areaname'=>$result['areaname'],
                             ':year'=>$result['year'],
                             ':icdcode'=>$result['icdcode'],
                             ':value'=>$result['value'],
                             ':id'=>$result['s'],
                             ));
        }
        // end transaction
        $db->commit();
        echo 'done ' .$indicator['icdlabel'];
  }

}




