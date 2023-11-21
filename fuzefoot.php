<?php
/*
Plugin Name: Soccer backend
Description: Backend code for the Soccer app
Version: 1.0
Author: Diego Curyk
Text Domain: soccer
*/

namespace Soccer;

use WP_User_Query;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/classes/Player.php';
require_once __DIR__ . '/classes/Request.php';
require_once __DIR__ . '/classes/Reservation.php';
require_once __DIR__ . '/classes/StripeModel.php';
require_once __DIR__ . '/classes/StripePayment.php';
require_once __DIR__ . '/classes/StripePaymentTeam.php';
require_once __DIR__ . '/classes/Team.php';

class Soccer {
	public function init() {

		$routes = new Routes();

		add_filter( 'jwt_auth_token_before_sign', [$this, 'access_token_data'], 10, 2 );
		add_filter( 'jwt_auth_expire', [$this, 'access_token_expire'] );
		add_action( 'init', [$this, 'handle_preflight'] );
		add_action( 'save_post_match', [$this, 'create_teams'] );
		add_action( 'rest_api_init', [$routes, 'init'] );
		add_action( 'add_meta_boxes', [$this, 'players_meta_box'] );
		add_action( 'admin_enqueue_scripts', [$this, 'admin_styles'] );
		add_action( 'wp_ajax_user_search', [$this, 'user_search'] );
		add_action( 'wp_ajax_add_player', [$this, 'add_player'] );
		add_action( 'wp_ajax_remove_player', [$this, 'remove_player'] );
		add_action( 'wp_ajax_change_qty', [$this, 'change_qty'] );
		add_filter( 'user_contactmethods', [$this, 'contact_methods'], 10, 1 );
		add_filter( 'manage_users_columns', [$this, 'modify_user_table'] );
		add_filter( 'manage_users_custom_column', [$this, 'modify_user_table_row'], 10, 3 );
		add_filter( 'cron_schedules', [$this, 'add_cron_interval'] );
		add_filter( 'pre_get_avatar_data', [$this, 'get_avatar'], 10, 2 );
		add_action( 'soccer_payment_expiration_check', [$this, 'payment_expiration_check'] );

		// Modify the expiration date for the password reset link
		add_filter( 'password_reset_expiration', function( $expiration ) {
    		return 3600; // An hour
		});

	}

	/**
	 * Admin styles
	 */
	public function admin_styles() {
		wp_enqueue_style( 'admin-styles', plugin_dir_url(__FILE__) . 'css/admin.css', [], '1.1' );
		wp_enqueue_script( 'jquery-autocomplete' );
	}

	/**
	 * Data to include in the access token
	 */
	public function access_token_data( $token, $user ) {
		$token['data']['user']['displayName'] = $user->data->display_name;
		$token['data']['user']['photoURL'] = get_avatar_url( $user );
		$token['data']['user']['role'] = $user->roles[0];
		return $token;
	}

	/**
	 * Access token expiration
	 */
	public function access_token_expire( $expire ) {
		$expire = time() + YEAR_IN_SECONDS;
		return $expire;
	}

	/**
	 * Filter for allowing custom avatars
	 */
	public function get_avatar( $args, $id_or_email ) {
		if ($id_or_email instanceof \WP_User) {
			$id_or_email = $id_or_email->ID;
		}
		$avatar = get_user_meta( $id_or_email, 'wp_user_avatar' );
		if ($avatar) {
			$args['url'] = wp_get_attachment_image_url($avatar[0], 'medium');
		}
		return $args;
	}

	/**
	 * CORS settings
	 */
	public function handle_preflight() {
		header("Access-Control-Allow-Origin: *");
		header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
		header("Access-Control-Allow-Credentials: true");
		header('Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization');
		if ('OPTIONS' == $_SERVER['REQUEST_METHOD']) {
			status_header(200);
			exit();
		}
	}

	/**
	 * Create teams for new match
	 */
	public function create_teams( $post_id ) {
		$teams = get_post_meta( $post_id, 'teams', true );
		$match = get_post( $post_id );
		$places = get_post_meta( $post_id, 'places', true );
		$number_of_teams = get_post_meta( $post_id, 'number_of_teams', true );

		// Create teams
		if (empty($teams) || count($teams) < $number_of_teams) {
			$teams = [];
			for ($number = 1; $number <= $number_of_teams; $number++) {
				$team_name = "Team $number";
				$team_id = wp_insert_post([
					'post_title' => $team_name . ' | ' . $match->post_title,
					'post_content' => '',
					'post_type' => 'team',
					'post_status' => 'publish'
				]);
				$teams[] = $team_id;
			}
			update_post_meta( $post_id, 'teams', $teams );
		}

		// Update name and places
		$number = 0;
		foreach ($teams as $team_id) {
			$number++;
			$team = get_post($team_id);
			update_post_meta( $team_id, 'places', $places );
			update_post_meta( $team_id, 'match', $post_id );
			$match_name = strpos($team->post_title, ' | ');
			$team_name = $match_name ? preg_replace("/ \| .*/", '', $team->post_title) : $team->post_title;
			$new_name = (empty($team_name) || $team_name == $match->post_title) ? "Team $number" : $team_name;
			wp_update_post([
				'ID' => $team_id,
				'post_title' => $new_name . ' | ' . $match->post_title
			]);
		}
	}

	public function players_meta_box() {
		add_meta_box('match_players', 'Players', [$this, 'players_meta_box_html'], 'match');
	}

	public function players_meta_box_html() {
		load_template(__DIR__ . '/templates/players_meta_box.php');
	}

	public function user_search() {
		wp_die(json_encode($this->_user_search()));
	}

	protected function _user_search() {
		if ( ! current_user_can( 'administrator' ) ) {
			return [];
		}
		$term = '*' . $_GET['term'] . '*';
		$query = new WP_User_Query([
			'search' => $term,
			'search_columns' => [
				'display_name',
				'user_email',
			],
		]);
	if ( empty( $query->get_results() ) ) {
			return [];
		}
		$users = [];
		foreach ( $query->get_results() as $user ) {
			$phone = get_user_meta($user->ID, 'phone', true);
			$username = $user->display_name;
			if ($phone) $username .= " ($phone)";
			$users[] = [
				'id' => $user->ID,
				'value' => $username
			];
		}
		return $users;
	}

	public function add_player() {
		wp_die(json_encode($this->_add_player()));
	}

	protected function _add_player() {
		if ( ! current_user_can( 'administrator' ) ) {
			return [
				'success' => false,
				'message' => 'Unauthorized'
			];
		}
		$player_id = Request::post('player');
		$team_id = Request::post('team');

		if ( ! $player_id ) {
			return [
				'success' => false,
				'message' => 'Must specify a player'
			];
		}

		if ( ! $team_id ) {
			return [
				'success' => false,
				'message' => 'Must specify a team'
			];
		}

		$player = get_user_by( 'ID', $player_id );
		$team = get_post( $team_id );

		if ( !$player ) {
			return [
				'success' => false,
				'message' => 'Player not found'
			];
		}

		if ( !$team ) {
			return [
				'success' => false,
				'message' => 'Team not found'
			];
		}

		try {
			$team = new Team($team_id);
			$team->add_player($player_id, true, true);
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => $e->getMessage()
			];
		}

		return [
			'success' => true,
		];

	}

	public function remove_player() {
		wp_die(json_encode($this->_remove_player()));
	}

	protected function _remove_player() {
		if ( ! current_user_can( 'administrator' ) ) {
			return [
				'success' => false,
				'message' => 'Unauthorized'
			];
		}
		$player_id = Request::post('player');
		$team_id = Request::post('team');

		if ( ! $player_id ) {
			return [
				'success' => false,
				'message' => 'Must specify a player'
			];
		}

		if ( ! $team_id ) {
			return [
				'success' => false,
				'message' => 'Must specify a team'
			];
		}

		try {
			$team = new Team($team_id);
			$team->remove_player($player_id);
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => $e->getMessage()
			];
		}

		return [
			'success' => true,
		];
	}

	public function change_qty() {
		wp_die(json_encode($this->_change_qty()));
	}

	protected function _change_qty() {
		if ( ! current_user_can( 'administrator' ) ) {
			return [
				'success' => false,
				'message' => 'Unauthorized'
			];
		}
		$player_id = Request::post('player');
		$team_id = Request::post('team');
		$qty = Request::post('qty');

		if ( ! $player_id ) {
			return [
				'success' => false,
				'message' => 'Must specify a player'
			];
		}

		if ( ! $team_id ) {
			return [
				'success' => false,
				'message' => 'Must specify a team'
			];
		}

		if ( ! $qty ) {
			return [
				'success' => false,
				'message' => 'Must specify quantity'
			];
		}

		try {
			$team = new Team($team_id);
			$team->change_qty($player_id, $qty);
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => $e->getMessage()
			];
		}

		return [
			'success' => true,
		];
	}

	public function contact_methods( $contactmethods ) {
		$contactmethods['phone'] = 'Phone Number';
		return $contactmethods;
	}
	
	public function modify_user_table( $columns ) {
		$columns['phone'] = 'Phone';
		unset($columns['posts']);
		unset($columns['role']);
		return $columns;
	}
	
	public function modify_user_table_row( $val, $column_name, $user_id ) {
		switch ($column_name) {
			case 'phone' :
				return get_user_meta( $user_id, 'phone', true );
			default:
		}
		return $val;
	}

	/**
	 * Plugin activation hook
	 */
	public function plugin_activation() {
		StripePayment::install();
		StripePaymentTeam::install();
		Reservation::install();
		if (! wp_next_scheduled ( 'soccer_payment_expiration_check' )) {
			wp_schedule_event( time(), 'every_minute', 'soccer_payment_expiration_check' );
		}
	}

	public static function normalize_locale( $locale ) {
		if ($locale === 'de') return 'de_DE';
		if ($locale === 'en') return 'en_US';
		if ($locale === 'es') return 'es_ES';
		if ($locale === 'fr') return 'fr_FR';
		return $locale;
	}

	/**
	 * Create a cron interval that runs every minute
	 */
	public function add_cron_interval( $schedules ) { 
		$schedules['every_minute'] = [
			'interval' => 60,
			'display'  => 'Every Minute',
		];
		return $schedules;
	}

	/**
	 * Check for expired match reservations and remove players
	 */
	public function payment_expiration_check() {
		Reservation::cancelExpired();
	}
	
}
$soccer = new Soccer();
$soccer->init();

register_activation_hook(
	__FILE__,
	[$soccer, 'plugin_activation']
);
