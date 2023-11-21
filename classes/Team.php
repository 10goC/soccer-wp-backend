<?php
namespace Soccer;

class Team {

	protected $team_id;

	public function __construct($team_id) {
		$this->team_id = $team_id;
	}

	/**
	 * Add player
	 */
	public function add_player( $player_id, $confirmed = false, $added_by_admin = false ) {
		$match = get_post( get_post_meta( $this->team_id, 'match', true ) );

		if ( !$match ) {
			throw new \Exception('Match not found');
		}

		$match_teams = get_post_meta( $match->ID, 'teams', true );

		$players = [];

		foreach ( $match_teams as $match_team_id ) {
			$team_players = get_post_meta( $match_team_id, 'players', true );
			if ( $match_team_id == $this->team_id && is_array($team_players) ) {
				$players = $team_players;
			}
			foreach ( $team_players as $team_player ) {
				if ( $team_player['id'] == $player_id ) {
					throw new \Exception('Player already in match');
				}
			}
		}

		$players[] = [
			'id' => $player_id,
			'confirmed' => $confirmed,
			'added_by_admin' => $added_by_admin,
			'timestamp' => time() * 1000
		];

		// Add player to team
		update_post_meta( $this->team_id, 'players', $players );

		// Add match to player
		$player = new Player( $player_id );
		$player->add_match( $match->ID );

		if (!$confirmed) {
			// Create spot reservation
			$reservation = new Reservation( $player_id, $this->team_id );
			$reservation->save();
		}
	}

	/**
	 * Remove player
	 */
	public function remove_player( $player_id ) {
		$players = get_post_meta( $this->team_id, 'players', true ) ?: [];

		$player_index = -1;

		foreach ($players as $i => $player) {
			if ($player['id'] == $player_id) {
				$player_index = $i;
				break;
			}
		}

		if ($player_index == -1) {
			throw new \Exception('Player not found');
		}

		// Remove player from team
		array_splice( $players, $player_index, 1 );
		update_post_meta( $this->team_id, 'players', $players );

		// Remove match from player
		$player = new Player( $player_id );
		$match_id = get_post_meta( $this->team_id, 'match', true );
		$player->remove_match( $match_id );

		// Cancel pending reservations
		Reservation::cancelPlayerPendingTeamReservations( $player_id, $this->team_id );
	}

	/**
	 * Change quantity
	 */
	public function change_qty( $player_id, $qty ) {
		$players = get_post_meta( $this->team_id, 'players', true ) ?: [];

		$player_index = -1;

		foreach ($players as $i => $player) {
			if ($player['id'] == $player_id) {
				$player_index = $i;
				break;
			}
		}

		if ($player_index == -1) {
			throw new \Exception('Player not found');
		}

		$players[$player_index]['qty'] = $qty;
		update_post_meta( $this->team_id, 'players', $players );

	}

	/**
	 * Confirm player
	 */
	public function confirm_player( $player_id ) {
		$players = get_post_meta( $this->team_id, 'players', true ) ?: [];
		foreach ($players as $i => $player) {
			if ($player['id'] == $player_id) {
				$players[$i]['confirmed'] = true;
				update_post_meta( $this->team_id, 'players', $players );
			}
		}
	}
}
