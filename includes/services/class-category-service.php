<?php
/**
 * Category query and mutation service.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces category-related PostgreSQL RPC functions.
 */
final class Balance_Beacon_Category_Service {
	/**
	 * Returns categories with descendant transaction counts.
	 *
	 * Descendant totals are resolved in PHP instead of WITH RECURSIVE.
	 *
	 * @param int      $book_id Book ID.
	 * @param int|null $user_id Defaults to current user.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_categories_with_counts( $book_id, $user_id = null ) {
		global $wpdb;

		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$book_id = absint( $book_id );

		if ( ! $user_id || $user_id !== get_current_user_id() || ! $book_id ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		$this->assert_owned_book( $book_id, $user_id );

		$sql  = "SELECT c.id, c.created_at, c.updated_at, c.name, c.category_type AS type,
			c.parent_id, c.book_id, c.user_id, c.is_hidden, c.is_hidden AS hidden,
			COUNT(t.id) AS direct_count
			FROM {$tables['categories']} c
			LEFT JOIN {$tables['transactions']} t
				ON t.category_id = c.id AND t.book_id = c.book_id AND t.user_id = c.user_id
			WHERE c.book_id = %d AND c.user_id = %d
			GROUP BY c.id
			ORDER BY c.sort_order ASC, c.id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $book_id, $user_id ), ARRAY_A );

		$by_parent = array();
		$direct    = array();

		foreach ( $rows as $row ) {
			$parent = $row['parent_id'] ? (int) $row['parent_id'] : 0;
			$by_parent[ $parent ][] = (int) $row['id'];
			$direct[ (int) $row['id'] ] = (int) $row['direct_count'];
		}

		foreach ( $rows as &$row ) {
			$row['parent_id']         = $row['parent_id'] ? (int) $row['parent_id'] : null;
			$row['transaction_count'] = $this->descendant_count( (int) $row['id'], $by_parent, $direct, array() );
			unset( $row['direct_count'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Creates a category.
	 *
	 * @param array<string, mixed> $data Category payload.
	 * @param int                  $user_id Current user ID.
	 * @return int New category ID.
	 */
	public function create_category( array $data, $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$book_id = absint( isset( $data['book_id'] ) ? $data['book_id'] : 0 );
		$now     = current_time( 'mysql' );

		if ( ! $user_id || $user_id !== get_current_user_id() || ! $book_id ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		$this->assert_owned_book( $book_id, $user_id );

		$payload               = $this->sanitize_payload( $data, $book_id, $user_id );
		$payload['created_at'] = $now;
		$payload['updated_at'] = $now;

		$inserted = $wpdb->insert(
			$tables['categories'],
			$payload,
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Unable to create category.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a category.
	 *
	 * @param int                  $id Category ID.
	 * @param array<string, mixed> $data Category payload.
	 * @param int                  $user_id Current user ID.
	 * @return bool
	 */
	public function update_category( $id, array $data, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = absint( $user_id );
		$book_id = absint( isset( $data['book_id'] ) ? $data['book_id'] : 0 );

		if ( ! $user_id || $user_id !== get_current_user_id() || ! $id || ! $book_id ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		$this->assert_owned_book( $book_id, $user_id );
		$this->assert_owned_category( $id, $book_id, $user_id );

		$payload = $this->sanitize_payload( $data, $book_id, $user_id );
		unset( $payload['book_id'], $payload['user_id'] );
		if ( ! array_key_exists( 'is_hidden', $data ) ) {
			unset( $payload['is_hidden'] );
		}
		$payload['updated_at'] = current_time( 'mysql' );
		$formats = array( '%d', '%s', '%s' );
		if ( array_key_exists( 'is_hidden', $payload ) ) {
			$formats[] = '%d';
		}
		$formats[] = '%s';

		$updated = $wpdb->update(
			$tables['categories'],
			$payload,
			array(
				'id'      => $id,
				'book_id' => $book_id,
				'user_id' => $user_id,
			),
			$formats,
			array( '%d', '%d', '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Unable to update category.' );
		}

		return true;
	}

	/**
	 * Deletes a category.
	 *
	 * @param int $id Category ID.
	 * @param int $user_id Current user ID.
	 * @return bool
	 */
	public function delete_category( $id, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = absint( $user_id );

		if ( ! $user_id || $user_id !== get_current_user_id() || ! $id ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();

		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['categories']} WHERE id = %d AND user_id = %d", $id, $user_id ) ) ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$deleted = $wpdb->delete(
			$tables['categories'],
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			throw new RuntimeException( 'Unable to delete category.' );
		}

		return true;
	}

	/**
	 * Sanitizes and validates writable category fields.
	 *
	 * @param array<string, mixed> $data Raw payload.
	 * @param int                  $book_id Book ID.
	 * @param int                  $user_id User ID.
	 * @return array<string, mixed>
	 */
	private function sanitize_payload( array $data, $book_id, $user_id ) {
		$name      = sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' );
		$type      = sanitize_key( isset( $data['category_type'] ) ? $data['category_type'] : 'expense' );
		$parent_id = $this->optional_id( isset( $data['parent_id'] ) ? $data['parent_id'] : null );
		$is_hidden = rest_sanitize_boolean( isset( $data['is_hidden'] ) ? $data['is_hidden'] : 0 ) ? 1 : 0;

		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Category name is required.' );
		}

		if ( ! in_array( $type, array( 'expense', 'income' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid category type.' );
		}

		if ( $parent_id ) {
			$this->assert_owned_category( $parent_id, $book_id, $user_id );
		}

		return array(
			'user_id'       => absint( $user_id ),
			'book_id'       => absint( $book_id ),
			'parent_id'     => $parent_id,
			'name'          => $name,
			'category_type' => $type,
			'is_hidden'     => $is_hidden,
		);
	}

	/**
	 * Returns a nullable positive integer ID.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function optional_id( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = absint( $value );

		return $value ? $value : null;
	}

	/**
	 * Ensures the book belongs to the current user.
	 *
	 * @param int $book_id Book ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function assert_owned_book( $book_id, $user_id ) {
		global $wpdb;

		$tables = Balance_Beacon_Schema::get_table_names();
		$owned  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['books']} WHERE id = %d AND user_id = %d",
				absint( $book_id ),
				absint( $user_id )
			)
		);

		if ( ! $owned ) {
			throw new RuntimeException( 'Access denied.' );
		}
	}

	/**
	 * Ensures the category belongs to the current user and book.
	 *
	 * @param int $category_id Category ID.
	 * @param int $book_id Book ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function assert_owned_category( $category_id, $book_id, $user_id ) {
		global $wpdb;

		$tables = Balance_Beacon_Schema::get_table_names();
		$owned  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['categories']} WHERE id = %d AND book_id = %d AND user_id = %d",
				absint( $category_id ),
				absint( $book_id ),
				absint( $user_id )
			)
		);

		if ( ! $owned ) {
			throw new RuntimeException( 'Access denied.' );
		}
	}

	/**
	 * Sums direct counts for a category and all descendants, with cycle protection.
	 *
	 * @param int              $id Category ID.
	 * @param array<int,array> $children Children keyed by parent ID.
	 * @param array<int,int>   $direct Direct transaction counts.
	 * @param array<int,bool>  $visited Visited category IDs.
	 * @return int
	 */
	private function descendant_count( $id, array $children, array $direct, array $visited ) {
		if ( isset( $visited[ $id ] ) ) {
			return 0;
		}

		$visited[ $id ] = true;
		$total          = isset( $direct[ $id ] ) ? $direct[ $id ] : 0;

		foreach ( isset( $children[ $id ] ) ? $children[ $id ] : array() as $child_id ) {
			$total += $this->descendant_count( $child_id, $children, $direct, $visited );
		}

		return $total;
	}
}
