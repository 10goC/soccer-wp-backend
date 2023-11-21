<?php
namespace Soccer;

class Reservation {

	protected $player_id;
	protected $team_id;

	const PENDING = 'pending';
	const CONFIRMED = 'confirmed';
	const CANCELLED = 'cancelled';

	const TOLERANCE_MINUTES = 30;

	public function __construct( $player_id, $team_id ) {
		$this->player_id = $player_id;
		$this->team_id = $team_id;
	}

	public static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	public static function table() {
		return self::db()->prefix . 'ff_reservations';
	}

	public static function install() {
		$table_name = self::table();
		$sql = "CREATE TABLE $table_name (
			id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
			player_id INT NOT NULL,
			team_id INT NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			status VARCHAR(128) NULL DEFAULT NULL
		) ENGINE InnoDB";
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Cancel expired reservations
	 */
	public static function cancelExpired() {
		$table = self::table();
		$expired = self::db()->get_results(self::db()->prepare(
			"SELECT * FROM $table
			WHERE status = %s AND NOW() > DATE_ADD(
				created_at, INTERVAL %d MINUTE
			)",
			self::PENDING,
			self::TOLERANCE_MINUTES
		));
		if (!$expired) {
			return;
		}
		foreach ($expired as $row) {
			$team = new Team( $row->team_id );
			$team->remove_player( $row->player_id );
			self::db()->update( self::table(), [
				'status' => self::CANCELLED
			], [
				'id' => $row->id
			]);
		}
	}

	/**
	 * Cancel specific pending reservations
	 */
	public static function cancelPlayerPendingTeamReservations( $player_id, $team_id ) {
		self::db()->update(self::table(), [
			'status' => self::CANCELLED
		], [
			'status' => self::PENDING,
			'player_id' => $player_id,
			'team_id' => $team_id
		]);
	}

	public function save() {
		self::db()->insert(self::table(), [
			'player_id' => $this->player_id,
			'team_id' => $this->team_id,
			'status' => self::PENDING
		]);
	}
}
