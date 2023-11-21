<?php
namespace Soccer;

use Stripe\PaymentIntent;

define( 'TEST_MODE', false );
define( 'DUMMY_ID', 'stripe-payment-intent-test-id' );

class StripePayment extends StripeModel {
	protected static $table_name = 'stripe_payments';
	protected $fields = ['id', 'payment_intent_id', 'user_id', 'client_secret', 'amount', 'amount_received', 'status'];

	public static function install() {
		$table_name = self::table();
		$sql = "CREATE TABLE $table_name (
			id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
			payment_intent_id VARCHAR(128) NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			user_id INT NULL DEFAULT NULL,
			client_secret VARCHAR(128) NULL DEFAULT NULL,
			amount INT NOT NULL DEFAULT 0,
			amount_received INT NOT NULL DEFAULT 0,
			status VARCHAR(128) NULL DEFAULT NULL,
			UNIQUE ( payment_intent_id )
		) ENGINE InnoDB";
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	public static function findByPaymentId($id) {
		global $wpdb;
		$table_name = self::table();
		$sql = $wpdb->prepare("SELECT * FROM $table_name WHERE
			payment_intent_id = %s",
			TEST_MODE ? DUMMY_ID : $id
		);

		$row = $wpdb->get_row($sql, ARRAY_A);
		if ($row) {
			return new StripePayment($row);
		}
	}

	public function save() {
		global $wpdb;
		$stripePayment = self::findByPaymentId($this->payment_intent_id);
		if ($stripePayment) {
			$this->id = $stripePayment->id;
		}
		if ($this->id) {
			$wpdb->update(self::table(), [
				'amount_received' => $this->amount_received,
				'status' => $this->status
			], [
				'id' => $this->id
			]);
		} else {
			$wpdb->insert(self::table(), [
				'user_id' => $this->user_id,
				'payment_intent_id' => TEST_MODE ? DUMMY_ID : $this->payment_intent_id,
				'client_secret' => $this->client_secret,
				'amount' => $this->amount,
				'amount_received' => $this->amount_received,
				'status' => TEST_MODE ? 'processing' : $this->status
			]);
			$this->id = $wpdb->insert_id;
		}
	}

	public static function updateStatus(PaymentIntent $paymentIntent) {
		$stripePayment = self::findByPaymentId(TEST_MODE ? DUMMY_ID : $paymentIntent->id);
		if ($stripePayment) {
			$stripePayment->amount_received = $paymentIntent->amount_received;
			$stripePayment->status = $paymentIntent->status;
			$stripePayment->save();
		}
		return $stripePayment;
	}
}