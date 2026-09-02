<?php
/**
 * Base REST controller utilities.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for Balance Beacon REST controllers.
 */
abstract class Balance_Beacon_REST_Base_Controller {
	const NAMESPACE = 'balance-beacon/v1';

	/**
	 * Restricts every API endpoint to signed-in WordPress users.
	 *
	 * @return bool
	 */
	public function permission_callback() {
		return is_user_logged_in();
	}

	/**
	 * Converts a service exception to a REST error response.
	 *
	 * @param Throwable $exception Exception thrown by a service.
	 * @return WP_Error
	 */
	protected function service_error( Throwable $exception ) {
		return new WP_Error(
			'balance_beacon_service_error',
			$exception->getMessage(),
			array( 'status' => 403 )
		);
	}

	/**
	 * Reads an integer array from JSON arrays, query arrays, or CSV strings.
	 *
	 * @param mixed $value Request value.
	 * @return array<int, int>
	 */
	protected function int_array( $value ) {
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
	 * Returns null for missing or empty request values.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $key     Parameter name.
	 * @return mixed|null
	 */
	protected function nullable_param( WP_REST_Request $request, $key ) {
		$value = $request->get_param( $key );

		return '' === $value ? null : $value;
	}

	/**
	 * Parses nullable booleans while preserving null.
	 *
	 * @param mixed $value Request value.
	 * @return bool|null
	 */
	protected function nullable_bool( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return rest_sanitize_boolean( $value );
	}

	/**
	 * Returns a user-owned book ID, creating the user's default book when needed.
	 *
	 * @param int $book_id Requested book ID. Pass 0 to use the default book.
	 * @param int $user_id Current WordPress user ID.
	 * @return int|WP_Error
	 */
	protected function resolve_user_book_id( $book_id, $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$book_id = absint( $book_id );

		if ( ! $user_id || $user_id !== get_current_user_id() ) {
			return new WP_Error(
				'balance_beacon_forbidden',
				'無法驗證目前使用者。',
				array( 'status' => 403 )
			);
		}

		$tables = Balance_Beacon_Schema::get_table_names();

		if ( $book_id ) {
			$owned = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$tables['books']} WHERE id = %d AND user_id = %d",
					$book_id,
					$user_id
				)
			);

			if ( $owned ) {
				return (int) $owned;
			}
		}

		$default_book_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['books']} WHERE user_id = %d ORDER BY is_default DESC, id ASC LIMIT 1",
				$user_id
			)
		);

		if ( $default_book_id ) {
			if ( $book_id ) {
				return new WP_Error(
					'balance_beacon_forbidden',
					'無法使用此帳本。',
					array( 'status' => 403 )
				);
			}

			return (int) $default_book_id;
		}

		$now    = current_time( 'mysql' );
		$result = $wpdb->insert(
			$tables['books'],
			array(
				'user_id'       => $user_id,
				'name'          => '預設帳本',
				'currency_code' => 'TWD',
				'is_default'    => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'balance_beacon_book_create_failed',
				'無法建立預設帳本。',
				array( 'status' => 500 )
			);
		}

		return (int) $wpdb->insert_id;
	}
}
