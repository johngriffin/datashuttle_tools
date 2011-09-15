#!/usr/bin/php
<?php

/**
 * Prototype: Import mortality data from database into SOLR
 */

$config = array(
	'db' => array(
		'host'	=> '127.0.0.1',
		'user'	=> 'datashuttle',
		'pass'	=> 'datashuttle',
		'name'	=> 'datashuttle',
	),
	'solr' => array(
		'host'	=> '127.0.0.1',
		'port'	=> 8983,
		'path'	=> '/solr',
	),
	'kasabi' => array(
		'url'	=> 'http://api.kasabi.com/dataset/ordnance-survey-linked-data/apis/sparql',
		'key'	=> '503fd03ef47dfd89b8470301692732332828dba3',
	),
);

$lads = array();

require_once('lib/Apache/Solr/Service.php');
require_once('lib/Apache/Solr/HttpTransport/Curl.php');

ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M'); // HACK: Why is fetch_assoc leaking?

// Connect to DB
$db = new mysqli($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name']);
if ($db->connect_errno) {
    die("Unable to connect to DB\n");
}

// Connect to SOLR
$solr = new Apache_Solr_Service($config['solr']['host'], $config['solr']['port'], $config['solr']['path'], new Apache_Solr_HttpTransport_Curl());
if (!$solr->ping()) {
	die("Unable to connect to SOLR\n");
}

// Fetch LADs
print "Fetching LADs...";

$url = sprintf('%s?%s',
	$config['kasabi']['url'],
	http_build_query(array(
		'apikey' => $config['kasabi']['key'],
		'output' => 'json',
		'query' => "
			select distinct ?label ?census ?lat ?long
			where {
				?s <http://data.ordnancesurvey.co.uk/ontology/admingeo/hasCensusCode> ?census .
				?s <http://www.w3.org/2004/02/skos/core#altLabel> ?label .
				?s <http://www.w3.org/2003/01/geo/wgs84_pos#lat> ?lat .
				?s <http://www.w3.org/2003/01/geo/wgs84_pos#long> ?long .
			}"
	))
);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);

$data = curl_exec($ch);
curl_close($ch);

$data = json_decode($data, true);
if (is_null($data) || !isset($data['results']['bindings'])) {
	die("Unable to parse JSON data");
}

foreach($data['results']['bindings'] as $lad) {
	$lads['H' . $lad['census']['value']] = array(
		'label'	=> $lad['label']['value'],
		'lat'	=> $lad['lat']['value'],
		'lng'	=> $lad['long']['value'],
	);
}

unset($data);
print "done\n\n";

// Delete existing index
$solr->deleteByQuery('*:*');

// Fetch all rows from DB
$result = $db->query('SELECT * FROM nic_indicators');
if (!$result) {
	die("Unable to query DB\n");
}

$start = time();
$count = 0;

// Iterate over DB rows adding each one as a SOLR doc
while ($row = $result->fetch_assoc()) {

	// Extract LAD from row ID
	if (!preg_match('#^http://datashuttle\.org/mortality/[\w\d-%.]+/([\w\d]+)/\d+/[MF]$#', $row['id'], $matches)) {
		printf("ERROR: Couldn't parse ID: %s\n", $row['id']);
		continue;
	}

	// Lookup LAD
	$lad = $lads[$matches[1]];

	if (!isset($lad)) {
		print "ERROR: Couldn't find LAD: $matches[1]\n";
		continue;
	}

	// Create document
	$doc = new Apache_Solr_Document();
	$doc->id			= $row['id'];
	$doc->icd			= $row['icdcode'];
	$doc->area			= $matches[1];
	$doc->area_name		= $lad['label'];
	$doc->area_location	= $lad['lat'] . ',' . $lad['lng'];
	$doc->year			= $row['year'];
	$doc->gender		= $row['gender'];
	$doc->value			= $row['value'];

	// Add document to index
	try {
		$solr->addDocument($doc);
	} catch (Exception $e) {
		print "ERROR: Couldn't add row: " . $e->getMessage() . "\n";
		continue;
	}

	printf("ADDED: %06d (%dMB)\n", ++$count, memory_get_usage()/1024/1024);

	unset($row);
	unset($doc);

}

// Clean-up SOLR
$solr->commit();
$solr->optimize();

// Clean-up DB
$result->free();
$db->close();

// Stats
$len = time() - $start;
printf("\nAdded %d rows in %d seconds (~%d rows per second)\n", $count, $len, $count / $len);

