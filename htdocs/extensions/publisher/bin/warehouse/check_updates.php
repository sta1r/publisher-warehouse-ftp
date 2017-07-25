<?php
/*
- This will run on a periodic cron or Queue basis
- Checks for both order status updates AND stock updates
*/
define('DOCROOT', rtrim(dirname(__FILE__)."/../../../.."));

require(DOCROOT . '/symphony/lib/boot/bundle.php');
require_once(CORE . "/class.administration.php");
require_once(EXTENSIONS . '/publisher/lib/class.publisher.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisherorder.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisheralert.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publishercompile.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publisherparse.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publishertransport.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publisherorderflags.php');


Class Check_Updates extends Extension {

	private $local_paths = [];
	private $local_files = [];
	private $orders = [];
	private $stock_updates = [];
	private $manifest = [];
	private $pending = [];
	private $parse, $transport, $dev;

	public function __construct() {

		$pageInstance = Administration::instance();
		$environmentConfig = (object) Symphony::Configuration()->get('environment');
		$this->dev = ($environmentConfig->environment != "production" && $environmentConfig->environment != "staging");

		$this->parse = new publisherParse();
		$this->compile = new PublisherCompile();
		$this->transport = new PublisherTransport();
		$this->flag = new PublisherOrderFlags();


		if ($pageInstance->isLoggedIn()) {

			try
			{
				/* PHASE 1 = ORDER UPDATES */
				$this->getOrderTrackingFiles();
				$orders = $this->parse->parseOrderTrackingFiles();

				if (is_array($orders)) {
					$this->flag->markDispatched($orders);
				} else {
					echo 'No orders listed in CSV';
				}

				$this->transport->moveToFolder($this->transport->getLocalFilePath(), $this->local_files, 'processed/');

				/* PHASE 2 = STOCK UPDATES */
				$this->getStockUpdateFile();
				$this->stock_updates = $this->parse->parseStockUpdateFile();

				$this->pending = $this->compile->getPendingOrders();

				$manifest = $this->getStockManifest();
				$report = $this->runStockLevelMonitor($manifest);

			}
			catch (PublisherExceptionInterface $e)
			{
				PublisherAlert::alert($e);
			}
			catch (Exception $e) {
				if ($this->dev) {
					echo "ERROR!\n";
					var_dump($e);
				} else {
					PublisherAlert::alert($e);
				}
			}
		} else {
			echo "You need to be logged in to run this script.";
		}
	}

	/*
		getOrderTrackingFiles()
		- faster version of getCSV that finds any files matching the current datetime
		- Risks: 1) we miss a day for some reason
		- Mitigation: create either 1) a method which fetches a specific date file, or 2) a method like the original getCSV, i.e. which scans all local order tracking files, and runs an array_diff against all remote files, to check we haven't missed an update. Both of which could be run manually on ad hoc basis.
	*/
	private function getOrderTrackingFiles() {

		// set file path for order updates
		$this->transport->setLocalFilePath(DOCROOT.'/ftp/warehouse/incoming/orders/');

		$timestamp = new Datetime();
		$today = $timestamp->format('Ymd');

		$ftp_connection = $this->transport->initialiseFtp("order-updates");
		$remote_file_list = ftp_nlist($ftp_connection, ".");

		// Scan the remote file list for a filename matching our timestamp
		$todays_update = array_filter($remote_file_list, function($string) use ($today) {
			if (strpos($string, $today) === false) {
				return false;
			}
			return true;
		});

		// $todays_update is an array even if it only contains 1 item (which it should do)
		if (!empty($todays_update)) {

			foreach ($todays_update as $file) {
				$remote_file = $file;
				$local_file = $this->transport->getLocalFilePath().$file;
				$got_file = ftp_get($ftp_connection, $local_file, $remote_file, FTP_ASCII);
				echo "Downloaded $file\n";
			}

		} else {
			echo "No order tracking file found for $today.\n";
		}

		ftp_close($ftp_connection);
	}

	/*
		getStockUpdateFile()
		* Retrieves a single ProductStock.csv file, overwriting whatever we have locally
	*/
	private function getStockUpdateFile() {

		$remote_file = "ProductStock.csv";

		// set file path for stock updates
		$this->transport->setLocalFilePath(DOCROOT.'/ftp/warehouse/incoming/stock/');
		$local_file = $this->transport->getLocalFilePath()."ProductStock.csv";

		$ftp_connection = $this->transport->initialiseFtp("stock-updates");

		$stock_file = ftp_get($ftp_connection, $local_file, $remote_file, FTP_ASCII);
		echo "Downloaded stock update file.\n";

		ftp_close($ftp_connection);
	}

	/*
		getStockManifest()
		* Compiles an array of objects containing stock values for EA and Symphony
	*/
	private function getStockManifest() {

		$manifest = [];

		foreach ($this->stock_updates as $update) {
			$sku = $update["Product Code"];
			$skus[] = '"'.$sku.'"';
			$manifest[$sku] = new stdClass;
			$manifest[$sku]->wh_stock = $update["Available Stock"]; // diff between Available and Physical stock?
		}

		$skus = implode(",", $skus);

		$stmt = "SELECT product_name.entry_id AS product_id,
						product_name.value AS product_description,
						product_code.value AS product_code,
						f_product_stock_level.value AS product_stock_level,
						f_attributes.textfield3 AS attribute_stock_level,
						f_attributes.textfield4 AS attribute_product_code
						FROM sym_entries_data_75 AS product_name
						LEFT JOIN sym_entries_data_1849 AS product_code on product_code.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_764 AS f_product_stock_level on f_product_stock_level.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_763 AS f_attributes on f_attributes.entry_id = product_name.entry_id
						WHERE (product_code.value IN ({$skus})) OR (f_attributes.textfield4 IN ({$skus}))";

		$this->query = Symphony::Database()->fetch($stmt);

		foreach ($this->query as $row)
		{
			$sku = (!empty($row["attribute_product_code"])) ? $row["attribute_product_code"] : $row["product_code"];
			$stock = (!empty($row["attribute_stock_level"])) ? $row["attribute_stock_level"] : $row["product_stock_level"];

			$manifest[$sku]->sym_stock = $stock;
			$manifest[$sku]->sym_id = $row["product_id"];
			$manifest[$sku]->orders_pending = $this->pending[$row["product_id"]]->quantity;
		}

		return $manifest;
	}

	/*
		runStockLevelMonitor()
		* Runs a report to highlight stock level discrepancies
		* Output to Slack
	*/
	private function runStockLevelMonitor($manifest) {

		$count = 0;

		foreach($manifest as $key => $item) {
			if (!isset($item->sym_id)) {

				$count++;
				$output .= "\nWarning:\n";
				$output .= $key." - no matching SKU in Symphony";
				$output .= "\n---";
				continue;

			// If warehouse stock does not match publisher stock (factoring in pending orders)
			} elseif ($item->sym_stock + $item->orders_pending != $item->wh_stock) {

				$count++;
				$output .= "\nWarning:\n";
				$output .= $key." - stock levels do not match\n";
				$output .= "Warehouse stock: ".$item->wh_stock."\n";
				$output .= "Symphony stock: ".$item->sym_stock."\n";
				$output .= "URL: https://stage.publisher.com/symphony/publish/products/edit/".$item->sym_id."/";
				$output .= "\n---";
			}
		}

		if ($count > 0) {
			$report = "\n--------------------------- Found ".$count." stock discrepancies -------------------------";
			$report .= $output;

			// Save report to disc
			$fileName = "stock_level_report_".date("Y-m-d").".log";
			file_put_contents( __DIR__ . "/$fileName", $report);
			$fileUrl = $_SERVER["HTTP_HOST"] . "/extensions/publisher/warehouse/bin/$fileName";

			$text = [
				"fallback" => "Stock Level Monitor - found $count issues. Report available on scripts server.",
				"color" => "warning",
				"title" => "Stock Level Monitor",
				"text" => "`".basename(__FILE__)."` found $count cases.\n<https://$fileUrl|Report>."
			];

		} else {

			// No errors, all is well...
			$text = [
				"fallback" => "Stock Level Monitor found no discrepancies. Symphony and Warehouse stock levels match 100%.",
				"color" => "good",
				"title" => "Stock Level Monitor",
				"text" => "`".basename(__FILE__)."` found no discrepancies. Symphony and Warehouse stock levels match 100%."
			];
		}

		if ($this->dev) {
			echo $report;
		} else {
			PublisherAlert::slack($text);
		}
	}
}

$check = new Check_Updates();