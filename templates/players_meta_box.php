<?php $teams = get_post_meta($post->ID, 'teams', true) ?>
<div class="teams">
	<?php foreach ($teams as $team_id) {
		$team = get_post($team_id);
		$players = get_post_meta($team->ID, 'players', true); ?>
		<div class="team" data-id="<?php echo $team->ID ?>">
			<h3 class="title"><?php echo str_replace(' | ' . $post->post_title, '', $team->post_title) ?></h3>
			<ul>
				<?php foreach ($players as $player) {
					$user = get_user_by('ID', $player['id']);
					$username = $user->display_name;
					$phone = get_user_meta($user->ID, 'phone', true);
					if ($phone) $username .= " ($phone)" ?>
					<li class="player" data-id="<?php echo $player['id'] ?>">
						<span class="player-info">
							<span class="player-name"><?php echo $username; ?></span>
							<span class="badge player-count">
								<?php echo 'x' . ($player['qty'] ?? 1) ?>
							</span>
							<button type="button" class="button button-small button-secondary btn-increase-qty">+</button>
							<button type="button" class="button button-small button-secondary btn-decrease-qty">-</button>
						</span>
						<span class="dashicons dashicons-no btn-remove-player"></span>
					</li>
				<?php } ?>
			</ul>
			<input type="text" class="player-search">
			<button type="button" class="button button-primary btn-add-player">Add player</button>
		</div>
	<?php } ?>
</div>

<script>(function($) {
	const lock = (el) => {
		el.css({
			opacity: .5,
			pointerEvents: 'none'
		});
	};

	const unlock = (el) => {
		el.css({
			opacity: 1,
			pointerEvents: 'all'
		});
	};

	const bindRemovePlayerBtn = (el) => {
		el.click(function() {
			const btn = $(this);
			const box = btn.closest('.team');
			const row = btn.closest('li');
			const playerId = row.data('id');
			const teamId = box.data('id');
			const playerName = row.find('.player-name').text().trim().replace(/ \([^)]*\)$/, '');
			box.find('.notice').remove();
			lock(row);
			if (!confirm(`Are you sure you want to remove ${ playerName } from this match?`)) {
				return unlock(row);
			}
			$.post( ajaxurl, {
				action: 'remove_player',
				team: teamId,
				player: playerId
			}, ({ success, message }) => {
				unlock(row);
				if (success) {
					row.remove();
				} else {
					box.append(`<div class="error notice"><p>${ message ?? 'Error' }</p></div>`);
				}
			}, 'json')
			.fail((e) => {
				unlock(row);
				box.append(`<div class="error notice"><p>${ e.statusText }</p></div>`);
			});
		});
	};

	const bindQtyBtn = (el) => {
		el.click(function() {
			const btn = $(this);
			const box = btn.closest('.team');
			const row = btn.closest('li');
			const playerId = row.data('id');
			const teamId = box.data('id');
			const playerName = row.text().trim().replace(/ \([^)]*\)$/, '');
			const increase = btn.hasClass('btn-increase-qty');
			const badge = row.find('.badge');
			let qty = parseInt(badge.text().replace(/[^0-9]/g, ''));
			if (isNaN(qty)) qty = 1;
			if (qty === 1 && !increase) return;
			qty = increase ? qty + 1 : qty - 1;
			box.find('.notice').remove();
			lock(row);
			$.post( ajaxurl, {
				action: 'change_qty',
				team: teamId,
				player: playerId,
				qty
			}, ({ success, message }) => {
				unlock(row);
				if (success) {
					badge.text(`x${ qty }`);
				} else {
					box.append(`<div class="error notice"><p>${ message ?? 'Error' }</p></div>`);
				}
			}, 'json')
			.fail((e) => {
				unlock(row);
				box.append(`<div class="error notice"><p>${ e.statusText }</p></div>`);
			});
		});
	};

	$(document).ready(() => {
		$('.player-search').autocomplete({
			source: ajaxurl + '?action=user_search',
			select: (e, ui) => {
				$(e.target).data('id', ui.item.id);
				$(e.target).data('val', ui.item.label);
			}
		});
		$('.btn-add-player').click(function() {
			const btn = $(this);
			const box = btn.closest('.team');
			const list = box.find('ul');
			const input = btn.prev();
			const teamId = box.data('id');
			const playerId = input.data('id');
			box.find('.notice').remove();
			lock(btn);
			$.post( ajaxurl, {
				action: 'add_player',
				team: teamId,
				player: playerId
			}, ({ success, message }) => {
				unlock(btn);
				if (success) {
					const li = $(`<li class="player" data-id="${ input.data('id') }"></li>`);
					const playerInfo = $(`<span class="player-info"><span class="player-name">${ input.data('val') }</span></span>`);
					const badge = $('<span class="badge player-count">x1</span>');
					const removeBtn = $('<span class="dashicons dashicons-no btn-remove-player"></span>');
					const increaseBtn = $('<button type="button" class="button button-small button-secondary btn-increase-qty">+</button>');
					const decreaseBtn = $('<button type="button" class="button button-small button-secondary btn-decrease-qty">-</button>');
					bindRemovePlayerBtn(removeBtn);
					bindQtyBtn(increaseBtn);
					bindQtyBtn(decreaseBtn);
					list.append(li);
					li.append(playerInfo);
					playerInfo.append(badge);
					playerInfo.append(increaseBtn);
					playerInfo.append(decreaseBtn);
					li.append(removeBtn);
				} else {
					box.append(`<div class="error notice"><p>${ message ?? 'Error' }</p></div>`);
				}
			}, 'json')
			.fail((e) => {
				unlock(btn);
				box.append(`<div class="error notice"><p>${ e.statusText }</p></div>`);
			});
			input.val('');
		});

		bindRemovePlayerBtn($('.btn-remove-player'));
		bindQtyBtn($('.btn-increase-qty'));
		bindQtyBtn($('.btn-decrease-qty'));
	});
})(jQuery);</script>