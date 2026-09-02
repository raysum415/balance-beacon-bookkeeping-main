<?php
/**
 * Transaction REST controller.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes transaction query and write services to the frontend.
 */
final class Balance_Beacon_REST_Transaction_Controller extends Balance_Beacon_REST_Base_Controller {
	/**
	 * Registers transaction routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/transactions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_transactions' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->filter_args( true ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_transaction' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->create_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/transactions/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_transaction' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->create_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_transaction' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/transactions/ids',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_transaction_ids' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $this->filter_args( false ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/transactions/details',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_transaction_details' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'ids' => array(
						'description' => 'Transaction IDs as an array or comma-separated list.',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/transactions/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_transactions' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array_merge(
					$this->filter_args( false ),
					array(
						'cursor_date' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'cursor_created_at' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'cursor_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);
	}

	/**
	 * Handles GET /transactions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transactions( WP_REST_Request $request ) {
		try {
			$rows = ( new Balance_Beacon_Transaction_Service() )->get_transactions(
				get_current_user_id(),
				absint( $request->get_param( 'book_id' ) ),
				$this->transaction_filter_payload( $request )
			);

			return rest_ensure_response( is_array( $rows ) ? $rows : array() );
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles POST /transactions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_transaction( WP_REST_Request $request ) {
		try {
			$transaction_id = ( new Balance_Beacon_Transaction_Service() )->create_transaction(
				$this->create_payload( $request ),
				get_current_user_id()
			);

			return rest_ensure_response(
				array(
					'id'      => $transaction_id,
					'success' => true,
				)
			);
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error(
				'balance_beacon_invalid_transaction',
				$exception->getMessage(),
				array( 'status' => 400 )
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles PUT /transactions/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_transaction( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Transaction_Service() )->update_transaction(
				absint( $request['id'] ),
				$this->create_payload( $request ),
				get_current_user_id()
			);

			return rest_ensure_response(
				array(
					'id'      => absint( $request['id'] ),
					'success' => true,
				)
			);
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error(
				'balance_beacon_invalid_transaction',
				$exception->getMessage(),
				array( 'status' => 400 )
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles DELETE /transactions/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_transaction( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Transaction_Service() )->delete_transaction(
				absint( $request['id'] ),
				get_current_user_id()
			);

			return rest_ensure_response(
				array(
					'id'      => absint( $request['id'] ),
					'success' => true,
				)
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles GET /transactions/ids.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transaction_ids( WP_REST_Request $request ) {
		try {
			$ids = ( new Balance_Beacon_Transaction_Query_Service() )->get_filtered_transaction_ids(
				$this->query_args( $request, false )
			);

			return rest_ensure_response( $ids );
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles GET /transactions/details.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_transaction_details( WP_REST_Request $request ) {
		try {
			$rows = ( new Balance_Beacon_Transaction_Query_Service() )->get_transaction_details_by_ids(
				$this->int_array( $request->get_param( 'ids' ) ),
				get_current_user_id()
			);

			return rest_ensure_response( $rows );
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles GET /transactions/export.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_transactions( WP_REST_Request $request ) {
		try {
			$rows = ( new Balance_Beacon_Transaction_Query_Service() )->export_transactions(
				$this->query_args( $request, false, true )
			);

			return rest_ensure_response( $rows );
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Builds REST argument definitions for shared transaction filters.
	 *
	 * @param bool $require_book Whether book_id is required.
	 * @return array<string, array<string, mixed>>
	 */
	private function filter_args( $require_book ) {
		return array(
			'book_id' => array(
				'type'              => 'integer',
				'required'          => $require_book,
				'sanitize_callback' => 'absint',
			),
			'page' => array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'limit' => array(
				'type'              => 'integer',
				'default'           => 100,
				'sanitize_callback' => 'absint',
			),
			'date_from' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'start_date' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'end_date' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'min_amount' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'max_amount' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'category_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'account_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'confirmed' => array(
				'type' => 'boolean',
			),
			'store_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'tag_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'member_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'is_reconciled' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'tag_ids' => array(
				'description' => 'Tag IDs as an array or comma-separated list.',
			),
			'tags' => array(
				'description' => 'Tag IDs as an array or comma-separated list.',
			),
			'members' => array(
				'description' => 'Member IDs as an array or comma-separated list.',
			),
			'keyword' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Builds REST argument definitions for transaction creation and updates.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function create_args() {
		return array(
			'book_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'account_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'category_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'transfer_account_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'store_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'tag_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'member_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'tags' => array(
				'description' => 'Tag IDs array.',
			),
			'members' => array(
				'description' => 'Member IDs array.',
			),
			'is_reconciled' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'type' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'transaction_type' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'amount' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'to_amount' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'exchange_rate' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'transaction_date' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'notes' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'description' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Sanitizes write data before handing it to the service.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	private function create_payload( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' === $type ) {
			$type = sanitize_key( (string) $request->get_param( 'transaction_type' ) );
		}

		$payload = array(
			'book_id'             => absint( $request->get_param( 'book_id' ) ),
			'account_id'          => absint( $request->get_param( 'account_id' ) ),
			'category_id'         => $this->nullable_id( $request->get_param( 'category_id' ) ),
			'transfer_account_id' => $this->nullable_id( $request->get_param( 'transfer_account_id' ) ),
			'store_id'            => $this->nullable_id( $request->get_param( 'store_id' ) ),
			'tag_id'              => $this->nullable_id( $request->get_param( 'tag_id' ) ),
			'member_id'           => $this->nullable_id( $request->get_param( 'member_id' ) ),
			'tags'                => $this->int_array( $request->get_param( 'tags' ) ),
			'members'             => $this->int_array( $request->get_param( 'members' ) ),
			'type'                => $type,
			'amount'              => sanitize_text_field( (string) $request->get_param( 'amount' ) ),
			'to_amount'           => $this->nullable_decimal_param( $request, 'to_amount' ),
			'exchange_rate'       => $this->nullable_decimal_param( $request, 'exchange_rate' ),
			'transaction_date'    => sanitize_text_field( (string) ( $request->get_param( 'transaction_date' ) ?: $request->get_param( 'date' ) ) ),
			'notes'               => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			'description'         => sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?: $request->get_param( 'notes' ) ) ),
		);

		if ( empty( $payload['tags'] ) && $payload['tag_id'] ) {
			$payload['tags'] = array( $payload['tag_id'] );
		}

		if ( empty( $payload['members'] ) && $payload['member_id'] ) {
			$payload['members'] = array( $payload['member_id'] );
		}

		if ( $request->has_param( 'is_reconciled' ) ) {
			$payload['is_reconciled'] = rest_sanitize_boolean( $request->get_param( 'is_reconciled' ) ) ? 1 : 0;
		}

		return $payload;
	}

	/**
	 * Normalizes empty IDs to null.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function nullable_id( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = absint( $value );

		return $value ? $value : null;
	}

	/**
	 * Reads a nullable decimal request value as a sanitized string.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $key     Parameter key.
	 * @return string|null
	 */
	private function nullable_decimal_param( WP_REST_Request $request, $key ) {
		$value = $request->get_param( $key );

		if ( null === $value || '' === $value ) {
			return null;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitizes GET /transactions filters for SQL-backed queries.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	private function transaction_filter_payload( WP_REST_Request $request ) {
		$payload = array(
			'start_date'  => sanitize_text_field( (string) ( $request->get_param( 'start_date' ) ?: $request->get_param( 'date_from' ) ) ),
			'end_date'    => sanitize_text_field( (string) ( $request->get_param( 'end_date' ) ?: $request->get_param( 'date_to' ) ) ),
			'account_id'  => absint( $request->get_param( 'account_id' ) ),
			'category_id' => absint( $request->get_param( 'category_id' ) ),
			'member_id'   => absint( $request->get_param( 'member_id' ) ),
			'store_id'    => absint( $request->get_param( 'store_id' ) ),
			'tag_id'      => absint( $request->get_param( 'tag_id' ) ),
			'tags'        => $this->int_array( $request->get_param( 'tags' ) ?: $request->get_param( 'tag_ids' ) ),
			'members'     => $this->int_array( $request->get_param( 'members' ) ),
			'page'        => absint( $request->get_param( 'page' ) ),
			'limit'       => absint( $request->get_param( 'limit' ) ),
		);

		if ( $request->has_param( 'is_reconciled' ) ) {
			$payload['is_reconciled'] = rest_sanitize_boolean( $request->get_param( 'is_reconciled' ) ) ? 1 : 0;
		}

		return $payload;
	}

	/**
	 * Translates REST query names into the old RPC argument names expected by services.
	 *
	 * @param WP_REST_Request $request        Request object.
	 * @param bool            $include_page   Whether to include page pagination.
	 * @param bool            $include_cursor Whether to include export cursor values.
	 * @return array<string, mixed>
	 */
	private function query_args( WP_REST_Request $request, $include_page, $include_cursor = false ) {
		$args = array(
			'p_user_id'     => get_current_user_id(),
			'p_book_id'     => $this->nullable_param( $request, 'book_id' ),
			'p_date_from'   => $this->nullable_param( $request, 'date_from' ),
			'p_date_to'     => $this->nullable_param( $request, 'date_to' ),
			'p_min_amount'  => $this->nullable_param( $request, 'min_amount' ),
			'p_max_amount'  => $this->nullable_param( $request, 'max_amount' ),
			'p_category_id' => $this->nullable_param( $request, 'category_id' ),
			'p_account_id'  => $this->nullable_param( $request, 'account_id' ),
			'p_confirmed'   => $this->nullable_bool( $request->get_param( 'confirmed' ) ),
			'p_store_id'    => $this->nullable_param( $request, 'store_id' ),
			'p_tag_ids'     => $this->int_array( $request->get_param( 'tag_ids' ) ?: $request->get_param( 'tags' ) ),
			'p_members'     => $this->int_array( $request->get_param( 'members' ) ),
			'p_keyword'     => $this->nullable_param( $request, 'keyword' ),
			'p_limit'       => absint( $request->get_param( 'limit' ) ),
		);

		if ( $include_page ) {
			$args['p_page'] = absint( $request->get_param( 'page' ) );
		}

		if ( $include_cursor ) {
			$args['p_cursor_date']       = $this->nullable_param( $request, 'cursor_date' );
			$args['p_cursor_created_at'] = $this->nullable_param( $request, 'cursor_created_at' );
			$args['p_cursor_id']         = $this->nullable_param( $request, 'cursor_id' );
		}

		return $args;
	}
}
