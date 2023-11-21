<?php
namespace Soccer;

class Request {
	public static function post($var, $default = null) {
		if (isset($_POST[$var])) {
			return $_POST[$var];
		}
		$json = @file_get_contents('php://input');
		if (!$json) return $default;
		$payload = json_decode($json, true);
		if (isset($payload[$var])) {
			return $payload[$var];
		}
		return $default;
	}
}