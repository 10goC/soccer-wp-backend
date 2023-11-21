<?php
namespace Soccer;

class Player {

	protected $player_id;

	public function __construct( $player_id ) {
		$this->player_id = $player_id;
	}

	/**
	 * Add match
	 */
	public function add_match( $match_id ) {
		$match_ids = get_user_meta( $this->player_id, 'matches', true );
		if (!$match_ids) $match_ids = [];
		if (in_array($match_id, $match_ids)) return;
		$match_ids[] = $match_id;
		update_user_meta( $this->player_id, 'matches', $match_ids );
	}

	/**
	 * Remove match
	 */
	public function remove_match( $match_id ) {
		$match_ids = get_user_meta( $this->player_id, 'matches', true );
		if (!$match_ids) return;
		$match_index = array_search($match_id, $match_ids);
		if ($match_index === false) return;
		array_splice($match_ids, $match_index, 1);
		update_user_meta( $this->player_id, 'matches', $match_ids );
	}
}