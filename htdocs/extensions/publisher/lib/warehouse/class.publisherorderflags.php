<?php

Class PublisherOrderFlags {

	public function __construct() {}

	/**
	 * markExported()
	 * Mark all orders in the present cohort as isExported = 1
	 * @param array $orders
	 */
	public function markExported($orders) {

		$ids_to_update = [];

		foreach ($orders as $order)
		{
			$ids_to_update[] = "'".$order["order_id"]."'";
		}

		$ids_to_update = implode(',', $ids_to_update);

		$sql = "UPDATE `order_history` SET `isExported` = '1' WHERE `order_id` IN ({$ids_to_update})";

		$updated = Symphony::Database()->query($sql);
		$flagged = (mysql_affected_rows() > 0) ? true : false;

		if ($updated && $flagged)
		{
			echo "Marked order ids {$ids_to_update} as exported\n";
		}
	}

	/**
	 * markSent()
	 * Mark all orders in the present cohort as isSent = 1
	 * @param object $orders (in this case $orders is an XML object loaded from the local file system)
	*/
	public function markSent($orders) {

		$ids_to_update = [];

		foreach ($orders as $web_order)
		{
			$ids_to_update[] = "'".$web_order->order->order_id."'";
		}

		$ids_to_update = implode(',', $ids_to_update);

		$sql = "UPDATE `order_history` SET `isSent` = '1' WHERE `order_id` IN ({$ids_to_update})";

		$updated = Symphony::Database()->query($sql);
		$flagged = (mysql_affected_rows() > 0) ? true : false;

		if ($updated && $flagged)
		{
			echo "Marked order ids {$ids_to_update} as sent\n";
		}
	}

	/**
	 * markDispatched()
	 * Mark all orders in the present cohort as isDispatched = 1
	 * @param array $orders
	*/
	public function markDispatched($orders) {

		foreach ($orders as $order)
		{
			$ids_to_update[] = "'".$order["Client Reference"]."'";
		}

		$ids_to_update = implode(',', $ids_to_update);

		$sql = "UPDATE `order_history` SET `isDispatched` = '1' WHERE `order_id` IN ({$ids_to_update})";

		$updated = Symphony::Database()->query($sql);
		$flagged = (mysql_affected_rows() > 0) ? true : false;

		if ($updated && $flagged)
		{
			echo "Marked order ids {$ids_to_update} as dispatched\n";
		}
	}
}