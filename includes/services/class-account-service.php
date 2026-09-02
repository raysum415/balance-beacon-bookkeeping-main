<?php
/**
 * Account service.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/** Handles account CRUD and account balance queries. */
final class Balance_Beacon_Account_Service {
	/**
	 * Create an account owned by the current user.
	 *
	 * @param array<string, mixed> $data Account data.
	 * @param int                  $user_id Current WordPress user ID.
	 * @return int
	 */
	public function create_account( $data, $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$payload = $this->normalize_payload( $data, true );
		if ( ! $payload['book_id'] || ! $this->user_owns_book( $payload['book_id'], $user_id ) ) {
			throw new RuntimeException( 'Unable to use this book.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		$now    = current_time( 'mysql' );
		$row    = array(
			'user_id'         => $user_id,
			'book_id'         => $payload['book_id'],
			'name'            => $payload['name'],
			'initial_balance' => $payload['initial_balance'],
			'currency'        => $payload['currency'],
			'account_group'   => $payload['account_group'],
			'group_id'        => $payload['group_id'],
			'currency_id'     => $payload['currency_id'],
			'is_hidden'       => $payload['is_hidden'],
			'is_active'       => $payload['is_active'],
			'default_confirmed' => $payload['default_confirmed'],
			'statement_date'  => $payload['statement_date'],
			'payment_date'    => $payload['payment_date'],
			'account_type'    => $payload['account_type'],
			'currency_code'   => $payload['currency'],
			'opening_balance' => $payload['initial_balance'],
			'current_balance' => $payload['initial_balance'],
			'hidden'          => $payload['is_hidden'],
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		$inserted = $wpdb->insert(
			$tables['accounts'],
			$row,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Unable to create account.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an account owned by the current user.
	 *
	 * @param int                  $id Account ID.
	 * @param array<string, mixed> $data Account data.
	 * @param int                  $user_id Current WordPress user ID.
	 * @return bool
	 */
	public function update_account( $id, $data, $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$id      = absint( $id );
		if ( ! $user_id || $user_id !== get_current_user_id() || ! $id ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables  = Balance_Beacon_Schema::get_table_names();
		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['accounts']} WHERE id = %d AND user_id = %d",
				$id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! $current ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$payload = $this->normalize_payload( $data, false );
		$row     = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( array_key_exists( 'name', $payload ) ) {
			$row['name'] = $payload['name'];
			$formats[]   = '%s';
		}

		if ( array_key_exists( 'account_type', $payload ) ) {
			$row['account_type'] = $payload['account_type'];
			$formats[]           = '%s';
		}

		if ( array_key_exists( 'initial_balance', $payload ) ) {
			$row['initial_balance'] = $payload['initial_balance'];
			$row['opening_balance'] = $payload['initial_balance'];
			$formats[]              = '%s';
			$formats[]              = '%s';
		}

		if ( array_key_exists( 'currency', $payload ) ) {
			$row['currency']      = $payload['currency'];
			$row['currency_code'] = $payload['currency'];
			$formats[]            = '%s';
			$formats[]            = '%s';
		}

		if ( array_key_exists( 'account_group', $payload ) ) {
			$row['account_group'] = $payload['account_group'];
			$formats[]            = '%s';
		}

		if ( array_key_exists( 'group_id', $payload ) ) {
			$row['group_id'] = $payload['group_id'];
			$formats[]       = '%d';
		}

		if ( array_key_exists( 'currency_id', $payload ) ) {
			$row['currency_id'] = $payload['currency_id'];
			$formats[]          = '%d';
		}

		if ( array_key_exists( 'is_hidden', $payload ) ) {
			$row['is_hidden'] = $payload['is_hidden'];
			$row['hidden']    = $payload['is_hidden'];
			$formats[]        = '%d';
			$formats[]        = '%d';
		}

		if ( array_key_exists( 'is_active', $payload ) ) {
			$row['is_active'] = $payload['is_active'];
			$formats[]        = '%d';
		}

		if ( array_key_exists( 'default_confirmed', $payload ) ) {
			$row['default_confirmed'] = $payload['default_confirmed'];
			$formats[]                = '%d';
		}

		if ( array_key_exists( 'statement_date', $payload ) ) {
			$row['statement_date'] = $payload['statement_date'];
			$formats[]             = '%d';
		}

		if ( array_key_exists( 'payment_date', $payload ) ) {
			$row['payment_date'] = $payload['payment_date'];
			$formats[]           = '%d';
		}

		if ( 1 === count( $row ) ) {
			throw new RuntimeException( 'No fields to update.' );
		}

		$updated = $wpdb->update(
			$tables['accounts'],
			$row,
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			$formats,
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Unable to update account.' );
		}

		return true;
	}

	/**
	 * Delete an account owned by the current user.
	 *
	 * @param int $id Account ID.
	 * @param int $user_id Current WordPress user ID.
	 * @return bool
	 */
	public function delete_account( $id, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = absint( $user_id );
		if ( ! $user_id || $user_id !== get_current_user_id() || ! $id ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['accounts']} WHERE id = %d AND user_id = %d", $id, $user_id ) ) ) {
			throw new RuntimeException( 'Access denied.' );
		}

		if ( false === $wpdb->delete( $tables['accounts'], array( 'id' => $id, 'user_id' => $user_id ), array( '%d', '%d' ) ) ) {
			throw new RuntimeException( 'Unable to delete account.' );
		}

		return true;
	}

	/**
	 * Replacement for get_accounts_with_balance.
	 *
	 * @param string|int $group_id all, unclassified, or a numeric group ID.
	 * @param int|null   $user_id Defaults to the signed-in WordPress user.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_accounts_with_balance( $group_id = 'all', $user_id = null ) {
		global $wpdb;

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		$where  = 'a.user_id = %d';
		$params = array( $user_id );

		if ( 'unclassified' === (string) $group_id ) {
			$where .= ' AND a.group_id IS NULL';
		} elseif ( 'all' !== (string) $group_id ) {
			$where    .= ' AND a.group_id = %d';
			$params[] = absint( $group_id );
		}

		$sql = "SELECT a.id, a.created_at, a.updated_at, a.book_id, a.name,
			a.account_type, a.account_type AS type,
			COALESCE(a.initial_balance, a.opening_balance) AS initial_balance,
			COALESCE(a.currency, a.currency_code) AS currency,
			a.currency_code,
			COALESCE(a.account_group, 'cash') AS account_group,
			COALESCE(a.is_hidden, a.hidden) AS is_hidden,
			a.statement_date, a.payment_date,
			a.is_active, a.default_confirmed, a.hidden, a.user_id, a.group_id, a.currency_id,
			COALESCE(a.initial_balance, a.opening_balance) + COALESCE(SUM(CASE
				WHEN t.transaction_type = 'income' AND t.account_id = a.id THEN t.amount
				WHEN t.transaction_type = 'expense' AND t.account_id = a.id THEN -t.amount
				WHEN t.transaction_type = 'transfer' AND t.account_id = a.id THEN -t.amount
				WHEN t.transaction_type = 'transfer' AND t.transfer_account_id = a.id THEN COALESCE(t.to_amount, t.amount)
				ELSE 0 END), 0) AS current_balance,
			COUNT(t.id) AS transaction_count
			FROM {$tables['accounts']} a
			LEFT JOIN {$tables['transactions']} t
				ON (t.account_id = a.id OR t.transfer_account_id = a.id) AND t.user_id = a.user_id
			WHERE {$where}
			GROUP BY a.id
			ORDER BY a.sort_order ASC, a.id ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'prepare_account_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Normalize an account row for REST output.
	 *
	 * @param array<string, mixed> $row Account row.
	 * @return array<string, mixed>
	 */
	private function prepare_account_row( $row ) {
		$row['id']                = absint( $row['id'] );
		$row['book_id']           = absint( $row['book_id'] );
		$row['user_id']           = absint( $row['user_id'] );
		$row['group_id']          = empty( $row['group_id'] ) ? null : absint( $row['group_id'] );
		$row['currency_id']       = empty( $row['currency_id'] ) ? null : absint( $row['currency_id'] );
		$row['is_hidden']         = absint( $row['is_hidden'] );
		$row['hidden']            = absint( $row['hidden'] );
		$row['is_active']         = absint( $row['is_active'] );
		$row['default_confirmed'] = absint( $row['default_confirmed'] );
		$row['transaction_count'] = absint( $row['transaction_count'] );
		$row['initial_balance']   = $this->decimal_string( $row['initial_balance'] );
		$row['current_balance']   = $this->decimal_string( $row['current_balance'] );
		$row['statement_date']    = empty( $row['statement_date'] ) ? null : absint( $row['statement_date'] );
		$row['payment_date']      = empty( $row['payment_date'] ) ? null : absint( $row['payment_date'] );

		return $row;
	}

	/**
	 * Normalize create/update payloads.
	 *
	 * @param array<string, mixed> $data Raw payload.
	 * @param bool                 $for_create Whether defaults should be filled.
	 * @return array<string, mixed>
	 */
	private function normalize_payload( $data, $for_create ) {
		$payload = array();

		if ( $for_create || array_key_exists( 'book_id', $data ) ) {
			$payload['book_id'] = absint( $data['book_id'] ?? 0 );
		}

		if ( $for_create || array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
			if ( '' === $name ) {
				throw new RuntimeException( 'Account name is required.' );
			}
			$payload['name'] = $name;
		}

		if ( $for_create || array_key_exists( 'account_type', $data ) ) {
			$payload['account_type'] = sanitize_key( (string) ( $data['account_type'] ?? 'asset' ) );
			if ( '' === $payload['account_type'] ) {
				$payload['account_type'] = 'asset';
			}
		}

		if ( $for_create || array_key_exists( 'initial_balance', $data ) ) {
			$payload['initial_balance'] = $this->decimal_string( $data['initial_balance'] ?? '0.0000' );
		}

		if ( $for_create || array_key_exists( 'currency', $data ) || array_key_exists( 'currency_code', $data ) ) {
			$currency = $data['currency'] ?? ( $data['currency_code'] ?? 'TWD' );
			$currency = strtoupper( sanitize_text_field( (string) $currency ) );
			$payload['currency'] = '' === $currency ? 'TWD' : substr( $currency, 0, 10 );
		}

		if ( $for_create || array_key_exists( 'account_group', $data ) ) {
			$account_group = sanitize_text_field( (string) ( $data['account_group'] ?? 'cash' ) );
			$payload['account_group'] = '' === $account_group ? 'cash' : substr( $account_group, 0, 50 );
		}

		if ( $for_create || array_key_exists( 'group_id', $data ) ) {
			$payload['group_id'] = empty( $data['group_id'] ) ? null : absint( $data['group_id'] );
		}

		if ( $for_create || array_key_exists( 'currency_id', $data ) ) {
			$payload['currency_id'] = empty( $data['currency_id'] ) ? null : absint( $data['currency_id'] );
		}

		if ( $for_create || array_key_exists( 'is_hidden', $data ) || array_key_exists( 'hidden', $data ) ) {
			$is_hidden = $data['is_hidden'] ?? ( $data['hidden'] ?? 0 );
			$payload['is_hidden'] = rest_sanitize_boolean( $is_hidden ) ? 1 : 0;
		}

		if ( $for_create || array_key_exists( 'is_active', $data ) ) {
			$payload['is_active'] = rest_sanitize_boolean( $data['is_active'] ?? 1 ) ? 1 : 0;
		}

		if ( $for_create || array_key_exists( 'default_confirmed', $data ) ) {
			$payload['default_confirmed'] = rest_sanitize_boolean( $data['default_confirmed'] ?? 0 ) ? 1 : 0;
		}

		if ( $for_create || array_key_exists( 'statement_date', $data ) ) {
			$payload['statement_date'] = $this->billing_day( $data['statement_date'] ?? null, 'statement_date' );
		}

		if ( $for_create || array_key_exists( 'payment_date', $data ) ) {
			$payload['payment_date'] = $this->billing_day( $data['payment_date'] ?? null, 'payment_date' );
		}

		return $payload;
	}

	/**
	 * Normalize a credit-card billing day.
	 *
	 * @param mixed  $value Raw day value.
	 * @param string $field Field name for errors.
	 * @return int|null
	 */
	private function billing_day( $value, $field ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$day = absint( $value );
		if ( $day < 1 || $day > 31 ) {
			throw new InvalidArgumentException( "{$field} must be between 1 and 31." );
		}

		return $day;
	}

	/**
	 * Check that a book belongs to a user.
	 *
	 * @param int $book_id Book ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function user_owns_book( $book_id, $user_id ) {
		global $wpdb;

		$tables = Balance_Beacon_Schema::get_table_names();

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['books']} WHERE id = %d AND user_id = %d",
				absint( $book_id ),
				absint( $user_id )
			)
		);
	}

	/**
	 * Return a DECIMAL-safe string.
	 *
	 * @param mixed $value Raw decimal value.
	 * @return string
	 */
	private function decimal_string( $value ) {
		$value = trim( preg_replace( '/[^0-9.\-]/', '', (string) $value ) );
		if ( '' === $value || '-' === $value || '.' === $value || '-.' === $value ) {
			return '0.0000';
		}

		$is_negative = '-' === $value[0];
		$value       = ltrim( $value, '-' );
		$parts       = explode( '.', $value, 2 );
		$integer     = preg_replace( '/\D/', '', $parts[0] );
		$fraction    = isset( $parts[1] ) ? preg_replace( '/\D/', '', $parts[1] ) : '';
		$integer     = '' === $integer ? '0' : ltrim( $integer, '0' );
		$integer     = '' === $integer ? '0' : $integer;
		$fraction    = str_pad( substr( $fraction, 0, 4 ), 4, '0' );

		return ( $is_negative ? '-' : '' ) . $integer . '.' . $fraction;
	}
}
