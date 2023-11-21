<?php
namespace Soccer;

use Stripe\PaymentIntent;

class StripePaymentTeam extends StripeModel {
	protected static $table_name = 'stripe_payment_team';
	protected $fields = ['id', 'payment_id', 'team_id', 'quantity'];

	public static function install() {
		$table_name = self::table();
		$sql = "CREATE TABLE $table_name (
			id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
			payment_id INT NOT NULL,
			team_id INT NOT NULL,
			quantity INT NOT NULL DEFAULT 1
		) ENGINE InnoDB";
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

    public function save() {
		global $wpdb;
        $wpdb->insert(self::table(), [
            'payment_id' => $this->payment_id,
            'team_id' => $this->team_id,
            'quantity' => $this->quantity,
        ]);
        $this->id = $wpdb->insert_id;
    }

	public static function getTeamIdsByPayment(PaymentIntent $paymentIntent) {
		global $wpdb;
		$table_name = self::table();
		$payment = StripePayment::findByPaymentId($paymentIntent->id);
		$sql = $wpdb->prepare("SELECT team_id FROM $table_name WHERE
			payment_id = %s",
			$payment->id
		);

		return $wpdb->get_col($sql);
	}
}