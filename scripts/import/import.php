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
			'table'	=> 'nic_indicators',
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

		// Output database
		'db_out' => array(
			'table'				=> 'mortality',
			'areas_table'		=> 'mortality_areas',
			'chapters_table'	=> 'mortality_chapters',
			'chapters_file'		=> 'csv/chapters.csv',
			'diseases_table'	=> 'mortality_diseases',
			'diseases_file'		=> 'csv/chapters_hierarchy.csv',
		),

		// Kasabi
		'kasabi' => array(
			'url'	=> 'http://api.kasabi.com/dataset/ordnance-survey-linked-data/apis/sparql',
			'key'	=> '503fd03ef47dfd89b8470301692732332828dba3',
		),
	);

	// Lookup tables
	private $lads = array();
	private $chapters = array();
	private $diseases = array();

	private $adapter;
	private $count = 0;

	public function __construct() {

		global $argc, $argv;

		ini_set('max_execution_time', 0);
		ini_set('memory_limit', '1024M');

		// Connect to DB
		$this->db = new mysqli($this->config['db']['host'], $this->config['db']['user'], $this->config['db']['pass'], $this->config['db']['name']);
		if ($this->db->connect_errno) {
			die("Unable to connect to DB\n");
		}

		// Check what server we're importing to (what adapter to use)
		if ($argc != 2) {
			$this->usage();
		}

		// Init adapter
		switch ($argv[1]) {

			case 'solr':
				$this->adapter = new ImportAdapterSolr($this->config['solr']);
				break;

			case 'es':
				$this->adapter = new ImportAdapterES($this->config['es']);
				break;

			case 'db':
				$config = array_merge($this->config['db_out'], array('db' => $this->db));
				$this->adapter = new ImportAdapterMySQL($config);
				break;

			default:
				$this->usage();

		}

	}

	public function __destruct() {
		$this->db->close();
	}

	private function usage() {
		global $argv;
		die("usage: $argv[0] [solr|es|db]\n");
	}

	public function run() {

		// Fetch ICD chapters
		$this->fetchChapters($this->config['db_out']);

		// Fetch ICD diseases
		$this->fetchDiseases($this->config['db_out']);

		// Fetch LADs
		$this->fetchLADs($this->config['db_out']);

		// Delete existing index
		$this->adapter->deleteIndex();

		// Fetch all rows from DB
		$result = $this->db->query('SELECT * FROM ' . $this->config['db']['table']);
		if (!$result) {
			die("Unable to query DB\n");
		}

		$aggregate = array();
		$start = time();

		// Iterate over DB rows adding each one
		while ($row = $result->fetch_assoc()) {

			// Extract LAD from row ID
			list($icd, $area, $year, $gender) = $this->parseId($row['id']);

			// Lookup LAD
			$area_id = $this->lads[$area];
			if (!isset($area_id)) {
				die("ERROR: Couldn't find LAD: $area\n");
			}

			// Lookup disease
			$disease_id = $this->diseases[$row['icdcode']];
			if (!isset($disease_id)) {
				print_r($this->diseases);
				die("ERROR: Couldn't find disease: $row[icdcode]\n");
			}

			// Create document
			$doc = array(
				'id'			=> $row['id'],
				'disease_id'	=> $disease_id,
				'area_id'		=> $area_id,
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

		// Stats
		$len = time() - $start;
		printf("\nAdded %d rows in %d seconds (~%d rows per second)\n", $this->count, $len, $this->count / $len);

	}

	private function fetchChapters($config) {

		print "Fetching chapters...";

		// Detect line endings so we don't choke on CR only files
		ini_set('auto_detect_line_endings', true);

		// Open chapters CSV
		$csv = fopen($config['chapters_file'], 'r');
		if ($csv === false) {
			die("Unable to open chapters CSV file: $config[chapters_file]\n");
		}

		// Create table
		$result = $this->db->query("
			CREATE TABLE IF NOT EXISTS `$config[chapters_table]` (
				`chapter_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				`chapter_code` varchar(255) NOT NULL,
				`chapter_name` varchar(255) NOT NULL,
				PRIMARY KEY (`chapter_id`)
			) ENGINE=MyISAM  DEFAULT CHARSET=utf8"
		);

		if (!$result) {
			die("Unable to create table: $config[chapters_table]\n");
		}

		$result = $this->db->query("TRUNCATE `$config[chapters_table]`");

		if (!$result) {
			die("Unable to truncate table: $config[chapters_table]\n");
		}

		// Prepare insert statement
		$insert = $this->db->prepare("
			INSERT INTO `$config[chapters_table]` (`chapter_id`, `chapter_code`, `chapter_name`)
			VALUES (NULL, ?, ?)"
		);

		$insert->bind_param('ss', $code, $name);

		$count = 0;

		while (($row = fgetcsv($csv, 4096, ',')) !== false) {

			// Skip header row
			if (++$count === 1) {
				continue;
			}

			// Insert chapter
			$code = $row[0];
			$name = $row[2];
			$insert->execute();

			$this->chapters[$row[1]] = $this->db->insert_id;

		}

		$insert->close();

		printf("done (%d chapters)\n", $count);

	}

	private function fetchDiseases($config) {

		print "Fetching diseases...";

		// Detect line endings so we don't choke on CR only files
		ini_set('auto_detect_line_endings', true);

		// Open chapters CSV
		$csv = fopen($config['diseases_file'], 'r');
		if ($csv === false) {
			die("Unable to open diseases CSV file: $config[diseases_file]\n");
		}

		// Create table
		$result = $this->db->query("
			CREATE TABLE IF NOT EXISTS `$config[diseases_table]` (
				`disease_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				`chapter_id` int(10) unsigned NOT NULL,
				`disease_icd` varchar(255) NOT NULL,
				`disease_name` varchar(255) NOT NULL,
				PRIMARY KEY (`disease_id`)
			) ENGINE=MyISAM  DEFAULT CHARSET=utf8"
		);

		if (!$result) {
			die("Unable to create table: $config[diseases_table]\n");
		}

		$result = $this->db->query("TRUNCATE `$config[diseases_table]`");

		if (!$result) {
			die("Unable to truncate table: $config[diseases_table]\n");
		}

		// Prepare insert statement
		$insert = $this->db->prepare("
			INSERT INTO `$config[diseases_table]` (`disease_id`, `chapter_id`, `disease_icd`, `disease_name`)
			VALUES (NULL, ?, ?, ?)"
		);

		$insert->bind_param('iss', $chapter_id, $icd, $name);

		$count = 0;

		while (($row = fgetcsv($csv, 4096, ',')) !== false) {

			// Skip header row
			if (++$count === 1) {
				continue;
			}

			$chapter = trim($row[3]);

			if (!isset($this->chapters[$chapter])) {
				die("Unknown chapter: $chapter\n");
			}

			$chapter_id = $this->chapters[$chapter];
			$icd = trim($row[0]);
			$name = trim($row[1]);

			// Remove chapter prefix from disease name (if it exists)
			$chapter_num = trim($row[2]);

			if (strpos($name, $chapter_num) === 0) {
				$name = trim(substr($name, strlen($chapter_num)));
			}

			// Insert disease
			$insert->execute();

			$this->diseases[$icd] = $this->db->insert_id;

		}

		$insert->close();

		printf("done (%d diseases)\n", $count);

	}
	private function fetchLADs($config) {

		print "Fetching LADs";

		// Create table
		$result = $this->db->query("
			CREATE TABLE IF NOT EXISTS `$config[areas_table]` (
				`area_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				`area_code` varchar(255) NOT NULL,
				`area_name` varchar(255) NOT NULL,
				`area_boundry` longtext NOT NULL,
				PRIMARY KEY (`area_id`)
			) ENGINE=MyISAM  DEFAULT CHARSET=utf8"
		);

		if (!$result) {
			die("Unable to create table: $config[areas_table]\n");
		}

		// Skip adding LADs if the table isn't empty
		$result = $this->db->query("SELECT area_id, area_code FROM `$config[areas_table]`");
		if ($result && $result->num_rows > 0) {

			while($row = $result->fetch_assoc()) {
				$this->lads[$row['area_code']] = $row['area_id'];
			}

			printf(" done (%d total)\n\n", count($this->lads));
			return;

		}

		$result = $this->db->query("TRUNCATE `$config[areas_table]`");

		if (!$result) {
			die("Unable to truncate table: $config[areas_table]\n");
		}

		// Prepare insert statement
		$insert = $this->db->prepare("
			INSERT INTO `$config[areas_table]` (`area_id`, `area_code`, `area_name`, `area_boundry`)
			VALUES (NULL, ?, ?, ?)"
		);

		$insert->bind_param('sss', $code, $name, $boundry);

		// Fetch LADs
		$limit = 20;
		$offset = 0;

		do {

			$url = sprintf('%s?%s',
				$this->config['kasabi']['url'],
				http_build_query(array(
					'apikey' => $this->config['kasabi']['key'],
					'output' => 'json',
					'query' => sprintf("
						select distinct ?label ?census ?lat ?long ?extent ?GML
						where {
							?s <http://data.ordnancesurvey.co.uk/ontology/admingeo/hasCensusCode> ?census .
							?s <http://www.w3.org/2004/02/skos/core#altLabel> ?label .
							?s <http://www.w3.org/2003/01/geo/wgs84_pos#lat> ?lat .
							?s <http://www.w3.org/2003/01/geo/wgs84_pos#long> ?long .
							?s <http://data.ordnancesurvey.co.uk/ontology/geometry/extent> ?extent .
							?extent <http://data.ordnancesurvey.co.uk/ontology/geometry/asGML> ?GML .
						}
						limit %d
						offset %d",
						$limit,
						$offset
					)
				))
			);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, false);

			$data = curl_exec($ch);
			curl_close($ch);

			$json = json_decode($data, true);
			if (is_null($json) || !isset($json['results']['bindings'])) {
				print_r($data);
				die("Unable to parse JSON data");
			}

			if (count($json['results']['bindings'])) {
				foreach($json['results']['bindings'] as $lad) {

					$code = 'H' . $lad['census']['value'];

					if (isset($this->lads[$code])) {
						// Skip duplicates
						continue;
					}

					$name = $lad['label']['value'];
					$boundry = $this->convertGMLtoGeoJSON($lad['GML']['value'], $code);

					// Add area
					$result = $insert->execute();
					if (!$result) {
						die("Couldn't insert LAD: $code\n");
					}

					$this->lads[$code] = $this->db->insert_id;

				}
			}

			print ".";

			$offset += $limit;

		} while(count($json['results']['bindings']));

		$insert->close();

		printf(" done (%d total)\n\n", count($this->lads));

	}

	private function parseId($id) {

		if (!preg_match('#^http://datashuttle\.org/mortality/([\w\d-%.]+)/([\w\d]+)/(\d+)/([MF])$#', $id, $matches)) {
			die("ERROR: Couldn't parse ID: " . $id . "\n");
		}

		return array($matches[1], $matches[2], $matches[3], $matches[4]);

	}

	private function convertGMLtoGeoJSON($text, $area) {

		require_once 'lib/phpcoord/phpcoord-2.3.php';
		require_once 'lib/polyline-reducer/polyline-reducer.php';

		// Get coordinates from GML
		$doc = new DOMDocument();
		if (!$doc->loadXML($text)) {
			die ("Unable to load GML");
		}

		$posList = $doc->getElementsByTagNameNS('http://www.opengis.net/gml', 'posList')->item(0);

		$points = explode(' ', trim($posList->nodeValue));
		$numPoints = count($points);

		$coords = array();

		for ($i = 0; $i < $numPoints; $i += 2) {

			// Convert Eastings Northings to Latitude Longitude
			$os = new OSRef($points[$i], $points[$i+1]);
			$ll = $os->toLatLng();

			// Convert from OSGB36 datum (used by OS) to WGS84 datum (used by everything else!)
			$ll->OSGB36ToWGS84();

			// Add point to coordinates list
			$coords[] = new GeoPoint($ll->lat, $ll->lng);

		}

		// Simplify polygon using Douglas-Peucker algorithm
		// See: http://en.wikipedia.org/wiki/Ramer%E2%80%93Douglas%E2%80%93Peucker_algorithm
		$reducer = new PolylineReducer($coords);

		$coords = array();
		foreach($reducer->SimplerLine(0.01) as $point) {
			$coords[] = array($point->longitude, $point->latitude);
		}

		// Create GeoJSON feature structure
		$feature = new StdClass();
		$feature->id		= 'area_' . $area;
		$feature->type		= 'Feature';
		$feature->geometry	= array(
			'type'			=> 'Polygon',
			'coordinates'	=> array($coords),
		);

		// Return as JSON string
		return json_encode($feature);

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

class ImportAdapterMySQL implements ImportAdapter {

	private $config;
	private $db;

	public function __construct($config) {

		$this->config = $config;
		$this->db = $config['db'];

		$result = $this->db->query("
			CREATE TABLE IF NOT EXISTS " . $config['table'] . " (
				`id` varchar(128) NOT NULL,
				`disease_id` int(10) NOT NULL,
				`area_id` int(10) unsigned NOT NULL,
				`year` int(10) unsigned NOT NULL,
				`gender` char(1) NOT NULL,
				`value` int(10) unsigned NOT NULL,
				PRIMARY KEY (id),
				KEY `disease_id` (`disease_id`),
				KEY `area_id` (`area_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8"
		);

		if (!$result) {
			die("Unable to create table: " . $config['table']);
		}

	}

	public function addDoc($doc, $count) {

		$insert = $this->db->prepare("
			INSERT INTO `" . $this->config['table'] . "` (`id`, `disease_id`, `area_id`, `year`, `gender`, `value`)
			VALUES (?, ?, ?, ?, ?, ?)"
		);

		if (!$insert) {
			die ("Unable to prepare insert statement");
		}

		$insert->bind_param('siiisi', $doc['id'], $doc['disease_id'], $doc['area_id'], $doc['year'], $doc['gender'], $doc['value']);
		$insert->execute();
		$insert->close();

	}

	public function deleteIndex() {
		return $this->db->query('TRUNCATE ' . $this->config['table']);
	}

}

// GO!
$import = new Import();
$import->run();

