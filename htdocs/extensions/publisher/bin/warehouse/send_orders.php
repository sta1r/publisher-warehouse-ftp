<?php
define('DOCROOT', rtrim(dirname(__FILE__)."/../../../.."));

require(DOCROOT . '/symphony/lib/boot/bundle.php');
require_once(CORE . "/class.administration.php");
require_once(TOOLKIT . '/class.sectionmanager.php');
require_once(TOOLKIT . '/class.entrymanager.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisher.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisherorder.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisheralert.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publishercompile.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publishertransport.php');
require_once(EXTENSIONS . '/publisher/lib/warehouse/class.publisherorderflags.php');

Class Send_Orders extends Extension {

	private $compile, $transport;
	private $orders = [];

	public function __construct() {

		$pageInstance = Administration::instance();
		$environmentConfig = (object) Symphony::Configuration()->get('environment');

		$this->compile = new PublisherCompile();
		$this->transport = new PublisherTransport();
		$this->flag = new PublisherOrderFlags();

		if ($pageInstance->isLoggedIn()) {

			try {

				$this->orders = $this->compile->getOrders(1, 0, 0, 0);
				$this->compile->generateXML($this->orders);

				$this->flag->markExported($this->orders);

				$this->transport->setLocalFilePath(DOCROOT.'/ftp/warehouse/orders/');
				$this->transport->transportXML();

				// mark up and clean up
				$local_paths = $this->transport->getLocalPaths($this->transport->getLocalFilePath(), 'xml');

				if (is_array($local_paths)) {

					foreach ($local_paths as $path) {
						$xml = simplexml_load_file($path);
						$orders = $xml->web_order;
						$this->flag->markSent($orders);
					}

					$local_files = $this->transport->getFileNames($local_paths);
					$this->transport->moveToFolder($this->transport->getLocalFilePath(), $local_files, 'sent/');

				} else {
					echo 'No files found';
				}
			}
			catch (PublisherExceptionInterface $e)
			{
				PublisherAlert::alert($e);
			}
			catch (Exception $e) {
				if ($environmentConfig->environment != "production" && $environmentConfig->environment != "staging") {
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
}

$send = new Send_Orders();