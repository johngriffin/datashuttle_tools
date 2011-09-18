#!/usr/bin/php
<?php

ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

$config = array(
	// Local database
	'db' => array(
		'host'	=> '127.0.0.1',
		'user'	=> 'datashuttle',
		'pass'	=> 'datashuttle',
		'name'	=> 'datashuttle',
		'table'	=> 'nic_indicators',
	),
);

// Connect to DB
$db = new mysqli($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name']);
if ($db->connect_errno) {
	die("Unable to connect to DB\n");
}

// Fetch all rows from DB
$result = $db->query('SELECT area_boundry FROM mortality_areas');
if (!$result) {
	die("Unable to query DB\n");
}

$features = array();
while ($row = $result->fetch_assoc()) {
	$features[] = $row['area_boundry'];
}

printf('{"type":"FeatureCollection","features":[%s]}', implode(',', $features));
