<?php
namespace Soccer;

abstract class StripeModel {

    protected static $table_name;
    protected $fields = [];

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . static::$table_name;
	}

	public function __construct($data) {
		foreach ($this->fields as $field) {
			if (isset($data[$field])) {
				$this->$field = $data[$field];
			}
		}
	}
}