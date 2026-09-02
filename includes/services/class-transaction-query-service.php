<?php
/**
 * Transaction RPC replacements.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the transaction-related PostgreSQL RPC functions.
 */
final class Balance_Beacon_Transaction_Query_Service {
	/**
	 * Replacement for get_transactions_with_balance.
	 *
	 * Running balances are calculated before filters are applied, matching the
	 * PostgreSQL CTE/window-function order.
	 *
	 * @param array $args RPC-style arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_transactions_with_balance( array $args ) {
		$user_id = $this->user_id( $args );
		$book_id = isset( $args['p_book_id'] ) ? absint( $args['p_book_id'] ) : 0;

		if ( ! $book_id ) {
			return array();
		}

		$rows     = $this->fetch_rows( $user_id, $book_id );
		$balances = $this->calculate_running_balances( $rows );
		$filtered = $this->filter_rows( $rows, $args );
		$total    = count( $filtered );

		usort( $filtered, array( $this, 'compare_descending' ) );
		$page   = max( 1, isset( $args['p_page'] ) ? absint( $args['p_page'] ) : 1 );
		$limit  = max( 1, min( 1000, isset( $args['p_limit'] ) ? absint( $args['p_limit'] ) : 10 ) );
		$rows   = array_slice( $filtered, ( $page - 1 ) * $limit, $limit );

		return $this->enrich_rows( $rows, $balances, $total );
	}

	/** Replacement for get_filtered_transaction_ids. */
	public function get_filtered_transaction_ids( array $args ) {
		$user_id = $this->user_id( $args );
		$book_id = empty( $args['p_book_id'] ) ? null : absint( $args['p_book_id'] );
		$rows    = $this->fetch_rows( $user_id, $book_id );
		$rows    = $this->filter_rows( $rows, $args );
		usort( $rows, array( $this, 'compare_id_list' ) );

		return array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	}

	/** Replacement for get_transaction_details_by_ids. */
	public function get_transaction_details_by_ids( array $ids, $user_id = null ) {
		global $wpdb;

		$current_user_id = get_current_user_id();
		$user_id         = $user_id ? absint( $user_id ) : $current_user_id;
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $current_user_id || $user_id !== $current_user_id ) {
			throw new RuntimeException( 'Access denied.' );
		}

		if ( ! $ids ) {
			return array();
		}

		$tables       = Balance_Beacon_Schema::get_table_names();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $user_id ), $ids );
		$sql          = "SELECT * FROM {$tables['transactions']} WHERE user_id = %d AND id IN ({$placeholders})";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		usort( $rows, array( $this, 'compare_id_list' ) );

		return $this->enrich_rows( $rows, array(), 0 );
	}

	/**
	 * Replacement for export_transactions using keyset pagination.
	 *
	 * The SQL body was absent from the reference file; its frontend contract is
	 * reproduced from the RPC call parameters.
	 */
	public function export_transactions( array $args ) {
		$user_id = $this->user_id( $args );
		$book_id = empty( $args['p_book_id'] ) ? null : absint( $args['p_book_id'] );
		$rows    = $this->filter_rows( $this->fetch_rows( $user_id, $book_id ), $args );
		$total   = count( $rows );

		usort( $rows, array( $this, 'compare_descending' ) );
		$rows  = array_values( array_filter( $rows, function ( $row ) use ( $args ) {
			return $this->is_after_cursor( $row, $args );
		} ) );
		$limit = max( 1, min( 5000, isset( $args['p_limit'] ) ? absint( $args['p_limit'] ) : 1000 ) );

		return $this->enrich_rows( array_slice( $rows, 0, $limit ), array(), $total );
	}

	/** Fetch user-owned rows with store name for keyword filtering. */
	private function fetch_rows( $user_id, $book_id = null ) {
		global $wpdb;

		$tables = Balance_Beacon_Schema::get_table_names();
		$sql    = "SELECT t.*, COALESCE(s.name, t.store_name) AS resolved_store_name
			FROM {$tables['transactions']} t
			LEFT JOIN {$tables['stores']} s ON s.id = t.store_id AND s.user_id = t.user_id
			WHERE t.user_id = %d";
		$args   = array( $user_id );

		if ( null !== $book_id ) {
			$sql   .= ' AND t.book_id = %d';
			$args[] = $book_id;
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Apply the nullable RPC filters in PHP. */
	private function filter_rows( array $rows, array $args ) {
		$tag_ids = $this->id_filter( isset( $args['p_tag_ids'] ) ? $args['p_tag_ids'] : null );
		$members = $this->id_filter( isset( $args['p_members'] ) ? $args['p_members'] : null );

		return array_values( array_filter( $rows, function ( $row ) use ( $args, $tag_ids, $members ) {
			if ( ! empty( $args['p_date_from'] ) && $row['transaction_date'] < $args['p_date_from'] ) return false;
			if ( ! empty( $args['p_date_to'] ) && $row['transaction_date'] > $args['p_date_to'] ) return false;
			if ( isset( $args['p_min_amount'] ) && null !== $args['p_min_amount'] && $this->decimal_compare( $row['amount'], $args['p_min_amount'] ) < 0 ) return false;
			if ( isset( $args['p_max_amount'] ) && null !== $args['p_max_amount'] && $this->decimal_compare( $row['amount'], $args['p_max_amount'] ) > 0 ) return false;
			if ( ! empty( $args['p_category_id'] ) && (int) $row['category_id'] !== absint( $args['p_category_id'] ) ) return false;
			if ( ! empty( $args['p_account_id'] ) && (int) $row['account_id'] !== absint( $args['p_account_id'] ) && (int) $row['transfer_account_id'] !== absint( $args['p_account_id'] ) ) return false;
			if ( array_key_exists( 'p_confirmed', $args ) && null !== $args['p_confirmed'] && (bool) $row['confirmed'] !== (bool) $args['p_confirmed'] ) return false;
			if ( ! empty( $args['p_store_id'] ) && (int) $row['store_id'] !== absint( $args['p_store_id'] ) ) return false;
			if ( $tag_ids && ! array_intersect( $tag_ids, $this->relation_ids( 'transaction_tags', 'tag_id', $row['id'] ) ) ) return false;
			if ( $members && ! array_intersect( $members, $this->relation_ids( 'transaction_members', 'member_id', $row['id'] ) ) ) return false;
			if ( isset( $args['p_keyword'] ) && null !== $args['p_keyword'] && '' !== (string) $args['p_keyword'] ) {
				$haystack = (string) $row['description'] . ' ' . (string) $row['resolved_store_name'];
				if ( false === mb_stripos( $haystack, (string) $args['p_keyword'], 0, 'UTF-8' ) ) return false;
			}
			return true;
		} ) );
	}

	/** Replace SUM() OVER with an ordered PHP accumulator per book/account. */
	private function calculate_running_balances( array $rows ) {
		usort( $rows, array( $this, 'compare_ascending' ) );
		$totals = array();
		$result = array();

		foreach ( $rows as $row ) {
			$source_key = $row['book_id'] . ':' . $row['account_id'];
			$change     = 'income' === $row['transaction_type'] ? $row['amount'] : '-' . ltrim( $row['amount'], '-' );
			$totals[ $source_key ] = Balance_Beacon_Decimal::add( isset( $totals[ $source_key ] ) ? $totals[ $source_key ] : '0', $change );
			$result[ $row['id'] ]['source'] = $totals[ $source_key ];

			if ( 'transfer' === $row['transaction_type'] && ! empty( $row['transfer_account_id'] ) ) {
				$target_key = $row['book_id'] . ':' . $row['transfer_account_id'];
				$incoming   = null !== $row['to_amount'] ? $row['to_amount'] : $row['amount'];
				$totals[ $target_key ] = Balance_Beacon_Decimal::add( isset( $totals[ $target_key ] ) ? $totals[ $target_key ] : '0', $incoming );
				$result[ $row['id'] ]['target'] = $totals[ $target_key ];
			}
		}

		return $result;
	}

	/** Add joined names and relation arrays while preserving the old RPC keys. */
	private function enrich_rows( array $rows, array $balances, $total ) {
		global $wpdb;
		$tables = Balance_Beacon_Schema::get_table_names();

		foreach ( $rows as &$row ) {
			$book     = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$tables['books']} WHERE id = %d AND user_id = %d", $row['book_id'], $row['user_id'] ), ARRAY_A );
			$category = $row['category_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$tables['categories']} WHERE id = %d AND user_id = %d", $row['category_id'], $row['user_id'] ), ARRAY_A ) : null;
			$account  = $wpdb->get_row( $wpdb->prepare( "SELECT name, currency_code FROM {$tables['accounts']} WHERE id = %d AND user_id = %d", $row['account_id'], $row['user_id'] ), ARRAY_A );
			$target   = $row['transfer_account_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT name, currency_code FROM {$tables['accounts']} WHERE id = %d AND user_id = %d", $row['transfer_account_id'], $row['user_id'] ), ARRAY_A ) : null;
			$tag_ids  = $this->relation_ids( 'transaction_tags', 'tag_id', $row['id'] );
			$mem_ids  = $this->relation_ids( 'transaction_members', 'member_id', $row['id'] );

			$row = array_merge( $row, array(
				'date'                    => $row['transaction_date'],
				'type'                    => $row['transaction_type'],
				'notes'                   => $row['description'],
				'target_account_id'       => $row['transfer_account_id'],
				'store'                   => $row['store_id'],
				'book_name'               => $book ? $book['name'] : null,
				'category_name'           => $category ? $category['name'] : null,
				'account_name'            => $account ? $account['name'] : null,
				'account_currency'        => $account ? $account['currency_code'] : null,
				'target_account_name'     => $target ? $target['name'] : null,
				'target_account_currency' => $target ? $target['currency_code'] : null,
				'store_name'              => isset( $row['resolved_store_name'] ) ? $row['resolved_store_name'] : $row['store_name'],
				'tag_ids'                 => $tag_ids,
				'members'                 => $mem_ids,
				'tag_names'               => $this->names_for_ids( 'tags', $tag_ids, $row['user_id'] ),
				'member_names'            => $this->names_for_ids( 'members', $mem_ids, $row['user_id'] ),
				'source_running_balance'  => isset( $balances[ $row['id'] ]['source'] ) ? $balances[ $row['id'] ]['source'] : null,
				'target_running_balance'  => isset( $balances[ $row['id'] ]['target'] ) ? $balances[ $row['id'] ]['target'] : null,
				'running_balance'         => null,
				'total_count'             => (int) $total,
			) );
			unset( $row['resolved_store_name'] );
		}
		unset( $row );

		return $rows;
	}

	/** Read normalized many-to-many IDs. */
	private function relation_ids( $table_key, $column, $transaction_id ) {
		global $wpdb;
		$tables = Balance_Beacon_Schema::get_table_names();
		$sql    = "SELECT {$column} FROM {$tables[$table_key]} WHERE transaction_id = %d";
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $transaction_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Resolve tag/member names without PostgreSQL arrays. */
	private function names_for_ids( $table_key, array $ids, $user_id ) {
		global $wpdb;
		if ( ! $ids ) return array();
		$tables = Balance_Beacon_Schema::get_table_names();
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql    = "SELECT name FROM {$tables[$table_key]} WHERE user_id = %d AND id IN ({$in})";
		return $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $user_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function id_filter( $value ) {
		return is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
	}

	private function user_id( array $args ) {
		$current = get_current_user_id();
		if ( ! $current ) throw new RuntimeException( 'Authentication required.' );
		if ( ! empty( $args['p_user_id'] ) && absint( $args['p_user_id'] ) !== $current ) throw new RuntimeException( 'Access denied.' );
		return $current;
	}

	private function decimal_compare( $left, $right ) {
		if ( function_exists( 'bccomp' ) ) return bccomp( Balance_Beacon_Decimal::normalize( $left ), Balance_Beacon_Decimal::normalize( $right ), 8 );
		$difference = Balance_Beacon_Decimal::subtract( $left, $right, 8 );
		return '-' === $difference[0] ? -1 : ( preg_match( '/^0(?:\.0+)?$/', $difference ) ? 0 : 1 );
	}

	private function is_after_cursor( $row, $args ) {
		if ( empty( $args['p_cursor_date'] ) ) return true;
		$updated = max( (string) $row['created_at'], (string) $row['updated_at'] );
		$cursor_updated = isset( $args['p_cursor_created_at'] ) ? (string) $args['p_cursor_created_at'] : '';
		return $row['transaction_date'] < $args['p_cursor_date'] ||
			( $row['transaction_date'] === $args['p_cursor_date'] && $updated < $cursor_updated ) ||
			( $row['transaction_date'] === $args['p_cursor_date'] && $updated === $cursor_updated && (int) $row['id'] < absint( $args['p_cursor_id'] ) );
	}

	private function compare_ascending( $a, $b ) {
		return array( $a['transaction_date'], max( $a['created_at'], $a['updated_at'] ), (int) $a['id'] ) <=> array( $b['transaction_date'], max( $b['created_at'], $b['updated_at'] ), (int) $b['id'] );
	}

	private function compare_descending( $a, $b ) {
		return -$this->compare_ascending( $a, $b );
	}

	private function compare_id_list( $a, $b ) {
		$date = strcmp( $b['transaction_date'], $a['transaction_date'] );
		if ( 0 !== $date ) return $date;
		$created = strcmp( $b['created_at'], $a['created_at'] );
		return 0 !== $created ? $created : ( (int) $a['id'] <=> (int) $b['id'] );
	}
}
