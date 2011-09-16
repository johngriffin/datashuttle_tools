#!/usr/bin/php
<?php

/**
 * Prototype: Import mortality data from database into SOLR or ElasticSearch
 */

class Import {

	private $config = array(
		// Local database
		'db' => array(
			'host'	=> '127.0.0.1',
			'user'	=> 'datashuttle',
			'pass'	=> 'datashuttle',
			'name'	=> 'datashuttle',
		),

		// Solr server
		'solr' => array(
			'host'	=> '127.0.0.1',
			'port'	=> 8983,
			'path'	=> '/solr',
		),

		// ElasticSearch server
		'es' => array(
			'host'		=> 'localhost',
			'port'		=> 9200,
			'index'		=> 'mortality',
			'type'		=> 'observation',
		),

		// Kasabi
		'kasabi' => array(
			'url'	=> 'http://api.kasabi.com/dataset/ordnance-survey-linked-data/apis/sparql',
			'key'	=> '503fd03ef47dfd89b8470301692732332828dba3',
		),
	);

	private $lads = array();
	private $adapter;
	private $count = 0;

	public function __construct() {

		ini_set('max_execution_time', 0);
		ini_set('memory_limit', '128M');

		// Check what server we're importing to (what adapter to use)
		$opt = getopt('s:');

		if (!count($opt) || ($opt['s'] != 'solr' && $opt['s'] != 'es')) {
			die("usage: ./import.php -s [solr|es]\n");
		}

		// Init adapter
		if ($opt['s'] == 'solr') {
			$this->adapter = new ImportAdapterSolr($this->config['solr']);
		} elseif ($opt['s'] == 'es') {
			$this->adapter = new ImportAdapterES($this->config['es']);
		}

	}

	public function run() {

		// Fetch LADs
		$this->fetchLADs();

		// Delete existing index
		$this->adapter->deleteIndex();

		// Connect to DB
		$db = new mysqli($this->config['db']['host'], $this->config['db']['user'], $this->config['db']['pass'], $this->config['db']['name']);
		if ($db->connect_errno) {
			die("Unable to connect to DB\n");
		}

		// Fetch all rows from DB
		$result = $db->query('SELECT * FROM nic_indicators');
		if (!$result) {
			die("Unable to query DB\n");
		}

		$aggregate = array();
		$start = time();

		// Iterate over DB rows adding each one as a SOLR doc
		while ($row = $result->fetch_assoc()) {

			// Extract LAD from row ID
			list($icd, $area, $year, $gender) = $this->parseId($row['id']);

			// Lookup LAD
			$lad = $this->lads[$area];

			if (!isset($lad)) {
				print "ERROR: Couldn't find LAD: $area\n";
				continue;
			}

			// Create document
			$doc = array(
				'id'			=> $row['id'],
				'icd'			=> $row['icdcode'],
				'area'			=> $area,
				'area_name'		=> $lad['label'],
				'area_location'	=> $lad['lat'] . ',' . $lad['lng'],
				'year'			=> $row['year'],
				'gender'		=> $row['gender'],
				'value'			=> $row['value'],
			);

			// Add to index
			$this->adapter->addDoc($doc, $this->count);

			printf("ADDED: %06d (%dMB)\n", ++$this->count, memory_get_usage()/1024/1024);

		}

		// Clean-up DB
		$result->free();
		$db->close();

		// Stats
		$len = time() - $start;
		printf("\nAdded %d rows in %d seconds (~%d rows per second)\n", $this->count, $len, $this->count / $len);

	}

	private function fetchLADs() {

		print "Fetching LADs...";

		$url = sprintf('%s?%s',
			$this->config['kasabi']['url'],
			http_build_query(array(
				'apikey' => $this->config['kasabi']['key'],
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
			$this->lads['H' . $lad['census']['value']] = array(
				'label'	=> $lad['label']['value'],
				'lat'	=> $lad['lat']['value'],
				'lng'	=> $lad['long']['value'],
			);
		}

		print "done\n\n";

	}

	private function parseId($id) {

		if (!preg_match('#^http://datashuttle\.org/mortality/([\w\d-%.]+)/([\w\d]+)/(\d+)/([MFA])$#', $id, $matches)) {
			die("ERROR: Couldn't parse ID: " . $id . "\n");
		}

		return array($matches[1], $matches[2], $matches[3], $matches[4]);

	}

}

interface ImportAdapter {

	public function __construct($config);
	public function addDoc($doc, $count);
	public function deleteIndex();

}

class ImportAdapterSolr implements ImportAdapter {

	private $config;
	private $solr;

	public function __construct($config) {

		$this->config = $config;

		require_once('lib/Apache/Solr/Service.php');
		require_once('lib/Apache/Solr/HttpTransport/Curl.php');

		$this->connect();
		if (!$this->solr->ping()) {
			die("Unable to connect to SOLR\n");
		}

	}

	public function __destruct() {
		$this->cleanUp();
	}

	public function addDoc($doc, $count) {

		// Create document
		$solrDoc = new Apache_Solr_Document();
		foreach($doc as $field => $value) {
			$solrDoc->$field = $value;
		}

		// Add document to index
		try {
			$this->solr->addDocument($solrDoc);
		} catch (Exception $e) {
			print "ERROR: Couldn't add row: " . $e->getMessage() . "\n";
			continue;
		}

		// HACK: Re-create Solr object every 100,000 rows to stop memory usage going mental
		if (($count % 10000) == 0 && $count != 0) {

			print "\nRe-creating Solr client object...";

			$this->cleanUp();
			$this->connect();

			print "done\n\n";

		}

	}

	public function deleteIndex() {
		print "Deleting existing index...";
		$this->solr->deleteByQuery('*:*');
		print "done\n\n";
	}

	private function connect() {
		$this->solr = new Apache_Solr_Service($this->config['host'], $this->config['port'], $this->config['path'], new Apache_Solr_HttpTransport_Curl());
	}

	private function cleanUp() {
		$this->solr->commit();
		$this->solr->optimize();
	}

}

class ImportAdapterES implements ImportAdapter {

	private $config;

	public function __construct($config) {
		$this->config = $config;
	}

	public function addDoc($doc, $count) {
		return $this->request('POST', $this->config['type'] . '/', $doc);
	}

	public function deleteIndex() {
		return $this->request('DELETE');
	}

	private function request($method, $action = '', $object = null) {

		if (!is_null($object)) {
			$data = json_encode($object);
		}

		$url = sprintf('http://%s:%d/%s/%s',
			$this->config['host'],
			$this->config['port'],
			$this->config['index'],
			$action
		);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

		if (!is_null($object)) {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			curl_setopt($ch, CURLOPT_POSTFIELDSIZE, strlen($data));
		}

		$response = json_decode(curl_exec($ch));

		if (is_null($response)) {
			return false;
		}

		return (bool)$response->ok;

	}

}

// GO!
$import = new Import();
$import->run();

