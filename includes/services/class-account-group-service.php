<?php
/**
 * Account group CRUD service.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles user-owned account group records.
 */
final class Balance_Beacon_Account_Group_Service {
	/**
	 * Get all account groups for a user-owned book.
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
				"SELECT id, user_id, book_id, name, created_at, updated_at FROM {$tables['account_groups']} WHERE book_id = %d AND user_id = %d ORDER BY name ASC, id ASC",
				$book_id,
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'prepare_row' ), $rows ) : array();
	}

	/**
	 * Create an account group.
	 *
	 * @param array<string, mixed> $data Group data.
	 * @param int                  $user_id Current user ID.
	 * @return int
	 */
	public function create( array $data, $user_id ) {
		global $wpdb;

		$user_id = $this->assert_user( $user_id );
		$book_id = $this->assert_user_book( $data['book_id'] ?? 0, $user_id );
		$name    = $this->name( $data['name'] ?? '' );
		$now     = current_time( 'mysql' );
		$tables  = Balance_Beacon_Schema::get_table_names();

		$inserted = $wpdb->insert(
			$tables['account_groups'],
			array(
				'user_id'    => $user_id,
				'book_id'    => $book_id,
				'name'       => $name,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( '無法建立帳戶群組。' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an account group.
	 *
	 * @param int                  $id Group ID.
	 * @param array<string, mixed> $data Group data.
	 * @param int                  $user_id Current user ID.
	 * @return bool
	 */
	public function update( $id, array $data, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = $this->assert_user( $user_id );
		$tables  = Balance_Beacon_Schema::get_table_names();

		if ( ! $this->record_belongs_to_user( $id, $user_id ) ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$updated = $wpdb->update(
			$tables['account_groups'],
			array(
				'name'       => $this->name( $data['name'] ?? '' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( '無法更新帳戶群組。' );
		}

		return true;
	}

	/**
	 * Delete an account group.
	 *
	 * @param int $id Group ID.
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
				"SELECT 1 FROM {$tables['accounts']} WHERE group_id = %d AND user_id = %d LIMIT 1",
				$id,
				$user_id
			)
		);

		if ( $in_use ) {
			throw new RuntimeException( '此群組內仍有帳戶，無法刪除。' );
		}

		$deleted = $wpdb->delete(
			$tables['account_groups'],
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			throw new RuntimeException( '無法刪除帳戶群組。' );
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
			'name'       => (string) ( $row['name'] ?? '' ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
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
				"SELECT g.id FROM {$tables['account_groups']} g INNER JOIN {$tables['books']} b ON b.id = g.book_id WHERE g.id = %d AND g.user_id = %d AND b.user_id = %d",
				absint( $id ),
				absint( $user_id ),
				absint( $user_id )
			)
		);
	}

	/**
	 * Sanitize and validate a group name.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	private function name( $name ) {
		$name = sanitize_text_field( (string) $name );

		if ( '' === $name ) {
			throw new RuntimeException( '群組名稱為必填。' );
		}

		return $name;
	}
}
