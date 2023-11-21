<?php
namespace Soccer;

use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;
use WP_REST_Response;

class Routes {

	const NAMESPACE = 'soccer/v1';

	public function init() {
		
		register_rest_route( self::NAMESPACE, '/user', [
			'methods' => 'POST',
			'callback' => [$this, 'register_user'],
		] );

		register_rest_route( self::NAMESPACE, '/reset-password', [
			'methods' => 'POST',
			'callback' => [$this, 'get_password_reset_token'],
		] );

		register_rest_route( self::NAMESPACE, '/reset-password-validation', [
			'methods' => 'POST',
			'callback' => [$this, 'get_password_validation'],
		] );

		register_rest_route( self::NAMESPACE, '/save-new-password', [
			'methods' => 'POST',
			'callback' => [$this, 'save_new_password'],
		] );

		register_rest_route( self::NAMESPACE, '/user', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_account'],
		] );

		register_rest_route( self::NAMESPACE, '/matches', [
			'methods' => 'GET',
			'callback' => [$this, 'get_matches'],
		] );

		register_rest_route( self::NAMESPACE, '/matches/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_match'],
		] );

		register_rest_route( self::NAMESPACE, '/teams/join', [
			'methods' => 'POST',
			'callback' => [$this, 'join_team'],
		] );

		register_rest_route( self::NAMESPACE, '/teams/leave', [
			'methods' => 'POST',
			'callback' => [$this, 'leave_team'],
		] );

		register_rest_route( self::NAMESPACE, '/teams/qty', [
			'methods' => 'POST',
			'callback' => [$this, 'change_qty'],
		] );
		
		register_rest_route( self::NAMESPACE, '/profile', [
			'methods' => 'GET',
			'callback' => [$this, 'get_user_profile'],
		] );
		
		register_rest_route( self::NAMESPACE, '/profile', [
			'methods' => 'POST',
			'callback' => [$this, 'save_user_profile'],
		] );

		register_rest_route( self::NAMESPACE, '/avatar', [
			'methods' => 'POST',
			'callback' => [$this, 'save_avatar'],
		] );

		register_rest_route( self::NAMESPACE, '/stripe', [
			'methods' => 'POST',
			'callback' => [$this, 'stripe_payment'],
		] );

		register_rest_route( self::NAMESPACE, '/stripe/confirm', [
			'methods' => 'POST',
			'callback' => [$this, 'confirm_payment'],
		] );

		register_rest_route( self::NAMESPACE, '/stripe/webhook', [
			'methods' => 'POST',
			'callback' => [$this, 'stripe_webhook'],
		] );

		register_rest_route( self::NAMESPACE, '/stripe/success', [
			'methods' => 'GET',
			'callback' => [$this, 'stripe_success'],
		] );
	}

	/**
	 * Register user
	 * POST /user
	 */
	public function register_user() {
		foreach (['email', 'password', 'name', 'birthdate'] as $field) {
		// foreach (['email', 'password', 'name', 'phone', 'birthdate', 'gender'] as $field) {
			if (!Request::post($field)) {
				return new WP_REST_Response(['error' => "The field '$field' is required."], 400);
			}
		}

		$username = sanitize_text_field(Request::post('email'));
		$email = sanitize_text_field(Request::post('email'));
		$password = sanitize_text_field(Request::post('password'));
		$name = sanitize_text_field(Request::post('name'));
		$phone = sanitize_text_field(Request::post('phone'));
		$birthdate = sanitize_text_field(Request::post('birthdate'));
		$gender = sanitize_text_field(Request::post('gender'));

		$user_id = username_exists($username);
		if (!$user_id && email_exists($email) == false) {
			$user_id = wp_create_user($username, $password, $email);
			if (is_wp_error($user_id)) {
				return new WP_REST_Response(['error' => $user_id], 500);
			}
			// Ger User Meta Data (Sensitive, Password included. DO NOT pass to front end.)
			$user = get_user_by('id', $user_id);
			$user->set_role('subscriber');
			wp_update_user([ 'ID' => $user_id, 'display_name' => $name ]);
			update_user_meta($user_id, 'phone', $phone);
			update_user_meta($user_id, 'birthdate', $birthdate);
			update_user_meta($user_id, 'gender', $gender);

			// Ger User Data (Non-Sensitive, Pass to front end.)
			return new WP_REST_Response(['success' => "User '$username' Registration was Successful"], 200);
		} else {
			return new WP_REST_Response(['error' => "Email already exists, please try 'Reset Password'"], 406);
		}
	}

	/**
	 * Send email for password reset
	 * POST /reset-password
	 */
	public function get_password_reset_token() {
		$email = Request::post('email');
		if (!$email) {
			return new WP_REST_Response(['error' => 'Email is required.'], 400);
		}
		$user = get_user_by( 'email', $email );
		if (!$user) {
			return new WP_REST_Response(['error' => 'User not found.'], 404);
		}
		$return_url = Request::post('returnURL');
		if (!$return_url) {
			return new WP_REST_Response(['error' => 'Return URL is required.'], 400);
		}
		$locale = Soccer::normalize_locale(Request::post('locale', 'en_US'));
		switch_to_locale($locale);
		load_plugin_textdomain('soccer', false,  'soccer/languages');

		$key = get_password_reset_key( $user );
		$link = "$return_url?key=$key&email=$email&locale=$locale";

		// Specify the metadata
		$to = $user->user_email;
		$subject = __('Password reset', 'soccer');
		$headers = array('Content-Type: text/html; charset=UTF-8');

		// Retrieve the template from an external file
		ob_start();
		load_template(__DIR__ . '/templates/email/password_reset.php');
		$template = ob_get_clean();

		// Use dynamic links to personnalize the mail
		$body = str_replace([
			'[[username]]',
			'[[password_reset_link]]',
			'[[company_name]]'
		], [
			$user->display_name,
			$link,
			'Soccer'
		], $template);

		wp_mail($to, $subject, $body, $headers);

		return new WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Validate password reset token
	 * POST /reset-password-validation
	 */
	public function get_password_validation() {
		$key = Request::post('token');
		if (!$key) {
			return new WP_REST_Response(['error' => 'You must provide a valid token.'], 400);
		}
		$email = Request::post('email');
		if (!$email) {
			return new WP_REST_Response(['error' => 'Email is required.'], 400);
		}
		$result = check_password_reset_key($key, $email);
		if (is_wp_error($result)) {
			return new WP_REST_Response(['error' => $result->get_error_code()], 400);
		}
		return new WP_REST_Response([
			'valid' => true
		], 200);
	}

	/**
	 * Validate token and save new password
	 * POST /save-new-password
	 */
	public function save_new_password() {
		$password = Request::post('password');
		if (!$password) {
			return new WP_REST_Response(['error' => 'You must provide a valid password.'], 400);
		}
		$key = Request::post('token');
		if (!$key) {
			return new WP_REST_Response(['error' => 'You must provide a valid token.'], 400);
		}
		$email = Request::post('email');
		if (!$email) {
			return new WP_REST_Response(['error' => 'Email is required.'], 400);
		}
		$result = check_password_reset_key($key, $email);
		if (is_wp_error($result)) {
			return new WP_REST_Response(['error' => $result->get_error_code()], 400);
		}
		$result->user_pass = $password;
		wp_update_user($result);
		return new WP_REST_Response([
			'success' => true
		], 200);
	}

	/**
	 * Delete account
	 * DELETE /user
	 */
	public function delete_account() {
		$user = wp_get_current_user();
		if (!$user) {
			return new WP_REST_Response(['error' => 'Not logged in. Account cannot be deleted.'], 400);
		}
		$deleted_accounts = get_option('deleted_accounts', []);
		$deleted_accounts[$user->ID] = $user->user_email;
		update_option('deleted_accounts', $deleted_accounts);
		require_once(ABSPATH . 'wp-admin/includes/user.php');
		wp_delete_user($user->ID);
		return new WP_REST_Response(['error' => 'Account deleted successfully'], 200);
	}

	/**
	 * Get matches
	 * GET /matches
	 */
	public function get_matches() {
		$matches = [];
		$match_posts = get_posts([
			'post_type' => 'match',
			'post_status' => ['publish', 'future'],
			'numberposts' => -1,
			'order' => 'ASC'
		]);
		foreach ( $match_posts as $match_post ) {
			$matches[] = $this->match_array($match_post);
		}
		echo json_encode($matches);
	}

	/**
	 * Get match
	 * GET /match/:id
	 */
	public function get_match( $params ) {
		$match = get_post($params['id']);
		echo json_encode($this->match_array($match));
	}

	/**
	 * Join team
	 * POST /teams/join
	 */
	public function join_team() {

		$user_id = get_current_user_id();
		$team_id = Request::post('team');

		if (!$user_id) {
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}

		if (!$team_id) {
			return new WP_REST_Response(['error' => 'No team selected'], 400);
		}

		try {
			$team = new Team($team_id);
			$team->add_player($user_id, false, false);
		} catch (\Exception $e) {
			return new WP_REST_Response(['error' => $e->getMessage()], 400);
		}
		return new WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Leave team
	 * POST /teams/leave
	 */
	public function leave_team() {

		$user_id = get_current_user_id();
		$team_id = Request::post('team');

		if (!$user_id) {
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}

		if (!$team_id) {
			return new WP_REST_Response(['error' => 'No team selected'], 400);
		}

		try {
			$team = new Team($team_id);
			$team->remove_player($user_id);
		} catch (\Exception $e) {
			return new WP_REST_Response(['error' => $e->getMessage()], 400);
		}
		return new WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Change qty
	 * POST /teams/qty
	 */
	public function change_qty() {
		$user_id = get_current_user_id();
		$team_id = Request::post('team');
		$qty = Request::post('qty');

		if (!$user_id) {
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}

		if (!$team_id) {
			return new WP_REST_Response(['error' => 'No team selected'], 400);
		}

		if (!$qty) {
			return new WP_REST_Response(['error' => 'Unspecified quantity'], 400);
		}

		try {
			$team = new Team($team_id);
			$team->change_qty($user_id, $qty);
		} catch (\Exception $e) {
			return new WP_REST_Response(['error' => $e->getMessage()], 400);
		}
		return new WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Confirm teams (after payment)
	 */
	protected function confirm_teams($team_ids, $user_id = null) {

		if (!$user_id) {
			$user_id = get_current_user_id();
		}

		if (!$user_id) {
			return false;
		}

		foreach ($team_ids as $team_id) {
			$team = new Team($team_id);
			$team->confirm_player($user_id);
		}
	}

	/**
	 * Get user profile
	 * GET /profile
	 */
	public function get_user_profile() {
		$user_id = get_current_user_id();
		if (!$user_id) {
			return new WP_REST_Response([
				'upcomingMatches' => [],
				'pastMatches' => []
			], 200);
		}
		$match_ids = get_user_meta($user_id, 'matches', true);
		$upcoming = [];
		$past = [];
		$upcoming_match_posts = $match_ids ? get_posts([
			'post_type' => 'match',
			'post_status' => 'future',
			'include' => $match_ids
		]) : [];
		$past_match_posts = $match_ids ? get_posts([
			'post_type' => 'match',
			'post_status' => 'publish',
			'include' => $match_ids
		]) : [];
		foreach ($upcoming_match_posts as $match_post) {
			$upcoming[] = $this->match_array($match_post);
		}
		foreach ($past_match_posts as $match_post) {
			$past[] = $this->match_array($match_post);
		}
		$user = wp_get_current_user();
		return new WP_REST_Response([
			'upcomingMatches' => $upcoming,
			'pastMatches' => $past,
			'name' => $user->display_name,
			'email' => $user->user_email,
			'phone' => get_user_meta( $user_id, 'phone', true ),
			'birthdate' => get_user_meta( $user_id, 'birthdate', true ),
			'gender' => get_user_meta( $user_id, 'gender', true )
		], 200);
	}

	/**
	 * Save user profile
	 * POST /profile
	 */
	public function save_user_profile() {
		$user_id = get_current_user_id();
		if (!$user_id) {
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}
		$name = Request::post('name');
		$email = Request::post('email');
		$phone = Request::post('phone');
		$birthdate = Request::post('birthdate');
		$gender = Request::post('gender');

		if ($name) {
			wp_update_user([ 'ID' => $user_id, 'display_name' => $name ]);
		}
		if ($email) {
			wp_update_user([ 'ID' => $user_id, 'user_email' => $email ]);
		}
		if ($phone) {
			update_user_meta($user_id, 'phone', $phone );
		}
		if ($birthdate) {
			update_user_meta($user_id, 'birthdate', $birthdate );
		}
		if ($gender) {
			update_user_meta($user_id, 'gender', $gender );
		}
		return new WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Save avatar
	 * POST /avatar
	 */
	public function save_avatar() {
		$user_id = get_current_user_id();
		if (!$user_id) {
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}
		$rawData = Request::post('data');
		if ($rawData) {
			$format = Request::post('format');
			$data = base64_decode($rawData);
			$filename = md5(uniqid()) . '.' . $format;
			$upload = wp_upload_bits( $filename, null, $data );
			if (!empty($upload['error'])) {
				return new WP_REST_Response(['error' => $upload['error']], 500);
			}
			$wp_filetype = wp_check_filetype( basename( $upload['file'] ), null );
			$wp_upload_dir = wp_upload_dir();
			$attachment = [
				'guid' => $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path( $upload['file'] ),
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => preg_replace('/\.[^.]+$/', '', basename( $upload['file'] )),
				'post_content'   => '',
				'post_status'    => 'inherit'
			];
			$attach_id = wp_insert_attachment( $attachment, $upload['file']);
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
			wp_update_attachment_metadata( $attach_id, $attach_data );
			update_user_meta($user_id, 'wp_user_avatar', $attach_id);
		}
		return new WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Create Stripe Payment Intent
	 * POST /stripe
	 */
	public function stripe_payment() {
		$secret = get_option('stripe_secret_key');
		Stripe::setApiKey($secret);
		try {
			// retrieve JSON from POST body
			$items = Request::post('items');

			$amount = $this->calculateOrderAmount($items);
		
			// Create a PaymentIntent with amount and currency
			$paymentIntent = PaymentIntent::create([
				'amount' => $amount,
				'currency' => 'chf',
				'automatic_payment_methods' => [
					'enabled' => true,
				],
			]);

			// Save locally
			$this->savePaymentIntent($paymentIntent, $items);
		
			$output = [
				'clientSecret' => $paymentIntent->client_secret,
			];

			echo json_encode($output);
		} catch (\Error $e) {
			http_response_code(500);
			echo json_encode(['error' => $e->getMessage()]);
		}
	}

	/**
	 * Confirm Stripe Payment
	 * POST /stripe/confirm
	 */
	public function confirm_payment() {
		$items = Request::post('items');
		$payment_intent_data = Request::post('paymentIntent');
		$secret = get_option('stripe_secret_key');
		$stripe = new StripeClient($secret);
		$paymentIntentService = new PaymentIntentService($stripe);
		$paymentIntent = $paymentIntentService->retrieve($payment_intent_data['id']);

		// Save locally
		$this->savePaymentIntent($paymentIntent, $items);

		return $this->get_user_profile();
	}

	protected function savePaymentIntent(PaymentIntent $paymentIntent, $items) {
		$stripePayment = new StripePayment([
			'user_id' => get_current_user_id(),
			'payment_intent_id' => $paymentIntent->id,
			'client_secret' => $paymentIntent->client_secret,
			'amount' => $paymentIntent->amount,
			'amount_received' => $paymentIntent->amount_received,
			'status' => $paymentIntent->status
		]);
		$stripePayment->save();
		$team_ids = [];
		foreach ($items as $item) {
			$team_ids[] = $item['teamId'];
			$stripePaymentTeam = new StripePaymentTeam([
				'payment_id' => $stripePayment->id,
				'team_id' => $item['teamId'],
				'quantity' => $item['qty'] ?? 1
			]);
			$stripePaymentTeam->save();
		}

		if (!TEST_MODE && $paymentIntent->status === 'succeeded') {
			$this->confirm_teams($team_ids);
		}
	}

	public function stripe_webhook() {
		$secret = get_option('stripe_secret_key');
		$endpoint_secret = get_option('stripe_webhook_secret');
		$stripe = new StripeClient($secret);

		$payload = @file_get_contents('php://input');
		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
		$event = null;

		try {
			$event = Webhook::constructEvent(
				$payload, $sig_header, $endpoint_secret
			);
		} catch(\UnexpectedValueException $e) {
			// Invalid payload
			http_response_code(400);
			exit();
		} catch(\Stripe\Exception\SignatureVerificationException $e) {
			// Invalid signature
			http_response_code(400);
			exit();
		}

		// Handle the event
		switch ($event->type) {
			case 'payment_intent.succeeded':
				$paymentIntent = $event->data->object;
				$payment = StripePayment::updateStatus($paymentIntent);
				$team_ids = StripePaymentTeam::getTeamIdsByPayment($paymentIntent);
				if ($team_ids) {
					$this->confirm_teams($team_ids, $payment->user_id);
				}
				break;
			case 'payment_intent.payment_failed':
				// @todo: Handle failures
				break;
			default:
				// ... handle other event types
				echo 'Received unknown event type ' . $event->type;
		}

		http_response_code(200);
	}

	public function stripe_success() {
		die(header('location:soccer://cart/payment-complete'));
	}

	protected function calculateOrderAmount(array $items): int {
		// Calculate the order total on the server to prevent
		// people from directly manipulating the amount on the client
		$total = 0;
		foreach ($items as $item) {
			$match = get_post( $item['matchId'] );
			$qty = $item['qty'] ?? 1;
			$total += $match->price * $qty * 100;
		}
		return $total;
	}

	protected function _get_fields() {
		$fields = [];
		$field_posts = get_posts([
			'post_type' => 'field',
			'post_status' => 'publish',
		]);
		foreach ($field_posts as $field) {
			$fields[$field->ID] = [
				'id' => $field->ID,
				'name' => $field->post_title,
				'city' => get_post_meta( $field->ID, 'city', true ),
				'address' => get_post_meta( $field->ID, 'address', true ),
				'fieldImageSrc' => wp_get_attachment_url( get_post_meta( $field->ID, 'image', true ) ),
			];
		}
		return $fields;
	}

	protected function match_array($match_post) {
		$fields = $this->_get_fields();
		$match = [
			'id' => $match_post->ID,
			'date' => $match_post->post_date,
			'field' => $fields[get_post_meta( $match_post->ID, 'field', true )],
			'places' => get_post_meta( $match_post->ID, 'places', true ),
			'price' => get_post_meta( $match_post->ID, 'price', true ),
			'duration' => get_post_meta( $match_post->ID, 'duration', true ),
			'description' => $match_post->post_excerpt,
			'teams' => [],
		];
		$team_ids = get_post_meta( $match_post->ID, 'teams', true );
		foreach ( $team_ids as $team_id ) {
			$team = get_post( $team_id );
			$players = [];
			$players_data = get_post_meta( $team_id, 'players', true );
			if ($players_data) {
				foreach ($players_data as $player_data) {
					$player = get_user_by( 'ID', $player_data['id'] );
					$players[] = [
						'uid' => $player->ID,
						'displayName' => $player->display_name,
						'avatar' => get_avatar_url( $player->ID ),
						'confirmed' => $player_data['confirmed'],
						'timestamp' => $player_data['timestamp'] ?? null,
						'qty' => $player_data['qty'] ?? 1
					];
				}
			}
			$match['teams'][] = [
				'id' => $team->ID,
				'name' => preg_replace('/ \|.*/', '', $team->post_title),
				'places' => get_post_meta( $team->ID, 'places', true ),
				'players' => $players
			];
		}
		return $match;
	}
}