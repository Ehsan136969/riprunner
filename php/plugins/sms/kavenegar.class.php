<?php
// ==============================================================
//	Copyright (C) 2014 Mark Vejvoda
//	Under GNU GPL v3.0
// ==============================================================
namespace riprunner;

if ( defined('INCLUSION_PERMITTED') === false ||
    (defined('INCLUSION_PERMITTED') === true && INCLUSION_PERMITTED === false ) ) {
	die( 'This file must not be invoked directly.' );
}

require_once 'plugin_interfaces.php';

class SMSKavenegarPlugin implements ISMSPlugin {

	public function getPluginType() {
		return 'KAVENEGAR';
	}
	public function getMaxSMSTextLength() {
		return 0;
	}
	public function signalRecipients($SMSConfig, $recipient_list, $recipient_list_type, $smsText) {

		$resultSMS = 'START Send SMS using Kavenegar.' . PHP_EOL;

		if($recipient_list_type === RecipientListType::GroupList) {
			throw new \Exception("Kavenegar SMS Plugin does not support groups!");
		}
		else {
			// Remove empty and null entries
			$recipient_list_numbers = array_filter($recipient_list, 'strlen' );
		}

		$resultSMS .= 'About to send SMS to: [' . implode(",", $recipient_list_numbers) . ']' . PHP_EOL;

		$api_key = $SMSConfig->SMS_PROVIDER_KAVENEGAR_API_KEY;
		$base_url = rtrim($SMSConfig->SMS_PROVIDER_KAVENEGAR_BASE_URL, '/');
		$url = $base_url . '/' . $api_key . '/sms/send.json';

		$data = array(
				'receptor' => implode(',', $recipient_list_numbers),
				'message' => $smsText
		);
		if(isset($SMSConfig->SMS_PROVIDER_KAVENEGAR_FROM) === true &&
			trim($SMSConfig->SMS_PROVIDER_KAVENEGAR_FROM) !== '') {
			$data['sender'] = $SMSConfig->SMS_PROVIDER_KAVENEGAR_FROM;
		}

		$s = curl_init();
		curl_setopt($s, CURLOPT_URL, $url);
		curl_setopt($s, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($s, CURLOPT_POSTFIELDS, http_build_query($data));
		curl_setopt($s, CURLOPT_RETURNTRANSFER, true);

		$result = curl_exec($s);
		$this->logTrace('Kavenegar curl_exec returned: ' . $result);

		$resultSMS .= 'RESPONSE: ' . $result . PHP_EOL;

		if(curl_errno($s) === 0) {
			$info = curl_getinfo($s);
			$resultSMS .= 'Took ' . $info['total_time'] . ' seconds to send a request to ' . $info['url'] . PHP_EOL;
		}
		else {
			$resultSMS .= 'Curl error: ' . curl_error($s) . PHP_EOL;
		}

		$decoded = json_decode($result);
		if(isset($decoded->return) === true && isset($decoded->return->status) === true) {
			if((int)$decoded->return->status !== 200) {
				$resultSMS .= 'Kavenegar error status: ' . $decoded->return->status . PHP_EOL;
			}
			else {
				$resultSMS .= 'Kavenegar success response!' . PHP_EOL;
			}
		}
		else {
			$resultSMS .= 'Kavenegar unexpected response.' . PHP_EOL;
		}

		curl_close($s);

		return $resultSMS;
	}

	protected function logError($text) {
		global $log;
		if($log != null) $log->error($text);
	}

	protected function logWarning($text) {
		global $log;
		if($log != null) $log->warn($text);
	}

	protected function logTrace($text) {
		global $log;
		if($log != null) $log->trace($text);
	}

}
