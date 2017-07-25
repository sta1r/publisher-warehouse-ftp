<?php
namespace Shipping;

interface ServiceAdapterInterface {

	public function createShipment($shipmentArray);

	public function getShipment();
	public function setShipment($shipment);
	public function getShipmentId();
	public function setShipmentId($shipmentId);

	public function buyRate();

}
