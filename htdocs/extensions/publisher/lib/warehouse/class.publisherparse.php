<?php

Class PublisherParse {

	private $transport;

	public function __construct() {
		$this->transport = new PublisherTransport();
	}

	/**
	 * parseOrderTrackingFiles()
	 * Parses data from any files in /incoming/orders into a single array
	 * @return array $orders
	*/
	public function parseOrderTrackingFiles() {

		// set file path for order updates
		$this->transport->setLocalFilePath(DOCROOT.'/ftp/warehouse/incoming/orders/');

		$local_paths = $this->transport->getLocalPaths($this->transport->getLocalFilePath(), 'csv');
		$local_files = $this->transport->getFileNames($local_paths);

		foreach ($local_files as $file) {
			$handle = fopen($this->transport->getLocalFilePath().$file, "r");

			if ($handle !== FALSE) {

				// set the headers as array keys
				$headers = fgetcsv($handle, 1000, ",");

				while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
					if (!empty($data[0])) {
						$orders[] = array_combine($headers, $data);
					}
				}

				fclose($handle);
			}
		}

		return $orders;
	}

	/**
	 * parseStockUpdateFile()
	 * Parses data from a single ProductStock.csv file
	 * @return array $stock_updates (EA stock levels for all live SKUs)
	*/
	public function parseStockUpdateFile() {

		$this->transport->setLocalFilePath(DOCROOT.'/ftp/warehouse/incoming/stock/');
		$handle = fopen($this->transport->getLocalFilePath()."ProductStock.csv", "r");

		if ($handle !== FALSE) {

			// set the headers as array keys
			$headers = fgetcsv($handle, 1000, ",");

			while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
				if (!empty($data[0])) {
					$stock_updates[] = array_combine($headers, $data);
				}
			}

			fclose($handle);
		}

		return $stock_updates;
	}
}