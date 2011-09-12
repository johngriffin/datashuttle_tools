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
		'host'	=> 'localhost',
		'port'	=> 8983,
		'path'	=> '/solr',
	),
);

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

	// Extract area ID from row ID
	if (!preg_match('#^http://datashuttle\.org/mortality/[\w\d-%.]+/([\w\d]+)/\d+/[MF]$#', $row['id'], $matches)) {
		printf("ERROR: Couldn't parse ID: %s\n", $row['id']);
		continue;
	}

	// Create document
	$doc = new Apache_Solr_Document();
	$doc->id		= $row['id'];
	$doc->icd		= $row['icdcode'];
	$doc->area		= $matches[1];
	$doc->area_name	= $row['areaname'];
	$doc->year		= $row['year'];
	$doc->gender	= $row['gender'];
	$doc->value		= $row['value'];

	// Add document to index
	try {
		$solr->addDocument($doc);
	} catch (Exception $e) {
		print "ERROR: Couldn't add row\n";
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

