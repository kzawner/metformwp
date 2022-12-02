<?php

namespace MetForm\Core\Integrations;

use WP_Error;

defined('ABSPATH') || exit;

class KeyCRM
{
	const URL = 'https://openapi.keycrm.app/v1';
	private $_settings;

	/**
		* @return array<mixed,mixed>
		*/
	public function _fetchProduct($sku) {
		$response = $this->_request('offers', 'GET',
			array(
				'include' => 'product',
				'sku'=> $sku,
				'filter' => '{"sku": "' . $sku . '"}',
			),
		);
		if (count($response->data) != 1)  {
			error_log('Product not found: count($response->data) != 1');
			return FALSE;
		}
		$product = $response->data[0]->product;
		$r = array(
      "sku" => $sku,
      "price" => $product->max_price,
      "name" => $product->name,
      "picture" => $product->thumbnail_url,
		);
		return $r;
	}

	function _request($path, $method, $query=[], $data=NULL, &$error_message=NULL) {
		$auth = [
			'api_key' => $this->_settings['mf_keycrm_api_key']
		];

		$body = [
				'method' => $method,
				'data_format' => 'body',
				'timeout' => 45,
				'blocking'    => true,
				'headers' => [
					'Accept' => 'application/json',
					'Authorization' => 'Bearer ' . $auth['api_key'],
					'Content-Type' => 'application/json',
				],
				'body' => json_encode($data),
				'query' => $query,
			];
		$response = wp_remote_request(self::URL . '/' . $path
			. ($query ? '?' . http_build_query($query) : ''),
			$body);

		error_log('Response: ' . json_encode($response));

		$error_message = null;
		if (is_wp_error($response)) {
			$error_message = "Error occured when posting data to KeyCRM: "
					. esc_html($response->get_error_message()) . ", error code: " . $response->get_error_code();

		// Happens when API returns internal server error, response code in the
		// body is 500, though no HTTP error code returned.
		} elseif ($response_body && isset($response_body->code) && $response_body->code != 201) {
			$error_message = "Error occured when posting data to KeyCRM: " . esc_html($response_body->message) . ", error code: " . $response_body->code;
		}

		if ($error_message) {
			$this->_log($error_message);
			return false;
		}

		$response_body = isset($response['body']) ? json_decode($response['body']) : null;

		return $response_body;
}

    /**
     * @return <missing>|array<string,mixed>
     */
	public function call_api($form_data, $settings)
	{
		$this->_settings = $settings;
		$this->_log('Lead received: ' . json_encode($form_data));

		if (empty($settings['mf_keycrm_api_key'])
			|| empty($settings['mf_keycrm_source_id'])
			|| empty($settings['mf_keycrm_sku'])) {
			$return['status'] = 0;
			$return['error'] = esc_html__($this->_error('Some KeyCRM settings are not configured.'), 'metform');
			return $return;
		}

		$source_id = isset($settings['mf_keycrm_source_id']) ? $settings['mf_keycrm_source_id'] : '';

		$skuSetting = isset($settings['mf_keycrm_sku']) ? $settings['mf_keycrm_sku'] : '';
		$skuPairs = array();
		$defaultSku = NULL;

		foreach(explode("\n", $skuSetting) as $pair) {
			$pair = explode(':', $pair, 2);
			if (count($pair) == 1) {
				$defaultSku = trim($pair[0]);
			} else {
				list($pairDomain, $pairSku) = $pair;
				$skuPairs[trim($pairDomain)] = trim($pairSku);
			}
		}

		$sku = NULL;
		if (isset($skuPairs[$_SERVER['SERVER_NAME']])) {
			$sku = $skuPairs[$_SERVER['SERVER_NAME']];
		} elseif (isset($defaultSku)) {
			$sku = $defaultSku;
		}

		if (empty($sku)) {
			$return['status'] = 0;
			$return['error'] = esc_html__($this->_error('Product SKU not found.'), 'metform');
			return $return;
		}

		$product = $this->_fetchProduct($sku);

		if (!$product) {
			$return['status'] = 0;
			$return['error'] = esc_html__($this->_error('Product not found.'), 'metform');
			return $return;
		}

		$phone = null;
		foreach (['mf-telephone', 'mf-number'] as $key) {
			if (isset($form_data[$key])) {
				$phone = $form_data[$key];
			}
		}

		$name = '';
		foreach (['mf-listing-fname', 'mf-listing-lname'] as $key) {
			if (isset($form_data[$key])) {
				$name .= ' ' . $form_data[$key];
			}
		}
		$name = trim($name);

		if (!$phone) {
			return array(
				'status' => 0,
				'error' => $this->_error('Phone number not given')
			);
		}

		$data = [
				"source_id" => $source_id,
				"source_uuid" => uniqid(),
				"buyer" => [
					"full_name" => $name,
					"phone" => $phone,
				],
			"products" => [
				array_merge($product, [
					"quantity" => 1
				])
			]
		];

		$query = null;
		$referrer = wp_get_referer();
		if ($referrer) {
				$parsed = parse_url($referrer);
				if (isset($parsed['query']) && $parsed['query']) {
						parse_str($parsed['query'], $query);
				}
		}

		if ($query) {
			$utm_keys = [
				"utm_source",
				"utm_medium",
				"utm_campaign",
				"utm_term",
				"utm_content",
			];
			$data['marketing'] = [];
			foreach ($utm_keys as $utm_key) {
				if (isset($query[$utm_key])) {
					$data['marketing'][$utm_key] = $query[$utm_key];
				}
			}
		}

		$response_body = $this->_request('order', 'POST', NULL, $data, $error_message);

		if ($error_message) {
			/* $return['status'] = 0; */
			/* $return['msg'] = $this->_error("Error occured when posting data to KeyCRM: " . esc_html($error_message) . ", error code: " . $response_body->code); */
			$this->send_admin_email($data);
		}

		$return['status'] = 1;
		$return['msg'] = esc_html__($this->_log('Your data inserted on KeyCRM.'), 'metform');

		return $return;
	}

	function send_admin_email($form_data) {
			$to = get_option('admin_email');
			if (!$to) {
				$this->_error("Admin email is not configured.");
				return;
			}
			$this->_log("Sending lead info to admin email: " . $to);

			$subject = 'Posting lead information to CRM failed';
			$message = json_encode($form_data);

			wp_mail($to, $subject, $message);
	}

	function _log($msg) {
		error_log($msg);
		return $msg;
	}

	function _error($msg) {
		return $this->_log($msg);
	}
}
