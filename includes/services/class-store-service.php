<?php
/**
 * Store CRUD service.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles user-owned store records.
 */
final class Balance_Beacon_Store_Service {
	public function get_all( $book_id, $user_id ) {
		global $wpdb;

		$book_id = $this->assert_user_book( $book_id, $user_id );
		$tables  = Balance_Beacon_Schema::get_table_names();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, book_id, name, is_hidden, created_at, updated_at FROM {$tables['stores']} WHERE book_id = %d AND user_id = %d ORDER BY name ASC, id ASC",
				$book_id,
				absint( $user_id )
			),
			ARRAY_A
		);
	}

	public function create( array $data, $user_id ) {
		global $wpdb;

		$user_id = $this->assert_user( $user_id );
		$book_id = $this->assert_user_book( isset( $data['book_id'] ) ? $data['book_id'] : 0, $user_id );
		$name    = $this->name( isset( $data['name'] ) ? $data['name'] : '' );
		$is_hidden = rest_sanitize_boolean( isset( $data['is_hidden'] ) ? $data['is_hidden'] : 0 ) ? 1 : 0;
		$now     = current_time( 'mysql' );
		$tables  = Balance_Beacon_Schema::get_table_names();

		$inserted = $wpdb->insert(
			$tables['stores'],
			array(
				'user_id'    => $user_id,
				'book_id'    => $book_id,
				'name'       => $name,
				'is_hidden'  => $is_hidden,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Unable to create store.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function update( $id, array $data, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = $this->assert_user( $user_id );
		$name    = $this->name( isset( $data['name'] ) ? $data['name'] : '' );
		$tables  = Balance_Beacon_Schema::get_table_names();

		if ( ! $this->record_belongs_to_user( $id, $user_id ) ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$row = array( 'name' => $name, 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s', '%s' );
		if ( array_key_exists( 'is_hidden', $data ) ) { $row['is_hidden'] = rest_sanitize_boolean( $data['is_hidden'] ) ? 1 : 0; $formats = array( '%s', '%s', '%d' ); }
		$updated = $wpdb->update(
			$tables['stores'],
			$row,
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			$formats,
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Unable to update store.' );
		}

		return true;
	}

	public function delete( $id, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = $this->assert_user( $user_id );
		$tables  = Balance_Beacon_Schema::get_table_names();

		if ( ! $this->record_belongs_to_user( $id, $user_id ) ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$in_use = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$tables['transactions']} WHERE store_id = %d AND user_id = %d LIMIT 1", $id, $user_id ) );
		if ( $in_use ) {
			throw new Exception( '此資料已被交易紀錄使用，無法刪除。' );
		}

		$deleted = $wpdb->delete(
			$tables['stores'],
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			throw new RuntimeException( 'Unable to delete store.' );
		}

		return true;
	}

	private function assert_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		return $user_id;
	}

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

	private function record_belongs_to_user( $id, $user_id ) {
		global $wpdb;

		if ( ! $id ) {
			return false;
		}

		$tables = Balance_Beacon_Schema::get_table_names();

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT s.id FROM {$tables['stores']} s INNER JOIN {$tables['books']} b ON b.id = s.book_id WHERE s.id = %d AND s.user_id = %d AND b.user_id = %d",
				$id,
				$user_id,
				$user_id
			)
		);
	}

	private function name( $name ) {
		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			throw new RuntimeException( 'Name is required.' );
		}

		return $name;
	}
}
