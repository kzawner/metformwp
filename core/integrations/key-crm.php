<?php

namespace MetForm\Core\Integrations;

defined('ABSPATH') || exit;

class KeyCRM
{
	const URL = 'https://openapi.keycrm.app/v1/order';

	public function call_api($form_data, $settings)
	{

		$this->_log('Lead received: ' . json_encode($form_data));

		if (empty($settings['mf_keycrm_api_key'])
			|| empty($settings['mf_keycrm_source_id'])
			|| empty($settings['mf_keycrm_sku'])) {
			$return['status'] = 0;
			$return['error'] = esc_html__($this->_error('Some KeyCRM settings are not configured.'), 'metform');
			return $return;
		}

		$auth = [
			'api_key' => $settings['mf_keycrm_api_key']
		];

		$source_id = isset($settings['mf_keycrm_source_id']) ? $settings['mf_keycrm_source_id'] : '';
		$sku = isset($settings['mf_keycrm_sku']) ? $settings['mf_keycrm_sku'] : '';

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
				[
					"sku" => $sku,
					"quantity" => 1
				]
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

		$body = [
				'method' => 'POST',
				'data_format' => 'body',
				'timeout' => 45,
				'blocking'    => true,
				'headers' => [
					'Accept' => 'application/json',
					'Authorization' => 'Bearer ' . $auth['api_key'],
					'Content-Type' => 'application/json',
				],
				'body' => json_encode($data)
			];

		$response = wp_remote_post(self::URL, $body);

		error_log('Response: ' . json_encode($response));

		$response_body = NULL;
		if (!is_wp_error($response)) {
			$response_body = isset($response['body']) ? json_decode($response['body']) : null;
		}

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
			/* $return['status'] = 0; */
			/* $return['msg'] = $this->_error("Error occured when posting data to KeyCRM: " . esc_html($error_message) . ", error code: " . $response_body->code); */
			$this->send_admin_email($data);
		}

		$return['status'] = 1;
		$return['msg'] = esc_html__($this->_log('Your data inserted on KeyCRM.'), 'metform');

		return $return;
	}

	function send_admin_email($form_data){
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
