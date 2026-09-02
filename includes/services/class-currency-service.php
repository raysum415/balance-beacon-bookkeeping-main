<?php
/**
 * Currency CRUD service.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles user-owned currency records.
 */
final class Balance_Beacon_Currency_Service {
	/**
	 * Get all currencies for a user-owned book.
	 *
	 * @param int $book_id Book ID.
	 * @param int $user_id Current user ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all( $book_id, $user_id ) {
		global $wpdb;

		$user_id = $this->assert_user( $user_id );
		$book_id = $this->assert_user_book( $book_id, $user_id );
		$tables  = Balance_Beacon_Schema::get_table_names();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, book_id, code, name, symbol, decimals, created_at, updated_at FROM {$tables['currencies']} WHERE book_id = %d AND user_id = %d ORDER BY code ASC, id ASC",
				$book_id,
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'prepare_row' ), $rows ) : array();
	}

	/**
	 * Create a currency.
	 *
	 * @param array<string, mixed> $data Currency data.
	 * @param int                  $user_id Current user ID.
	 * @return int
	 */
	public function create( array $data, $user_id ) {
		global $wpdb;

		$user_id = $this->assert_user( $user_id );
		$book_id = $this->assert_user_book( $data['book_id'] ?? 0, $user_id );
		$payload = $this->payload( $data );
		$now     = current_time( 'mysql' );
		$tables  = Balance_Beacon_Schema::get_table_names();

		$inserted = $wpdb->insert(
			$tables['currencies'],
			array(
				'user_id'    => $user_id,
				'book_id'    => $book_id,
				'code'       => $payload['code'],
				'name'       => $payload['name'],
				'symbol'     => $payload['symbol'],
				'decimals'   => $payload['decimals'],
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( '無法建立幣別。' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a currency.
	 *
	 * @param int                  $id Currency ID.
	 * @param array<string, mixed> $data Currency data.
	 * @param int                  $user_id Current user ID.
	 * @return bool
	 */
	public function update( $id, array $data, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = $this->assert_user( $user_id );
		$payload = $this->payload( $data );
		$tables  = Balance_Beacon_Schema::get_table_names();

		if ( ! $this->record_belongs_to_user( $id, $user_id ) ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$updated = $wpdb->update(
			$tables['currencies'],
			array(
				'code'       => $payload['code'],
				'name'       => $payload['name'],
				'symbol'     => $payload['symbol'],
				'decimals'   => $payload['decimals'],
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( '無法更新幣別。' );
		}

		return true;
	}

	/**
	 * Delete a currency.
	 *
	 * @param int $id Currency ID.
	 * @param int $user_id Current user ID.
	 * @return bool
	 */
	public function delete( $id, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = $this->assert_user( $user_id );
		$tables  = Balance_Beacon_Schema::get_table_names();

		if ( ! $this->record_belongs_to_user( $id, $user_id ) ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$in_use = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$tables['accounts']} WHERE currency_id = %d AND user_id = %d LIMIT 1",
				$id,
				$user_id
			)
		);

		if ( $in_use ) {
			throw new RuntimeException( '此幣別已被帳戶使用，無法刪除。' );
		}

		$deleted = $wpdb->delete(
			$tables['currencies'],
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			throw new RuntimeException( '無法刪除幣別。' );
		}

		return true;
	}

	/**
	 * Normalize a database row for REST output.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function prepare_row( $row ) {
		return array(
			'id'         => absint( $row['id'] ?? 0 ),
			'user_id'    => absint( $row['user_id'] ?? 0 ),
			'book_id'    => absint( $row['book_id'] ?? 0 ),
			'code'       => (string) ( $row['code'] ?? '' ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'symbol'     => (string) ( $row['symbol'] ?? '' ),
			'decimals'   => absint( $row['decimals'] ?? 2 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Normalize currency write payload.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>
	 */
	private function payload( array $data ) {
		$code = strtoupper( sanitize_text_field( (string) ( $data['code'] ?? '' ) ) );
		$code = substr( preg_replace( '/[^A-Z0-9]/', '', $code ), 0, 10 );

		if ( '' === $code ) {
			throw new RuntimeException( '幣別代碼為必填。' );
		}

		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			throw new RuntimeException( '幣別名稱為必填。' );
		}

		$decimals = absint( $data['decimals'] ?? 2 );
		if ( $decimals > 8 ) {
			throw new RuntimeException( '小數位數不可大於 8。' );
		}

		return array(
			'code'     => $code,
			'name'     => $name,
			'symbol'   => sanitize_text_field( (string) ( $data['symbol'] ?? '' ) ),
			'decimals' => $decimals,
		);
	}

	/**
	 * Assert the current user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function assert_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		return $user_id;
	}

	/**
	 * Assert book ownership.
	 *
	 * @param int $book_id Book ID.
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function assert_user_book( $book_id, $user_id ) {
		global $wpdb;

		$user_id = $this->assert_user( $user_id );
		$book_id = absint( $book_id );

		if ( ! $book_id ) {
			throw new RuntimeException( 'Missing book_id.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		$owned  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['books']} WHERE id = %d AND user_id = %d",
				$book_id,
				$user_id
			)
		);

		if ( ! $owned ) {
			throw new RuntimeException( 'Access denied.' );
		}

		return (int) $owned;
	}

	/**
	 * Check record ownership.
	 *
	 * @param int $id Record ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function record_belongs_to_user( $id, $user_id ) {
		global $wpdb;

		if ( ! $id ) {
			return false;
		}

		$tables = Balance_Beacon_Schema::get_table_names();

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT c.id FROM {$tables['currencies']} c INNER JOIN {$tables['books']} b ON b.id = c.book_id WHERE c.id = %d AND c.user_id = %d AND b.user_id = %d",
				absint( $id ),
				absint( $user_id ),
				absint( $user_id )
			)
		);
	}
}
