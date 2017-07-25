<?php
namespace Shipping;

define('DOCROOT', rtrim(dirname(__FILE__)."/../../../.."));
require(DOCROOT . '/symphony/lib/boot/bundle.php');

require_once(CORE . "/class.administration.php");
require_once(TOOLKIT . '/class.sectionmanager.php');
require_once(TOOLKIT . '/class.entrymanager.php');

require_once(EXTENSIONS . '/publisher/lib/class.publisher.php');

use \Symphony, \Administration, \SectionManager, \FieldManager, \Publisher;

Class WarehouseShippingServiceAdapter implements ServiceAdapterInterface {

	private $shipmentId = false;
	private $shipment = false;
	private $zone = "1";
	private $logFile = false;
	private $selectedRate = [];
	public $_error = false;
	public $_msg = null;

	public function __construct() {
		$pageInstance = Administration::instance();
		$this->logFile = __DIR__ . "/../../logs/warehouseShippingLog.log";
	}

	public function createShipment($shipmentArray){
		$response = json_decode(json_encode($shipmentArray));

		$this->setZone($response->to_address->country);
		$response->zone = $this->getZone();

		// add Order ID?

		return $response;
	}

	public function getShipment() {
		return $this->shipment;
	}
	public function setShipment($shipment) {
		$this->shipment = $shipment;
	}

	/*
	 * Zones
	 * With EA, all countries belong to a zone between 1 and 10
	*/
	public function getZone(){
		return $this->zone;
	}
	public function setZone($country){

		$sectionID__shippingCountries = SectionManager::fetchIDFromHandle('shipping-countries');

		$fieldID__countryCode = FieldManager::fetchFieldIDFromElementName('country-code', $sectionID__shippingCountries);
		$fieldID__shippingZone = FieldManager::fetchFieldIDFromElementName('shipping-zone', $sectionID__shippingCountries);

		$sql = "SELECT zone.value AS zone
						FROM sym_entries_data_{$fieldID__shippingZone} AS zone
						JOIN sym_entries_data_{$fieldID__countryCode} AS country on country.entry_id=zone.entry_id
						WHERE country.value = '{$country}'";

		$this->zone = Symphony::Database()->fetchVar('zone', 0, $sql);

	}

	/*
	 * Fetch a set of rates for the supplied shipment
	*/
	public function getRates($shipment, $freeShipping = false) {

		$rates = new \stdClass;

		$sectionID__exactAbacusRates = SectionManager::fetchIDFromHandle('warehouse-rates');
		$sectionID__shippingTiers = SectionManager::fetchIDFromHandle('shipping-tiers');
		$sectionID__shippingDeliveryEstimates = SectionManager::fetchIDFromHandle('shipping-delivery-estimates');

		$fieldID__shippingZone = FieldManager::fetchFieldIDFromElementName('shipping-zone', $sectionID__exactAbacusRates);
		$fieldID__shippingTier = FieldManager::fetchFieldIDFromElementName('shipping-tier', $sectionID__exactAbacusRates);
		$fieldID__maxWeight = FieldManager::fetchFieldIDFromElementName('max-weight', $sectionID__exactAbacusRates);
		$fieldID__shippingPrice = FieldManager::fetchFieldIDFromElementName('shipping-price', $sectionID__exactAbacusRates);
		$fieldID__deliveryEstimate = FieldManager::fetchFieldIDFromElementName('delivery-estimate', $sectionID__exactAbacusRates);

		$fieldID__tierName = FieldManager::fetchFieldIDFromElementName('tier-name', $sectionID__shippingTiers);
		$fieldID__text = FieldManager::fetchFieldIDFromElementName('text', $sectionID__shippingDeliveryEstimates);


		// Returns a list of available rates for the supplied weight and zone, one per tier
		$sql = "SELECT tier.value AS tier, weight.value AS weight, price.value AS price, delivery.value AS delivery
						FROM sym_entries_data_{$fieldID__shippingPrice} AS price
						JOIN sym_entries_data_{$fieldID__shippingZone} AS zone ON zone.entry_id = price.entry_id
						JOIN sym_entries_data_{$fieldID__maxWeight} AS weight ON weight.entry_id = price.entry_id
						JOIN sym_entries_data_{$fieldID__shippingTier} AS tier_relation ON tier_relation.entry_id = price.entry_id
						JOIN sym_entries_data_{$fieldID__tierName} AS tier ON tier.entry_id = tier_relation.relation_id
						JOIN sym_entries_data_{$fieldID__deliveryEstimate} AS delivery_relation ON delivery_relation.entry_id = price.entry_id
						JOIN sym_entries_data_{$fieldID__text} AS delivery ON delivery.entry_id = delivery_relation.relation_id
						WHERE zone.value = '{$shipment->zone}'
						AND {$shipment->parcel->weight} <= weight.value
						GROUP BY tier.value
						ORDER BY CAST(price.value AS UNSIGNED) ASC";

		$results = Symphony::Database()->fetch($sql);

		if (count($results) > 0)
		{
			$rates->options = array();

			foreach ($results as $rate) {
				$tier = $rate["tier"];
				$weight = $rate["weight"];
				$price = $rate["price"];
				$delivery = $rate["delivery"];

				$rates->options[strtolower($tier)] = array(
					'tier' => $tier,
					'weight' => $weight,
					'price' => $price,
					'delivery' => $delivery
				);
			}

			$rates->lowest_rate = array(strtolower(reset($results[0])) => $results[0]);

			// For free shipping, return a 'free' tier in the $rates options, and update the lowest_rate
			if ($freeShipping) {

				$freeShippingTier = array('free' => array(
					'tier' => 'Free Shipping',
					'price' => 0.00,
					'delivery' => $rates->lowest_rate['delivery']
				));

				$rates->lowest_rate = $freeShippingTier;

				$freeOption = $freeShippingTier;
				$rates->options = $freeOption + $rates->options;
			}

		} else
		{
			$this->_error = true;
			$this->_msg = "No rates found for the supplied shipment";
		}

		return $rates;
	}

	public function getRate() {
		return $this->selectedRate;
	}
	public function setRate($rate) {
		$this->selectedRate = $rate;
	}


	// getTiers


	public function getShipmentId() {
		return $this->shipmentId;
	}
	public function setShipmentId($shipmentId) {
		$this->shipmentId = $shipmentId;
	}


	public function buyRate() {
		return $response;
	}

	public function logVar($var, $function) {
		// Vars
		$varJson = json_encode(json_decode(json_encode($var)));
		$timeStamp = date("Y-m-d H:i:s");

		// Open and write file
		$logFile = fopen($this->logFile, "a");
		fwrite($logFile, "\n". $timeStamp . " : " . $function . " : " . $varJson . "\nSHIPMENT : " . json_encode($this) . "\n---\n");
		fclose($logFile);
	}

}
