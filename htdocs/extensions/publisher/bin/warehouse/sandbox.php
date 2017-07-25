<?php
define('DOCROOT', rtrim(dirname(__FILE__)."/../../../.."));

require(DOCROOT . '/symphony/lib/boot/bundle.php');
require_once(CORE . "/class.administration.php");
require_once(TOOLKIT . '/class.sectionmanager.php');
require_once(TOOLKIT . '/class.entrymanager.php');
require_once(EXTENSIONS . '/publisher/lib/class.publisher.php');

// ShippingService includes
require_once(EXTENSIONS . '/publisher/lib/class.publishershipment.php');
require_once(EXTENSIONS . '/publisher/lib/Shipping/Shipping.php');

use \Shipping\WarehouseShippingServiceAdapter as ShippingService;


Class Warehouse_Sandbox extends Extension {

	private $shippingService = false;
	private $rates = [];

	public function __construct() {

		$pageInstance = Administration::instance();

		$this->shippingService = new ShippingService();

		$shipmentArray = array(
			'to_address'   => array(
				'name'    => 'Mr McMiaow',
				'street1' => '58 Cat Crescent',
				'street2' => '',
				'city'    => 'London',
				'zip'     => 'CAT 8DY',
				'country' => 'GB',
				'phone'   => '+12345678'
			),
			'from_address' => array(
				'company' => 'Publisher (Web store)',
				'street1' => 'Publisher House,',
				'street2' => '1 Smith Street',
				'city'    => 'London',
				'country' => 'GB',
				'zip'     => 'W1D 0BJ',
				'phone'   => '+12345678'
			),
			'parcel'  => array(
				'weight'	=> 1306 //convert weight from kg to oz
			),
			'options' => array(
				'invoice_number' => 'MON-12345'
			)
		);


		$shipment = $this->shippingService->createShipment($shipmentArray);
		//var_dump($shipment); exit;

		$rates = $this->shippingService->getRates($shipment, true);

		var_dump($rates);

		$rateId = 'standard';
		$this->shippingService->setRate($rates->options[$rateId]);

		$my_rate = $this->shippingService->getRate();
		var_dump($my_rate);
	}
}

new Sandbox();