<?php
/**
 * Transaction write and list service.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles transaction mutations and list queries for the REST API.
 */
final class Balance_Beacon_Transaction_Service {
	/**
	 * Gets user-owned transactions with optional SQL-level filters.
	 *
	 * @param int                  $user_id Current WordPress user ID.
	 * @param int                  $book_id Book ID.
	 * @param array<string, mixed> $args    Optional filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_transactions( $user_id, $book_id, array $args = array() ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$book_id = absint( $book_id );

		if ( ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		if ( ! $book_id ) {
			throw new InvalidArgumentException( 'book_id is required.' );
		}

		$this->assert_owned_book( $book_id, $user_id );

		$tables = Balance_Beacon_Schema::get_table_names();
		$where  = array(
			$wpdb->prepare( 't.user_id = %d', $user_id ),
			$wpdb->prepare( 't.book_id = %d', $book_id ),
		);

		$start_date = $this->optional_filter_date( isset( $args['start_date'] ) ? $args['start_date'] : ( isset( $args['date_from'] ) ? $args['date_from'] : null ) );
		$end_date   = $this->optional_filter_date( isset( $args['end_date'] ) ? $args['end_date'] : ( isset( $args['date_to'] ) ? $args['date_to'] : null ) );

		if ( null !== $start_date ) {
			$where[] = $wpdb->prepare( 't.transaction_date >= %s', $start_date );
		}

		if ( null !== $end_date ) {
			$where[] = $wpdb->prepare( 't.transaction_date <= %s', $end_date );
		}

		$account_id = $this->optional_id( $args, 'account_id' );
		if ( $account_id ) {
			$where[] = $wpdb->prepare( '(t.account_id = %d OR t.transfer_account_id = %d)', $account_id, $account_id );
		}

		$category_id = $this->optional_id( $args, 'category_id' );
		if ( $category_id ) {
			$where[] = $wpdb->prepare( 't.category_id = %d', $category_id );
		}

		$store_id = $this->optional_id( $args, 'store_id' );
		if ( $store_id ) {
			$where[] = $wpdb->prepare( 't.store_id = %d', $store_id );
		}

		$tag_ids = $this->id_list( isset( $args['tags'] ) ? $args['tags'] : ( isset( $args['tag_ids'] ) ? $args['tag_ids'] : array() ) );
		$tag_id  = $this->optional_id( $args, 'tag_id' );
		if ( $tag_id ) {
			$tag_ids[] = $tag_id;
		}
		$tag_ids = array_values( array_unique( array_filter( array_map( 'absint', $tag_ids ) ) ) );
		if ( ! empty( $tag_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $tag_ids ), '%d' ) );
			$where[]      = $wpdb->prepare(
				"EXISTS (
					SELECT 1 FROM {$tables['transaction_tags']} tt_filter
					WHERE tt_filter.transaction_id = t.id
					AND tt_filter.tag_id IN ({$placeholders})
				)",
				$tag_ids
			);
		}

		$member_ids = $this->id_list( isset( $args['members'] ) ? $args['members'] : array() );
		$member_id  = $this->optional_id( $args, 'member_id' );
		if ( $member_id ) {
			$member_ids[] = $member_id;
		}
		$member_ids = array_values( array_unique( array_filter( array_map( 'absint', $member_ids ) ) ) );
		if ( ! empty( $member_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $member_ids ), '%d' ) );
			$where[]      = $wpdb->prepare(
				"EXISTS (
					SELECT 1 FROM {$tables['transaction_members']} tm_filter
					WHERE tm_filter.transaction_id = t.id
					AND tm_filter.member_id IN ({$placeholders})
				)",
				$member_ids
			);
		}

		if ( array_key_exists( 'is_reconciled', $args ) && '' !== $args['is_reconciled'] && null !== $args['is_reconciled'] ) {
			$where[] = $wpdb->prepare( 't.is_reconciled = %d', rest_sanitize_boolean( $args['is_reconciled'] ) ? 1 : 0 );
		}

		$page   = max( 1, isset( $args['page'] ) ? absint( $args['page'] ) : 1 );
		$limit  = max( 1, min( 500, isset( $args['limit'] ) ? absint( $args['limit'] ) : 100 ) );
		$offset = ( $page - 1 ) * $limit;

		$sql = "SELECT
				t.*,
				b.name AS book_name,
				c.name AS category_name,
				a.name AS account_name,
				a.currency_code AS account_currency,
				ta.name AS target_account_name,
				ta.currency_code AS target_account_currency,
				COALESCE(s.name, t.store_name) AS resolved_store_name
			FROM {$tables['transactions']} t
			LEFT JOIN {$tables['books']} b ON b.id = t.book_id AND b.user_id = t.user_id
			LEFT JOIN {$tables['categories']} c ON c.id = t.category_id AND c.user_id = t.user_id
			LEFT JOIN {$tables['accounts']} a ON a.id = t.account_id AND a.user_id = t.user_id
			LEFT JOIN {$tables['accounts']} ta ON ta.id = t.transfer_account_id AND ta.user_id = t.user_id
			LEFT JOIN {$tables['stores']} s ON s.id = t.store_id AND s.user_id = t.user_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY t.transaction_date DESC, GREATEST(t.created_at, t.updated_at) DESC, t.id DESC
			LIMIT %d OFFSET %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = is_array( $rows ) ? $rows : array();

		$this->attach_many_to_many( $rows, $user_id );

		return array_map( array( $this, 'normalize_transaction_row' ), $rows );
	}

	/**
	 * Creates a user-owned transaction.
	 *
	 * @param array<string, mixed> $data    Sanitized transaction data.
	 * @param int                  $user_id Current WordPress user ID.
	 * @return int Newly created transaction ID.
	 */
	public function create_transaction( array $data, $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$book_id             = $this->required_id( $data, 'book_id' );
		$account_id          = $this->required_id( $data, 'account_id' );
		$category_id         = $this->optional_id( $data, 'category_id' );
		$transfer_account_id = $this->optional_id( $data, 'transfer_account_id' );
		$type                = $this->transaction_type( $data );
		$amount              = $this->decimal( isset( $data['amount'] ) ? $data['amount'] : null );
		$date                = $this->date( isset( $data['transaction_date'] ) ? $data['transaction_date'] : ( isset( $data['date'] ) ? $data['date'] : null ) );
		$description         = isset( $data['description'] ) ? (string) $data['description'] : ( isset( $data['notes'] ) ? (string) $data['notes'] : '' );
		$tag_ids             = $this->id_list( isset( $data['tags'] ) ? $data['tags'] : array() );
		$member_ids          = $this->id_list( isset( $data['members'] ) ? $data['members'] : array() );

		if ( empty( $tag_ids ) && $this->optional_id( $data, 'tag_id' ) ) {
			$tag_ids = array( $this->optional_id( $data, 'tag_id' ) );
		}

		if ( empty( $member_ids ) && $this->optional_id( $data, 'member_id' ) ) {
			$member_ids = array( $this->optional_id( $data, 'member_id' ) );
		}

		$account = $this->assert_owned_account( $account_id, $book_id, $user_id );
		$this->assert_owned_book( $book_id, $user_id );

		if ( $category_id ) {
			$this->assert_owned_category( $category_id, $book_id, $user_id );
		}

		if ( 'transfer' === $type ) {
			if ( ! $transfer_account_id ) {
				throw new InvalidArgumentException( 'Transfer account is required for transfer transactions.' );
			}
			$this->assert_owned_account( $transfer_account_id, $book_id, $user_id );
		}

		$this->assert_owned_reference( 'stores', $this->optional_id( $data, 'store_id' ), $book_id, $user_id );
		$this->assert_owned_references( 'tags', $tag_ids, $book_id, $user_id );
		$this->assert_owned_references( 'members', $member_ids, $book_id, $user_id );

		$tables        = Balance_Beacon_Schema::get_table_names();
		$now           = current_time( 'mysql' );
		$primary_tag    = ! empty( $tag_ids ) ? absint( $tag_ids[0] ) : null;
		$primary_member = ! empty( $member_ids ) ? absint( $member_ids[0] ) : null;

		$result = $wpdb->insert(
			$tables['transactions'],
			array(
				'user_id'             => $user_id,
				'book_id'             => $book_id,
				'account_id'          => $account_id,
				'category_id'         => $category_id ? $category_id : null,
				'transfer_account_id' => $transfer_account_id ? $transfer_account_id : null,
				'transaction_type'    => $type,
				'transaction_date'    => $date,
				'amount'              => $amount,
				'to_amount'           => 'transfer' === $type ? $this->nullable_decimal( isset( $data['to_amount'] ) ? $data['to_amount'] : null, 4 ) : null,
				'currency_code'       => ! empty( $data['currency_code'] ) ? sanitize_text_field( (string) $data['currency_code'] ) : $account['currency_code'],
				'exchange_rate'       => 'transfer' === $type ? $this->nullable_decimal( isset( $data['exchange_rate'] ) ? $data['exchange_rate'] : null, 6 ) : null,
				'description'         => $description,
				'store_name'          => isset( $data['store_name'] ) ? sanitize_text_field( (string) $data['store_name'] ) : null,
				'store_id'            => $this->optional_id( $data, 'store_id' ) ?: null,
				'tag_id'              => $primary_tag,
				'member_id'           => $primary_member,
				'payment_method'      => isset( $data['payment_method'] ) ? sanitize_text_field( (string) $data['payment_method'] ) : null,
				'metadata'            => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
				'confirmed'           => isset( $data['confirmed'] ) ? ( rest_sanitize_boolean( $data['confirmed'] ) ? 1 : 0 ) : 1,
				'is_reconciled'       => isset( $data['is_reconciled'] ) ? ( rest_sanitize_boolean( $data['is_reconciled'] ) ? 1 : 0 ) : 0,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array(
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
			)
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Failed to create transaction.' );
		}

		$transaction_id = (int) $wpdb->insert_id;
		$this->sync_transaction_tags( $transaction_id, $tag_ids );
		$this->sync_transaction_members( $transaction_id, $member_ids );

		return $transaction_id;
	}

	/**
	 * Updates a user-owned transaction.
	 *
	 * @param int                  $id      Transaction ID.
	 * @param array<string, mixed> $data    Sanitized transaction data.
	 * @param int                  $user_id Current WordPress user ID.
	 * @return bool
	 */
	public function update_transaction( $id, array $data, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = absint( $user_id );

		if ( ! $id || ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['transactions']} WHERE id = %d AND user_id = %d", $id, $user_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			throw new RuntimeException( 'Transaction not found.' );
		}

		$existing_tags    = $this->get_relation_ids( 'transaction_tags', 'tag_id', $id );
		$existing_members = $this->get_relation_ids( 'transaction_members', 'member_id', $id );
		$merged           = array_merge( $row, $data );

		$book_id             = absint( $merged['book_id'] );
		$account_id          = absint( $merged['account_id'] );
		$category_id         = $this->optional_id( $merged, 'category_id' );
		$transfer_account_id = $this->optional_id( $merged, 'transfer_account_id' );
		$type                = $this->transaction_type( $merged );
		$tag_ids             = array_key_exists( 'tags', $data ) ? $this->id_list( $data['tags'] ) : $existing_tags;
		$member_ids          = array_key_exists( 'members', $data ) ? $this->id_list( $data['members'] ) : $existing_members;

		if ( empty( $tag_ids ) && $this->optional_id( $merged, 'tag_id' ) ) {
			$tag_ids = array( $this->optional_id( $merged, 'tag_id' ) );
		}

		if ( empty( $member_ids ) && $this->optional_id( $merged, 'member_id' ) ) {
			$member_ids = array( $this->optional_id( $merged, 'member_id' ) );
		}

		$this->assert_owned_book( $book_id, $user_id );
		$this->assert_owned_account( $account_id, $book_id, $user_id );

		if ( $category_id ) {
			$this->assert_owned_category( $category_id, $book_id, $user_id );
		}

		if ( 'transfer' === $type ) {
			if ( ! $transfer_account_id ) {
				throw new InvalidArgumentException( 'Transfer account is required for transfer transactions.' );
			}
			$this->assert_owned_account( $transfer_account_id, $book_id, $user_id );
		}

		$this->assert_owned_reference( 'stores', $this->optional_id( $merged, 'store_id' ), $book_id, $user_id );
		$this->assert_owned_references( 'tags', $tag_ids, $book_id, $user_id );
		$this->assert_owned_references( 'members', $member_ids, $book_id, $user_id );

		$description    = array_key_exists( 'description', $data ) ? (string) $data['description'] : ( isset( $data['notes'] ) ? (string) $data['notes'] : (string) $row['description'] );
		$primary_tag    = ! empty( $tag_ids ) ? absint( $tag_ids[0] ) : null;
		$primary_member = ! empty( $member_ids ) ? absint( $member_ids[0] ) : null;

		$update = array(
			'account_id'          => $account_id,
			'category_id'         => $category_id ?: null,
			'transfer_account_id' => $transfer_account_id ?: null,
			'tag_id'              => $primary_tag,
			'member_id'           => $primary_member,
			'store_id'            => $this->optional_id( $merged, 'store_id' ) ?: null,
			'transaction_type'    => $type,
			'transaction_date'    => $this->date( $merged['transaction_date'] ),
			'amount'              => $this->decimal( $merged['amount'] ),
			'to_amount'           => 'transfer' === $type ? $this->nullable_decimal( isset( $merged['to_amount'] ) ? $merged['to_amount'] : null, 4 ) : null,
			'exchange_rate'       => 'transfer' === $type ? $this->nullable_decimal( isset( $merged['exchange_rate'] ) ? $merged['exchange_rate'] : null, 6 ) : null,
			'description'         => $description,
			'is_reconciled'       => isset( $merged['is_reconciled'] ) ? ( rest_sanitize_boolean( $merged['is_reconciled'] ) ? 1 : 0 ) : 0,
			'updated_at'          => current_time( 'mysql' ),
		);

		$result = $wpdb->update(
			$tables['transactions'],
			$update,
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			null,
			array( '%d', '%d' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Failed to update transaction.' );
		}

		$this->sync_transaction_tags( $id, $tag_ids );
		$this->sync_transaction_members( $id, $member_ids );

		return true;
	}

	/**
	 * Deletes a user-owned transaction and its many-to-many rows.
	 *
	 * @param int $id      Transaction ID.
	 * @param int $user_id Current WordPress user ID.
	 * @return bool
	 */
	public function delete_transaction( $id, $user_id ) {
		global $wpdb;

		$id      = absint( $id );
		$user_id = absint( $user_id );

		if ( ! $id || ! $user_id || $user_id !== get_current_user_id() ) {
			throw new RuntimeException( 'Access denied.' );
		}

		$tables = Balance_Beacon_Schema::get_table_names();

		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['transactions']} WHERE id = %d AND user_id = %d", $id, $user_id ) ) ) {
			throw new RuntimeException( 'Transaction not found.' );
		}

		$wpdb->delete( $tables['transaction_tags'], array( 'transaction_id' => $id ), array( '%d' ) );
		$wpdb->delete( $tables['transaction_members'], array( 'transaction_id' => $id ), array( '%d' ) );

		if ( false === $wpdb->delete( $tables['transactions'], array( 'id' => $id, 'user_id' => $user_id ), array( '%d', '%d' ) ) ) {
			throw new RuntimeException( 'Failed to delete transaction.' );
		}

		return true;
	}

	/**
	 * Attaches tag and member arrays to transaction rows.
	 *
	 * @param array<int, array<string, mixed>> $rows    Transaction rows.
	 * @param int                             $user_id Current WordPress user ID.
	 * @return void
	 */
	private function attach_many_to_many( array &$rows, $user_id ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		$tables          = Balance_Beacon_Schema::get_table_names();
		$transaction_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );

		if ( empty( $transaction_ids ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $transaction_ids ), '%d' ) );
		$tag_rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.transaction_id, tg.id, tg.name
				FROM {$tables['transaction_tags']} tt
				INNER JOIN {$tables['tags']} tg ON tg.id = tt.tag_id AND tg.user_id = %d
				WHERE tt.transaction_id IN ({$placeholders})
				ORDER BY tg.name ASC",
				array_merge( array( absint( $user_id ) ), $transaction_ids )
			),
			ARRAY_A
		);
		$member_rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.transaction_id, m.id, m.name
				FROM {$tables['transaction_members']} tm
				INNER JOIN {$tables['members']} m ON m.id = tm.member_id AND m.user_id = %d
				WHERE tm.transaction_id IN ({$placeholders})
				ORDER BY m.name ASC",
				array_merge( array( absint( $user_id ) ), $transaction_ids )
			),
			ARRAY_A
		);

		$tags_by_transaction    = array();
		$members_by_transaction = array();

		foreach ( is_array( $tag_rows ) ? $tag_rows : array() as $tag_row ) {
			$transaction_id = absint( $tag_row['transaction_id'] );
			if ( ! isset( $tags_by_transaction[ $transaction_id ] ) ) {
				$tags_by_transaction[ $transaction_id ] = array();
			}
			$tags_by_transaction[ $transaction_id ][] = array(
				'id'   => absint( $tag_row['id'] ),
				'name' => (string) $tag_row['name'],
			);
		}

		foreach ( is_array( $member_rows ) ? $member_rows : array() as $member_row ) {
			$transaction_id = absint( $member_row['transaction_id'] );
			if ( ! isset( $members_by_transaction[ $transaction_id ] ) ) {
				$members_by_transaction[ $transaction_id ] = array();
			}
			$members_by_transaction[ $transaction_id ][] = array(
				'id'   => absint( $member_row['id'] ),
				'name' => (string) $member_row['name'],
			);
		}

		foreach ( $rows as &$row ) {
			$transaction_id = absint( $row['id'] );
			$row['tags']    = isset( $tags_by_transaction[ $transaction_id ] ) ? $tags_by_transaction[ $transaction_id ] : array();
			$row['members'] = isset( $members_by_transaction[ $transaction_id ] ) ? $members_by_transaction[ $transaction_id ] : array();
		}
	}

	/**
	 * Replaces all tag relations for a transaction.
	 *
	 * @param int        $transaction_id Transaction ID.
	 * @param array<int> $tag_ids        Tag IDs.
	 * @return void
	 */
	private function sync_transaction_tags( $transaction_id, array $tag_ids ) {
		$this->sync_relation_table( 'transaction_tags', 'tag_id', $transaction_id, $tag_ids );
	}

	/**
	 * Replaces all member relations for a transaction.
	 *
	 * @param int        $transaction_id Transaction ID.
	 * @param array<int> $member_ids     Member IDs.
	 * @return void
	 */
	private function sync_transaction_members( $transaction_id, array $member_ids ) {
		$this->sync_relation_table( 'transaction_members', 'member_id', $transaction_id, $member_ids );
	}

	/**
	 * Replaces all rows in a transaction relation table.
	 *
	 * @param string     $table_key      Schema table key.
	 * @param string     $column         Relation ID column.
	 * @param int        $transaction_id Transaction ID.
	 * @param array<int> $ids            Related IDs.
	 * @return void
	 */
	private function sync_relation_table( $table_key, $column, $transaction_id, array $ids ) {
		global $wpdb;

		$tables         = Balance_Beacon_Schema::get_table_names();
		$transaction_id = absint( $transaction_id );
		$ids            = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		$wpdb->delete( $tables[ $table_key ], array( 'transaction_id' => $transaction_id ), array( '%d' ) );

		foreach ( $ids as $id ) {
			$result = $wpdb->insert(
				$tables[ $table_key ],
				array(
					'transaction_id' => $transaction_id,
					$column          => $id,
					'created_at'     => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s' )
			);

			if ( false === $result ) {
				throw new RuntimeException( 'Failed to sync transaction relations.' );
			}
		}
	}

	/**
	 * Returns relation IDs for a transaction.
	 *
	 * @param string $table_key      Schema table key.
	 * @param string $column         Relation column.
	 * @param int    $transaction_id Transaction ID.
	 * @return array<int, int>
	 */
	private function get_relation_ids( $table_key, $column, $transaction_id ) {
		global $wpdb;

		$tables = Balance_Beacon_Schema::get_table_names();
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$column} FROM {$tables[ $table_key ]} WHERE transaction_id = %d",
				absint( $transaction_id )
			)
		);

		return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * Reads and validates a required positive integer ID.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @param string               $key  Field key.
	 * @return int
	 */
	private function required_id( array $data, $key ) {
		$id = isset( $data[ $key ] ) ? absint( $data[ $key ] ) : 0;
		if ( ! $id ) {
			throw new InvalidArgumentException( "{$key} is required." );
		}

		return $id;
	}

	/**
	 * Reads an optional positive integer ID.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @param string               $key  Field key.
	 * @return int
	 */
	private function optional_id( array $data, $key ) {
		return isset( $data[ $key ] ) && '' !== $data[ $key ] && null !== $data[ $key ] ? absint( $data[ $key ] ) : 0;
	}

	/**
	 * Normalizes an ID list from arrays or comma-separated strings.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, int>
	 */
	private function id_list( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/**
	 * Normalizes and validates the transaction type.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @return string
	 */
	private function transaction_type( array $data ) {
		$type = ! empty( $data['type'] ) ? (string) $data['type'] : ( ! empty( $data['transaction_type'] ) ? (string) $data['transaction_type'] : '' );
		$type = strtolower( trim( $type ) );

		if ( ! in_array( $type, array( 'income', 'expense', 'transfer' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid transaction type.' );
		}

		return $type;
	}

	/**
	 * Normalizes a decimal string without converting it to float.
	 *
	 * @param mixed $value Decimal input.
	 * @param int   $scale Maximum scale.
	 * @return string
	 */
	private function decimal( $value, $scale = 4 ) {
		$normalized = Balance_Beacon_Decimal::normalize( $value );
		$parts      = explode( '.', $normalized, 2 );

		if ( isset( $parts[1] ) && strlen( $parts[1] ) > $scale ) {
			throw new InvalidArgumentException( 'Decimal value has too many fractional digits.' );
		}

		return $normalized;
	}

	/**
	 * Normalizes a nullable decimal string.
	 *
	 * @param mixed $value Decimal input.
	 * @param int   $scale Maximum scale.
	 * @return string|null
	 */
	private function nullable_decimal( $value, $scale = 4 ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return $this->decimal( $value, $scale );
	}

	/**
	 * Validates a Y-m-d date string.
	 *
	 * @param mixed $value Date input.
	 * @return string
	 */
	private function date( $value ) {
		$date = is_string( $value ) ? trim( $value ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new InvalidArgumentException( 'Invalid transaction date.' );
		}

		$parts = array_map( 'intval', explode( '-', $date ) );
		if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			throw new InvalidArgumentException( 'Invalid transaction date.' );
		}

		return $date;
	}

	/**
	 * Validates an optional Y-m-d date filter.
	 *
	 * @param mixed $value Date filter input.
	 * @return string|null
	 */
	private function optional_filter_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return $this->date( $value );
	}

	/**
	 * Keeps the REST list response compatible with frontend aliases.
	 *
	 * @param array<string, mixed> $row Raw SQL row.
	 * @return array<string, mixed>
	 */
	private function normalize_transaction_row( array $row ) {
		$tags    = isset( $row['tags'] ) && is_array( $row['tags'] ) ? $row['tags'] : array();
		$members = isset( $row['members'] ) && is_array( $row['members'] ) ? $row['members'] : array();

		$row['id']                  = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
		$row['user_id']             = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
		$row['book_id']             = isset( $row['book_id'] ) ? absint( $row['book_id'] ) : 0;
		$row['account_id']          = isset( $row['account_id'] ) ? absint( $row['account_id'] ) : 0;
		$row['category_id']         = isset( $row['category_id'] ) ? absint( $row['category_id'] ) : 0;
		$row['transfer_account_id'] = isset( $row['transfer_account_id'] ) ? absint( $row['transfer_account_id'] ) : 0;
		$row['target_account_id']   = $row['transfer_account_id'];
		$row['store_id']            = isset( $row['store_id'] ) ? absint( $row['store_id'] ) : 0;
		$row['store']               = $row['store_id'];
		$row['tag_id']              = ! empty( $tags ) ? absint( $tags[0]['id'] ) : ( isset( $row['tag_id'] ) ? absint( $row['tag_id'] ) : 0 );
		$row['member_id']           = ! empty( $members ) ? absint( $members[0]['id'] ) : ( isset( $row['member_id'] ) ? absint( $row['member_id'] ) : 0 );
		$row['is_reconciled']       = isset( $row['is_reconciled'] ) ? absint( $row['is_reconciled'] ) : 0;
		$row['date']                = isset( $row['transaction_date'] ) ? $row['transaction_date'] : '';
		$row['type']                = isset( $row['transaction_type'] ) ? $row['transaction_type'] : '';
		$row['notes']               = isset( $row['description'] ) ? $row['description'] : '';
		$row['store_name']          = isset( $row['resolved_store_name'] ) ? $row['resolved_store_name'] : ( isset( $row['store_name'] ) ? $row['store_name'] : null );
		$row['tags']                = $tags;
		$row['members']             = $members;
		$row['tag_ids']             = array_values( array_map( 'absint', wp_list_pluck( $tags, 'id' ) ) );
		$row['member_ids']          = array_values( array_map( 'absint', wp_list_pluck( $members, 'id' ) ) );
		$row['tag_names']           = array_values( array_map( 'strval', wp_list_pluck( $tags, 'name' ) ) );
		$row['member_names']        = array_values( array_map( 'strval', wp_list_pluck( $members, 'name' ) ) );

		unset( $row['resolved_store_name'] );

		return $row;
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
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['books']} WHERE id = %d AND user_id = %d", $book_id, $user_id ) );

		if ( ! $exists ) {
			throw new RuntimeException( 'Book not found.' );
		}
	}

	/**
	 * Ensures the account belongs to the current user and book.
	 *
	 * @param int $account_id Account ID.
	 * @param int $book_id    Book ID.
	 * @param int $user_id    User ID.
	 * @return array<string, mixed>
	 */
	private function assert_owned_account( $account_id, $book_id, $user_id ) {
		global $wpdb;

		$tables  = Balance_Beacon_Schema::get_table_names();
		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, currency_code FROM {$tables['accounts']} WHERE id = %d AND book_id = %d AND user_id = %d",
				$account_id,
				$book_id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! $account ) {
			throw new RuntimeException( 'Account not found.' );
		}

		return $account;
	}

	/**
	 * Ensures the category belongs to the current user and book.
	 *
	 * @param int $category_id Category ID.
	 * @param int $book_id     Book ID.
	 * @param int $user_id     User ID.
	 * @return void
	 */
	private function assert_owned_category( $category_id, $book_id, $user_id ) {
		global $wpdb;

		$tables = Balance_Beacon_Schema::get_table_names();
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['categories']} WHERE id = %d AND book_id = %d AND user_id = %d",
				$category_id,
				$book_id,
				$user_id
			)
		);

		if ( ! $exists ) {
			throw new RuntimeException( 'Category not found.' );
		}
	}

	/**
	 * Ensures a related entity belongs to the current user and book.
	 *
	 * @param string $entity  Schema table key.
	 * @param int    $id      Entity ID.
	 * @param int    $book_id Book ID.
	 * @param int    $user_id User ID.
	 * @return void
	 */
	private function assert_owned_reference( $entity, $id, $book_id, $user_id ) {
		if ( ! $id ) {
			return;
		}

		global $wpdb;

		$tables = Balance_Beacon_Schema::get_table_names();
		$table  = isset( $tables[ $entity ] ) ? $tables[ $entity ] : '';

		if ( ! $table || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND book_id = %d AND user_id = %d", $id, $book_id, $user_id ) ) ) {
			throw new RuntimeException( ucfirst( $entity ) . ' not found.' );
		}
	}

	/**
	 * Ensures all related entities belong to the current user and book.
	 *
	 * @param string     $entity  Schema table key.
	 * @param array<int> $ids     Entity IDs.
	 * @param int        $book_id Book ID.
	 * @param int        $user_id User ID.
	 * @return void
	 */
	private function assert_owned_references( $entity, array $ids, $book_id, $user_id ) {
		foreach ( $ids as $id ) {
			$this->assert_owned_reference( $entity, absint( $id ), $book_id, $user_id );
		}
	}
}
