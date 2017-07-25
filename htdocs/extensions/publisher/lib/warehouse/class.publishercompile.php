<?php

Class PublisherCompile {

	private $orders = [];

	public function __construct() {}

	/*
		NOTES
		- digital product lines are excluded in the core order retrieval query
		- if digital product lines are included in a cart that also contains shippable physical orders, the physical line items are processed as normal and the digital line items have a flag included in the <meta>
		- getOrders - should probably NOT return ALL orders, ever
	*/

	/**
	 * getOrders()
	 * Reuseable method to return sets of orders that are any combination of approved, exported, sent or dispatched. If all params are null, all orders are returned.
	 * @param bool $approved
	 * @param bool $exported
	 * @param bool $sent
	 * @param bool $dispatched
	 * @return array $orders
	*/
	public function getOrders($approved = null, $exported = null, $sent = null, $dispatched = null) {

		$stmt = "SELECT oh.order_id FROM order_history AS oh
						LEFT JOIN sym_entries_data_1675 AS f_digital_product on f_digital_product.entry_id = oh.product_id
						WHERE oh.date_added > '2017-03-26 00:00:00' # this will need to be the date of the transition to EA processing
						AND oh.product_id NOT IN ('shipping','discount','six-month','the-forecast','gift', 'none')
						AND oh.fulfilment_status = 'New order'
						AND f_digital_product.value = 'no'";

		// filter by order flag
		if (!empty($approved)) $stmt .= " AND oh.isApproved = {$approved}";
		if (!empty($exported)) $stmt .= " AND oh.isExported = {$exported}";
		if (!empty($sent)) $stmt .= " AND oh.isSent = {$sent}";
		if (!empty($dispatched)) $stmt .= " AND oh.isDispatched = {$dispatched}";

		$stmt .= " GROUP BY oh.order_id ORDER BY oh.date_added DESC LIMIT 10";

		$results = Symphony::Database()->fetch($stmt);

		if (count($results) > 0)
		{
			foreach ($results as $row)
			{
				array_push($this->orders, $row);
			}
			return $this->orders;
		}
		else
		{
			$msg = "No orders found in ".__METHOD__.".";
			echo $msg;
			throw new PublisherException($msg);
		}
	}

	/**
	 * getPendingOrders()
	 * Retrieves any orders which have not yet been dispatched
	 * @return array $pending (outputs an array of product IDs with total quantities)
	*/
	public function getPendingOrders() {

		$pending = [];

		// Orders where $dispatched = 0
		$this->orders = $this->getOrders(null, null, null, 0);

		foreach ($this->orders as $o)
		{
			$order = new PublisherOrder;
			$order->getById($o['order_id']);

			foreach ($order->getItems() as $item)
			{
				$key = $item->getProductId();
				if (!in_array($key, array('shipping', 'discount')))
				{
					if (empty($pending[$key]))
					{
						$pending[$key] = new stdClass;
						$pending[$key]->quantity = (int) $item->getQuantity();
					}
					else
					{
						$pending[$key]->quantity += $item->getQuantity();
					}
				}
			}
		}

		return $pending;
	}

	/**
	 * processOrderData()
	 * @param string $orderId
	 * @return array $data (returns full dataset for an order ID)
	*/
	public function processOrderData($orderId) {

		$data = $line_item = [];

		// get order object and hydrate
		$order = new PublisherOrder;
		$order->getById($orderId);
		//var_dump($order); exit;

		$billing_address = $order->getBillingAddress();
		$delivery_address = $order->getDeliveryAddress();

		$data["order_id"] = $order->getOrderId();
		$data["braintree_transaction_id"] = $order->getBraintreeTransactionId();
		$data["date_added"] = $order->getDate();
		// $data["fulfilment_status"] = $this->query[0]["fulfilment_status"];
		$data["email"] = $order->getEmail();
		$data["payment_method"] = $order->getPaymentMethod();
		$data["billing_fullname"] = $order->getBillingName()->billing_forenames.' '.$order->getBillingName()->billing_surname;
		$data["billing_forenames"] = $order->getBillingName()->billing_forenames;
		$data["billing_surname"] = $order->getBillingName()->billing_surname;
		$data["billing_address_1"] = $billing_address->billing_address_line1;
		$data["billing_address_2"] = $billing_address->billing_address_line2;
		$data["billing_address_3"] = $billing_address->billing_address_line3;
		$data["billing_city"] = $billing_address->billing_city;
		$data["billing_county"] = $billing_address->billing_county;
		$data["billing_postcode"] = $billing_address->billing_postcode;
		$data["billing_country"] = $billing_address->billing_country;
		$data["billing_phone"] = $order->getBillingPhone();
		$data["delivery_fullname"] = $order->getDeliveryName()->delivery_forenames.' '.$order->getDeliveryName()->delivery_surname;
		$data["delivery_forenames"] = $order->getDeliveryName()->delivery_forenames;
		$data["delivery_surname"] = $order->getDeliveryName()->delivery_surname;
		$data["delivery_address_1"] = $delivery_address->delivery_address_line1;
		$data["delivery_address_2"] = $delivery_address->delivery_address_line2;
		$data["delivery_address_3"] = $delivery_address->delivery_address_line3;
		$data["delivery_city"] = $delivery_address->delivery_city;
		$data["delivery_county"] = $delivery_address->delivery_county;
		$data["delivery_postcode"] = $delivery_address->delivery_postcode;
		$data["delivery_country"] = $delivery_address->delivery_country;
		$data["delivery_phone"] = $order->getDeliveryPhone();

		$data["line_items"] = [];

		foreach ($order->getItems() as $item)
		{
			if (!in_array($item->getProductId(), array('shipping', 'discount')))
			{
				// values from Order object
				$line_item["option_id"] = $item->getOptionId();
				$line_item["quantity"] = $item->getQuantity();
				$line_item["line_total"] = number_format($item->getUnitPrice(), 2);
				$line_item["price"] = number_format($item->getUnitPrice() / $item->getQuantity(), 2);

				// values from lookupProduct
				$product = $this->lookupProduct($item->getProductId());

				$line_item["product_id"] = $item->getProductId();
				$line_item["title"] = $product->title;
				//if (!$product->isShippable) $line_item["meta"] = 'Do not ship';

				// values from lookupAttribute
				$attribute = $this->lookupAttribute($line_item["option_id"]);

				$line_item["sku"] = (empty($line_item["option_id"])) ? $product->sku : $attribute->sku;
				$line_item["primary_attribute"] = (empty($attribute)) ? '' : $attribute->primary;
				$line_item["secondary_attribute"] = (empty($attribute)) ? '' : $attribute->secondary;

				// the barcode is optional - see email 22/5/17
				if (!empty($line_item["option_id"]) && !empty($attribute->ean)) {
					$line_item["barcode"] = $attribute->ean;
				} elseif (!empty($product->isbn)) {
					$line_item["barcode"] = $product->isbn;
				} else {
					$line_item["barcode"] = '';
				}

				// add each product line item to the order
				array_push($data["line_items"], $line_item);

				// sum the cart total
				$data["cart_total"] += $item->getUnitPrice();
			}

			if (!isset($data["shipping"]) && $item->getProductId() === 'shipping')
			{
				$data["shipping"] = number_format($item->getUnitPrice(), 2);
			}

			if (!isset($data["discount"]) && $item->getProductId() === 'discount')
			{
				$data["discount"] = number_format($item->getUnitPrice() * -1, 2);
			}
		}

		$data["order_total"] = number_format($data["cart_total"] + $data["shipping"] + $data["discount"], 2);

		return $data;
	}

	/**
	 * parseXML()
	 * @param array $data (the data we're parsing)
	 * @param object $doc (the DOMDocument object we're populating)
	 * @return object $web_order (populated DOMDocument object)
	*/
	public function parseXML($data, $doc) {

		$web_order = $doc->createElement('web_order');
		$order = $doc->createElement('order');
		$web_order->appendChild($order);

		$order->appendChild($doc->createElement('order_state', 'Payment Received'));
		$order->appendChild($doc->createElement('order_date', $data["date_added"]));
		$order->appendChild($doc->createElement('dispatch_date'));
		$order->appendChild($doc->createElement('product_total_inc', '?'));
		$order->appendChild($doc->createElement('product_total_ex', '?'));
		$order->appendChild($doc->createElement('shipping_total_inc', '?'));
		$order->appendChild($doc->createElement('shipping_total_ex', '?'));
		$order->appendChild($doc->createElement('shipping_vat', '?'));
		$order->appendChild($doc->createElement('grand_total_ex', '?'));
		$order->appendChild($doc->createElement('grand_total_inc', $data["order_total"]));
		$order->appendChild($doc->createElement('grand_total_vat', '?'));
		$order->appendChild($doc->createElement('discount_ex', '?'));
		$order->appendChild($doc->createElement('discount_inc', '?'));
		$order->appendChild($doc->createElement('discount_vat', '?'));
		$order->appendChild($doc->createElement('order_currency', 'GBP'));
		$order->appendChild($doc->createElement('order_id', $data["order_id"]));
		$order->appendChild($doc->createElement('courier_id'));
		$order->appendChild($doc->createElement('order_reference'));
		$order->appendChild($doc->createElement('order_customer_comments'));
		$order->appendChild($doc->createElement('order_notes'));
		$order->appendChild($doc->createElement('order_type', 'WEB'));
		$order->appendChild($doc->createElement('courier_name', 'Dispatched next business day (UPS)'));
		$order->appendChild($doc->createElement('inv_priority'));
		$order->appendChild($doc->createElement('order_offer_codes_csv'));

		$customer = $doc->createElement('customer');
		$web_order->appendChild($customer);

		$customer->appendChild($doc->createElement('customer_id', '<![CDATA['.$data["email"].']]>'));
		$customer->appendChild($doc->createElement('email_address', '<![CDATA['.$data["email"].']]>'));
		$customer->appendChild($doc->createElement('billing_title'));
		$customer->appendChild($doc->createElement('billing_company_name'));
		$customer->appendChild($doc->createElement('billing_firstname', '<![CDATA['.$data["billing_forenames"].']]>'));
		$customer->appendChild($doc->createElement('billing_lastname', '<![CDATA['.$data["billing_surname"].']]>'));
		$customer->appendChild($doc->createElement('billing_fullname', '<![CDATA['.$data["billing_fullname"].']]>'));
		$customer->appendChild($doc->createElement('billing_address1', '<![CDATA['.$data["billing_address_1"].']]>'));
		$customer->appendChild($doc->createElement('billing_address2', '<![CDATA['.$data["billing_address_2"].']]>'));
		$customer->appendChild($doc->createElement('billing_town', '<![CDATA['.$data["billing_address_3"].']]>'));
		$customer->appendChild($doc->createElement('billing_city', '<![CDATA['.$data["billing_city"].']]>'));
		$customer->appendChild($doc->createElement('billing_county', '<![CDATA['.$data["billing_county"].']]>'));
		$customer->appendChild($doc->createElement('billing_country', '<![CDATA['.$data["billing_country"].']]>'));
		$customer->appendChild($doc->createElement('billing_country_name', '<![CDATA['.Publisher::countryCodeToCountry($data["billing_country"]).']]>'));
		$customer->appendChild($doc->createElement('billing_postcode', '<![CDATA['.$data["billing_postcode"].']]>'));
		$customer->appendChild($doc->createElement('billing_telephone', '<![CDATA['.$data["billing_phone"].']]>'));
		$customer->appendChild($doc->createElement('billing_mobile'));
		$customer->appendChild($doc->createElement('billing_email', '<![CDATA['.$data["email"].']]>'));
		$customer->appendChild($doc->createElement('delivery_title'));
		$customer->appendChild($doc->createElement('delivery_company_name'));
		$customer->appendChild($doc->createElement('delivery_firstname', '<![CDATA['.$data["delivery_forenames"].']]>'));
		$customer->appendChild($doc->createElement('delivery_lastname', '<![CDATA['.$data["delivery_surname"].']]>'));
		$customer->appendChild($doc->createElement('delivery_fullname', '<![CDATA['.$data["delivery_fullname"].']]>'));
		$customer->appendChild($doc->createElement('delivery_address1', '<![CDATA['.$data["delivery_address_1"].']]>'));
		$customer->appendChild($doc->createElement('delivery_address2', '<![CDATA['.$data["delivery_address_2"].']]>'));
		$customer->appendChild($doc->createElement('delivery_town', '<![CDATA['.$data["delivery_address_3"].']]>'));
		$customer->appendChild($doc->createElement('delivery_city', '<![CDATA['.$data["delivery_city"].']]>'));
		$customer->appendChild($doc->createElement('delivery_county', '<![CDATA['.$data["delivery_county"].']]>'));
		$customer->appendChild($doc->createElement('delivery_country', '<![CDATA['.$data["delivery_country"].']]>'));
		$customer->appendChild($doc->createElement('delivery_country_name', '<![CDATA['.Publisher::countryCodeToCountry($data["delivery_country"]).']]>'));
		$customer->appendChild($doc->createElement('delivery_postcode', '<![CDATA['.$data["delivery_postcode"].']]>'));
		$customer->appendChild($doc->createElement('delivery_telephone', '<![CDATA['.$data["delivery_phone"].']]>'));
		$customer->appendChild($doc->createElement('email_opt_in'));
		$customer->appendChild($doc->createElement('post_opt_in'));
		$customer->appendChild($doc->createElement('heard_about'));

		$payment = $doc->createElement('payment');
		$web_order->appendChild($payment);

		$payment->appendChild($doc->createElement('payment_amount', $data["order_total"]));
		$payment->appendChild($doc->createElement('payment_type', $data["payment_method"]));
		$payment->appendChild($doc->createElement('card_holder'));
		$payment->appendChild($doc->createElement('auth_code'));
		$payment->appendChild($doc->createElement('transaction_reference', $data["braintree_transaction_id"]));
		$payment->appendChild($doc->createElement('security_reference'));
		$payment->appendChild($doc->createElement('cv2_avs'));
		$payment->appendChild($doc->createElement('notes'));

		$products = $doc->createElement('products');
		$web_order->appendChild($products);

		foreach ($data["line_items"] as $line_item)
			{
				$product = $doc->createElement('product');
				$products->appendChild($product);

				$product->appendChild($doc->createElement('line', $line_item["product_id"]));
				$product->appendChild($doc->createElement('model'));
				$product->appendChild($doc->createElement('reference', $line_item["sku"]));
				$product->appendChild($doc->createElement('child_product_reference'));
				$product->appendChild($doc->createElement('parent_product_reference'));
				$product->appendChild($doc->createElement('barcode', $line_item["barcode"]));
				$product->appendChild($doc->createElement('title', '<![CDATA['.htmlspecialchars($line_item["title"]).']]>'));
				$product->appendChild($doc->createElement('summary'));
				$product->appendChild($doc->createElement('quantity', $line_item["quantity"]));
				$product->appendChild($doc->createElement('price_inc', $line_item["line_total"]));
				$product->appendChild($doc->createElement('price_ex'));
				$product->appendChild($doc->createElement('price_vat'));
				$product->appendChild($doc->createElement('tax_rate', '20.000'));
				$product->appendChild($doc->createElement('manufacturer_name'));
				if (!empty($line_item["primary_attribute"])) {
					$product->appendChild($doc->createElement('attribute_summary', htmlspecialchars($line_item["primary_attribute"]).' '.htmlspecialchars($line_item["secondary_attribute"])));
				} else {
					$product->appendChild($doc->createElement('attribute_summary'));
				}

				$product->appendChild($doc->createElement('personalisation'));
				$product->appendChild($doc->createElement('weight'));
			}

		return $web_order;
	}

	/**
	 * lookupProduct()
	 * @param string $product_id
	 * @return object $p
	*/
	public function lookupProduct($product_id) {

		$p = new stdClass();

		$sectionID__products	= SectionManager::fetchIDFromHandle('products');
		$fieldID__productName = FieldManager::fetchFieldIDFromElementName('product-name', $sectionID__products);
		$fieldID__digitalProduct = FieldManager::fetchFieldIDFromElementName('digital-product', $sectionID__products);
		$fieldID__preOrder = FieldManager::fetchFieldIDFromElementName('pre-order', $sectionID__products);
		$fieldID__productSku = FieldManager::fetchFieldIDFromElementName('product-sku', $sectionID__products);
		$fieldID__isbn = FieldManager::fetchFieldIDFromElementName('isbn', $sectionID__products);

		$entryObj = EntryManager::fetch($product_id);

		$isDigital = $entryObj[0]->getData($fieldID__digitalProduct)["value"];
		$isPreOrder = $entryObj[0]->getData($fieldID__preOrder)["value"];

		$p->isShippable = ($isDigital === 'yes' || $isPreOrder === 'yes') ? 0 : 1;
		$p->title = $entryObj[0]->getData($fieldID__productName)["value"];
		$p->sku = $entryObj[0]->getData($fieldID__productSku)["value"];
		$p->isbn = $entryObj[0]->getData($fieldID__isbn)["value"];

		return $p;
	}

	/**
	 * lookupAttribute()
	 * Straight SQL query as opposed to FieldManager / EntryManager (seemingly tricky to getData for a specific option_id using those methods)
	 * @param string $option_id
	 * @return object $a
	*/
	public function lookupAttribute($option_id) {

		$a = new stdClass();

		$stmt = "SELECT * FROM sym_entries_data_763
						WHERE unique_id = '$option_id'";

		$results = Symphony::Database()->fetch($stmt);

		if (count($results) > 0)
		{
			foreach ($results as $row)
			{
				$a->primary = $row["textfield1"];
				$a->secondary = $row["textfield2"];
				$a->sku = $row["textfield4"];
				$a->ean = $row["textfield5"];
			}
		}

		return $a;
	}

	/**
	 * printXML() - for testing
	*/
	public function printXML() {

		header('Content-Type: application/xml');
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n". '<web_orders>';

		foreach ($this->orders as $order)
		{
			$data = $this->processOrderData($order["order_id"]);
			$xml = '<web_order>'.
				'<order>'.
					'<order_state>Payment Received</order_state>'.
					'<order_date>'.$data["date_added"].'</order_date>'.
					'<dispatch_date></dispatch_date>'.
					'<product_total_inc>?</product_total_inc>'.
					'<product_total_ex>?</product_total_ex>'.
					'<shipping_total_inc>?</shipping_total_inc>'.
					'<shipping_total_ex>?</shipping_total_ex>'.
					'<shipping_vat>?</shipping_vat>'.
					'<grand_total_ex>?</grand_total_ex>'.
					'<grand_total_inc>'.$data["order_total"].'</grand_total_inc>'.
					'<grand_total_vat>?</grand_total_vat>'.
					'<discount_ex>?</discount_ex>'.
					'<discount_inc>?</discount_inc>'.
					'<discount_vat>?</discount_vat>'.
					'<order_currency>GBP</order_currency>'.
					'<order_id>'.$data["order_id"].'</order_id>'.
					'<courier_id></courier_id>'.
					'<order_reference/>'.
					'<order_customer_comments/>'.
					'<order_notes/>'.
					'<order_type>WEB</order_type>'.
					'<courier_name>Dispatched next business day (UPS)</courier_name>'.
					'<inv_priority/>'.
					'<order_offer_codes_csv/>'.
				'</order>'.
				'<customer>'.
					'<customer_id><![CDATA['.$data["email"].']]></customer_id>'.
					'<email_address><![CDATA['.$data["email"].']]></email_address>'.
					'<billing_title/>'.
					'<billing_company_name/>'.
					'<billing_firstname><![CDATA['.$data["billing_forenames"].']]></billing_firstname>'.
					'<billing_lastname><![CDATA['.$data["billing_surname"].']]></billing_lastname>'.
					'<billing_fullname><![CDATA['.$data["billing_fullname"].']]></billing_fullname>'.
					'<billing_address1><![CDATA['.$data["billing_address_1"].']]></billing_address1>'.
					'<billing_address2><![CDATA['.$data["billing_address_2"].']]></billing_address2>'.
					'<billing_town><![CDATA['.$data["billing_address_3"].']]></billing_town>'.
					'<billing_city><![CDATA['.$data["billing_city"].']]></billing_city>'.
					'<billing_county><![CDATA['.$data["billing_county"].']]></billing_county>'.
					'<billing_country><![CDATA['.$data["billing_country"].']]></billing_country>'.
					'<billing_country_name><![CDATA['.Publisher::countryCodeToCountry($data["billing_country"]).']]></billing_country_name>'.
					'<billing_postcode><![CDATA['.$data["billing_postcode"].']]></billing_postcode>'.
					'<billing_telephone>'.$data["billing_phone"].'</billing_telephone>'.
					'<billing_mobile/>'.
					'<billing_email><![CDATA['.$data["email"].']]></billing_email>'.
					'<delivery_title/>'.
					'<delivery_company_name/>'.
					'<delivery_firstname><![CDATA['.$data["delivery_forenames"].']]></delivery_firstname>'.
					'<delivery_lastname><![CDATA['.$data["delivery_surname"].']]></delivery_lastname>'.
					'<delivery_fullname><![CDATA['.$data["delivery_fullname"].']]></delivery_fullname>'.
					'<delivery_address1><![CDATA['.$data["delivery_address_1"].']]></delivery_address1>'.
					'<delivery_address2><![CDATA['.$data["delivery_address_2"].']]></delivery_address2>'.
					'<delivery_town><![CDATA['.$data["delivery_address_3"].']]></delivery_town>'.
					'<delivery_city><![CDATA['.$data["delivery_city"].']]></delivery_city>'.
					'<delivery_county><![CDATA['.$data["delivery_county"].']]></delivery_county>'.
					'<delivery_country><![CDATA['.$data["delivery_country"].']]></delivery_country>'.
					'<delivery_country_name><![CDATA['.Publisher::countryCodeToCountry($data["delivery_country"]).']]></delivery_country_name>'.
					'<delivery_postcode><![CDATA['.$data["delivery_postcode"].']]></delivery_postcode>'.
					'<delivery_telephone>'.$data["delivery_phone"].'</delivery_telephone>'.
					'<email_opt_in/>'.
					'<post_opt_in/>'.
					'<heard_about/>'.
				'</customer>'.
				'<payment>'.
					'<payment_amount>'.$data["order_total"].'</payment_amount>'.
					'<payment_type>'.$data["payment_method"].'</payment_type>'.
					'<card_holder/>'.
					'<auth_code/>'.
					'<transaction_reference>'.$data["braintree_transaction_id"].'</transaction_reference>'.
					'<security_reference/>'.
					'<cv2_avs/>'.
					'<notes/>'.
				'</payment>'.
				'<products>';

			foreach ($data["line_items"] as $line_item)
			{
			$xml .= '<product>'.
					'<line/>'.
					'<model/>'.
					'<reference>'.$line_item["sku"].'</reference>'.
					'<child_product_reference/>'.
					'<parent_product_reference/>'.
					'<barcode>'.$line_item["barcode"].'</barcode>'.
					'<title>'.$line_item["title"].'</title>'.
					'<summary/>'.
					'<quantity>'.$line_item["quantity"].'</quantity>'.
					'<price_inc>'.$line_item["price"].'</price_inc>'.
					'<price_ex/>'.
					'<price_vat/>'.
					'<tax_rate>20.000</tax_rate>'.
					'<manufacturer_name/>'.
					'<attribute_summary>'.$line_item["primary_attribute"].' '.$line_item["secondary_attribute"].'</attribute_summary>'.
					'<personalisation/>'.
					'<weight/>'.
				'</product>';
			}

			$xml.= '</products>'.
				'<amazon_order_id/>'.
				'<amazon_fulfillment_method/>';

			$xml .=	'</web_order>';

			echo $xml;
		}

		echo '</web_orders>';
	}

	/**
	 * generateXML()
	 * Generate orders XML and save to the filesystem
	 * @param array $orders
	*/
	public function generateXML($orders) {

		$doc = new DOMDocument('1.0');
		$doc->formatOutput = true;

		$root = $doc->createElement('web_orders');
		$doc->appendChild($root);

		foreach ($orders as $o)
		{
			$data = $this->processOrderData($o["order_id"]);
			//var_dump($data); exit;
			$order_xml = $this->parseXML($data, $doc);
			$root->appendChild($order_xml);
		}

		$doc->appendChild($root);

		$timestamp = new Datetime();
		$file_name = 'orders-'.$timestamp->format('Y-m-d-H:i:s').'.xml';
		$file_size = $doc->save(DOCROOT."/ftp/warehouse/orders/".$file_name);

		if ($file_size !== false) {
			echo "Wrote: " . $file_name . " - " . $file_size . " bytes\n";
		} else {
			$msg = "Could not write to local file system in ".__METHOD__.".";
			echo $msg;
			throw new PublisherException($msg);
		}
	}

}