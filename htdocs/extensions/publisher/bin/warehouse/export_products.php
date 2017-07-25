<?php
define('DOCROOT', rtrim(dirname(__FILE__)."/../../../.."));

require(DOCROOT . '/symphony/lib/boot/bundle.php');
require_once(CORE . "/class.administration.php");
require_once(TOOLKIT . '/class.sectionmanager.php');
require_once(TOOLKIT . '/class.entrymanager.php');

Class Export_Products extends Extension {

	private $orders = [];

	public function __construct() {

		$pageInstance = Administration::instance();

		try {
			self::printExport();
		}
		catch (Exception $e) {
			echo "ERROR!\n";
			var_dump($e);
			exit;
		}
	}


	private function printExport() {

		$products = [];

		/*
			TODO
			- stock level check
			- subscribers only check
		*/
		$stmt = "SELECT product_name.entry_id AS product_id,
						product_name.value AS product_description,
						product_code.value AS product_code,
						product_price.value AS price,
						f_product_stock_level.value AS product_stock_level,
						f_isbn.value AS isbn,
						f_digital_product.value AS is_digital_product,
						f_pre_order.value AS is_pre_order,
						f_vat_free.value AS is_vat_free,
						f_attributes.unique_id AS attribute_id,
						f_attributes.textfield1 AS attribute1,
						f_attributes.textfield2 AS attribute2,
						f_attributes.textfield3 AS attribute_stock_level,
						f_attributes.textfield4 AS attribute_product_code,
						f_attributes.textfield5 AS attribute_ean,
						f_brand.value AS brand,
						f_weight.value AS product_weight,
						f_width.value AS product_width,
						f_height.value AS product_height,
						f_depth.value AS product_depth,
						f_primary_category.value AS primary_category,
						f_customs_price.value AS cost_price
						FROM sym_entries_data_75 AS product_name
						LEFT JOIN sym_entries_data_1849 AS product_code on product_code.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_761 AS product_price on product_price.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_764 AS f_product_stock_level on f_product_stock_level.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1693 AS f_isbn on f_isbn.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_619 AS f_available_from on f_available_from.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1675 AS f_digital_product on f_digital_product.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1191 AS f_pre_order on f_pre_order.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_964 AS f_vat_free on f_vat_free.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_763 AS f_attributes on f_attributes.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1695 AS f_brand_relation on f_brand_relation.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1694 AS f_brand on f_brand.entry_id = f_brand_relation.relation_id
						LEFT JOIN sym_entries_data_815 AS f_weight on f_weight.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1851 AS f_width on f_width.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1852 AS f_height on f_height.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1853 AS f_depth on f_depth.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_1287 AS f_customs_price on f_customs_price.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_642 AS f_primary_category_relation on f_primary_category_relation.entry_id = product_name.entry_id
						LEFT JOIN sym_entries_data_640 AS f_primary_category on f_primary_category.entry_id = f_primary_category_relation.relation_id
						WHERE f_available_from.date < NOW()
						#AND oh.product_id NOT IN ('shipping','discount','six-month','the-forecast','gift', 'none')
						#AND d.value = 'no'
						#GROUP BY oh.order_id
						ORDER BY product_description";

		$this->query = Symphony::Database()->fetch($stmt);

		$i = 1;

		echo '<table><tbody>'.
					'<tr>'.
						'<th>Counter</th>'.
						'<th>Symphony ID [Internal only]</th>'.
						'<th>Symphony Attribute ID [Internal only]</th>'.
						'<th>Product Code</th>'.
						'<th>Product Description</th>'.
						'<th>Stock Control Group</th>'.
						'<th>VAT Code</th>'.
						'<th>VatExclusivePrice</th>'.
						'<th>VatInclusivePrice</th>'.
						'<th>Supplier Code</th>'.
						'<th>Supplier Name</th>'.
						'<th>Cost (Buying)Price</th>'.
						'<th>Supplier Currency</th>'.
						'<th>Supplier Product Code</th>'.
						'<th>EAN</th>'.
						'<th>StandardCartonQuantity</th>'.
						'<th>Weight</th>'.
						'<th>Height</th>'.
						'<th>Width</th>'.
						'<th>Depth</th>'.
						'<th>Classification Code</th>'.
						'<th>Classification Description</th>'.
						'<th>Brand Code</th>'.
						'<th>Brand Description</th>'.
						'<th>Manufacturer Code</th>'.
						'<th>Manufacturer Description</th>'.
					'</tr>';


		foreach ($this->query as $row)
		{
			// Omit rows without stock
			if (empty($row["attribute1"]) && $row["product_stock_level"] == 0) continue;
			if (!empty($row["attribute1"]) && $row["attribute_stock_level"] == 0) continue;

			// Include attributes
			$row["product_description"] = (!empty($row["attribute1"]) ? $row["product_description"].' - '.$row["attribute1"] : $row["product_description"]);
			$row["product_description"] = (!empty($row["attribute2"]) ? $row["product_description"].' - '.$row["attribute2"] : $row["product_description"]);

			// Preserve original Sym ID
			$row["symphony_id"] = $row["product_id"];

			$row["symphony_option_id"] = (empty($row["attribute1"])) ? null : $row["attribute_id"];

			$row["product_code"] = (empty($row["attribute1"])) ? $row["product_code"] : $row["attribute_product_code"];

			// Brand
			$row["brand"] = (!empty($row["brand"])) ? $row["brand"] : 'Publisher';
			$row["brand_code"] = self::makeBrandCode($row["brand"]);

			// Stock Control
			$row["stock_control_group"] = ($row["is_digital_product"] === 'yes' || $row["is_pre_order"] === 'yes') ? 'NON-STOCK' : 'STOCK';

			// VAT calculation
			$row["vat_exclusive_price"] = ($row["is_vat_free"] === "no") ? $row["price"] * 0.8 : $row["price"];

			// Weight conversion to grams
			$row["product_weight"] = $row["product_weight"] * 1000;

			// Classification
			$row["classification_code"] = self::makeClassificationCode($row["primary_category"]);

			// EAN
			$row["EAN"] = (empty($row["isbn"])) ? $row["product_code"] : $row["isbn"];

			// BG for readability
			$bg = ($i % 2 == 0) ? 'style="background-color: #EEE;"' : '';

			$result = '<tr '.$bg.'>'.
									'<td>'.$i.'</td>'.
									'<td>'.$row["symphony_id"].'</td>'.
									'<td>'.$row["symphony_option_id"].'</td>'.
									'<td>'.$row["product_code"].'</td>'.
									'<td>'.$row["product_description"].'</td>'.
									'<td>'.$row["stock_control_group"].'</td>'.
									'<td>20</td>'.
									'<td>'.number_format($row["vat_exclusive_price"], 2).'</td>'.
									'<td>'.number_format($row["price"], 2).'</td>'.
									'<td>'.$row["brand_code"].'</td>'.
									'<td>'.$row["brand"].'</td>'.
									'<td>'.number_format($row["cost_price"], 2).'</td>'.
									'<td>GBP</td>'.
									'<td></td>'.
									'<td>'.$row["EAN"].'</td>'.
									'<td>0</td>'.
									'<td>'.$row["product_weight"].'</td>'.
									'<td>'.$row["product_height"].'</td>'.
									'<td>'.$row["product_width"].'</td>'.
									'<td>'.$row["product_depth"].'</td>'.
									'<td>'.$row["classification_code"].'</td>'.
									'<td>'.$row["primary_category"].'</td>'.
									'<td>'.$row["brand_code"].'</td>'.
									'<td>'.$row["brand"].'</td>'.
									'<td>'.$row["brand_code"].'</td>'.
									'<td>'.$row["brand"].'</td>'.
								'</tr>';

			echo $result;
			$i++;
		}

		echo '</tbody></table>';


	}

	/*
		Try and make a product SKU dynamically from an array of data
	*/
	private function makeProductSKU($data)
	{
		// Default SKU
		$sku = '';
		$brand = (!empty($data["brand"])) ? substr($data["brand"], 0, 3).'-' : '';

		if ($data["primary_category"] === 'Magazine')
		{
			$sku = 'MON-';
			$sku .= str_replace(' ', '-', $data["product_description"]);

		} else {

			$sku .= $brand;

			$titleAttributeArray = explode(' - ', $data["product_description"]);
			$title = $titleAttributeArray[0];

			$title = str_replace('& ', '', $title);
			$title = str_replace('.', '', $title);
			$termArray = explode(' ', $title);

			// First 3 letters of the core product description
			foreach ($termArray as $term)
			{
				$comp .= substr($term, 0, 3);
			}

			if (count($titleAttributeArray) > 1) {
				$comp .= '-';
				$termArray = explode(' ', $titleAttributeArray[1]);
				foreach ($termArray as $term)
				{
					//$comp .= substr($term, 0, 1);
					$comp .= $term;
				}
			}
			if (count($titleAttributeArray) > 2) {
				$comp = $comp.'-'.$titleAttributeArray[2];
			}

			$sku .= str_replace(' ', '', strtoupper($comp));
			//$sku = (strlen($sku) > 14) ? substr($sku, 0, 14) : $sku;
		}

		$sku = strtoupper($sku);
		//var_dump($sku);

		//exit;


		return $sku;
	}

	private function makeBrandCode($brand)
	{
		$code = '';
		$termArray = explode(' ', $brand);
		foreach ($termArray as $term)
		{
			$code .= substr($term, 0, 3);
		}
		$code = strtoupper($code);
		return $code;
	}

	private function makeClassificationCode($cat)
	{
		$code = '';
		$termArray = explode(' ', $cat);
		$code = strtoupper($termArray[0]);
		return $code;
	}

}

new Export_Products();