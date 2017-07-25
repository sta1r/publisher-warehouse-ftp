<?php
define('DOCROOT', rtrim(dirname(__FILE__)."/../../../.."));

require(DOCROOT . '/symphony/lib/boot/bundle.php');
require_once(CORE . "/class.administration.php");
require_once(EXTENSIONS . '/publisher/lib/class.publisher.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisherorder.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisheralert.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publishertransport.php');

/*
-
*/
Class Update_SKUs extends Extension {

	private $csv_data = [];
	private $update_queries = [];
	private $error = false;

	public function __construct() {

		$pageInstance = Administration::instance();
		PublisherTransport::setLocalFilePath(DOCROOT.'/ftp/warehouse/incoming/');

		try {

			$this->parseCSV();
			$this->generateSQL();

			echo "\n---- SKU QUERIES ----";
			foreach ($this->update_queries as $n => $query) {
				echo "\n".$query;
			}

		} catch (Exception $e) {
			echo "ERROR!\n";
			var_dump($e);
		}

	}

	/* See check EA updates - this could be in Parse module or similar */
	private function parseCSV() {

		$row = 1;
		if (($handle = fopen(PublisherTransport::getLocalFilePath()."sku_dump.csv", "r")) !== FALSE) {

			// set the headers as array keys
			$headers = fgetcsv($handle, 1000, ",");
			$i = 1;

			while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {

				$this->csv_data[$i] = array_combine($headers, $data);
				$i++;
			}

			fclose($handle);
		}

	}

	private function generateSQL() {

		foreach ($this->csv_data as $row) {

			$id = trim($row["product_id"]);
			$sku = trim($row["sku"]);

			// update attribute skus
			if ($row["product_attribute_id"] != '') {

				$id = trim($row["product_attribute_id"]);

				$sql = "UPDATE sym_entries_data_763 SET textfield4 = '{$sku}' WHERE unique_id = '{$id}';";

			// update main product skus
			} else {

				$sql = "INSERT INTO sym_entries_data_1849 (entry_id, handle, value) VALUES ('{$id}', '".strtolower($sku)."', '{$sku}') ON DUPLICATE KEY UPDATE handle = '".strtolower($sku)."', value = '{$sku}';";

			}

			$this->update_queries[] = $sql;

		}

		return $update_queries;
	}

}

$updater = new Update_SKUs();

