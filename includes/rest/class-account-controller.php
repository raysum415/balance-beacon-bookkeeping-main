<?php
/**
 * Account REST controller.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes account CRUD and balance queries to the frontend.
 */
final class Balance_Beacon_REST_Account_Controller extends Balance_Beacon_REST_Base_Controller {
	/**
	 * Registers account routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/accounts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_accounts' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'group_id' => array(
							'description'       => 'all, unclassified, or a numeric account group ID.',
							'type'              => 'string',
							'default'           => 'all',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_account' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->write_args( true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/accounts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_account' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->write_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_account' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Handles GET /accounts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_accounts( WP_REST_Request $request ) {
		try {
			$service = new Balance_Beacon_Account_Service();
			$rows    = $service->get_accounts_with_balance(
				$request->get_param( 'group_id' ),
				get_current_user_id()
			);

			return rest_ensure_response(
				array_map( array( $this, 'prepare_item_for_response' ), $rows )
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles POST /accounts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_account( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$book_id = $this->resolve_user_book_id( absint( $request->get_param( 'book_id' ) ), $user_id );

		if ( is_wp_error( $book_id ) ) {
			return $book_id;
		}

		$data             = $this->create_payload( $request, true );
		$data['book_id']  = $book_id;
		$data['currency'] = $data['currency'] ?: 'TWD';

		try {
			$id = ( new Balance_Beacon_Account_Service() )->create_account( $data, $user_id );

			return rest_ensure_response(
				array(
					'id'      => $id,
					'book_id' => (int) $book_id,
					'success' => true,
				)
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles PUT /accounts/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_account( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Account_Service() )->update_account(
				$request['id'],
				$this->create_payload( $request, false ),
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
	 * Handles DELETE /accounts/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_account( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Account_Service() )->delete_account( $request['id'], get_current_user_id() );

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
	 * Prepare an account row for REST output.
	 *
	 * @param array<string, mixed> $item Account row.
	 * @return array<string, mixed>
	 */
	public function prepare_item_for_response( $item ) {
		return array(
			'id'                => absint( $item['id'] ?? 0 ),
			'book_id'           => absint( $item['book_id'] ?? 0 ),
			'user_id'           => absint( $item['user_id'] ?? 0 ),
			'name'              => (string) ( $item['name'] ?? '' ),
			'account_type'      => (string) ( $item['account_type'] ?? ( $item['type'] ?? 'asset' ) ),
			'type'              => (string) ( $item['type'] ?? ( $item['account_type'] ?? 'asset' ) ),
			'initial_balance'   => (string) ( $item['initial_balance'] ?? '0.0000' ),
			'currency'          => (string) ( $item['currency'] ?? ( $item['currency_code'] ?? 'TWD' ) ),
			'currency_code'     => (string) ( $item['currency_code'] ?? ( $item['currency'] ?? 'TWD' ) ),
			'account_group'     => (string) ( $item['account_group'] ?? 'cash' ),
			'group_id'          => empty( $item['group_id'] ) ? null : absint( $item['group_id'] ),
			'currency_id'       => empty( $item['currency_id'] ) ? null : absint( $item['currency_id'] ),
			'is_hidden'         => absint( $item['is_hidden'] ?? ( $item['hidden'] ?? 0 ) ),
			'hidden'            => absint( $item['hidden'] ?? ( $item['is_hidden'] ?? 0 ) ),
			'statement_date'    => empty( $item['statement_date'] ) ? null : absint( $item['statement_date'] ),
			'payment_date'      => empty( $item['payment_date'] ) ? null : absint( $item['payment_date'] ),
			'is_active'         => absint( $item['is_active'] ?? 1 ),
			'default_confirmed' => absint( $item['default_confirmed'] ?? 0 ),
			'current_balance'   => (string) ( $item['current_balance'] ?? '0.0000' ),
			'transaction_count' => absint( $item['transaction_count'] ?? 0 ),
			'created_at'        => (string) ( $item['created_at'] ?? '' ),
			'updated_at'        => (string) ( $item['updated_at'] ?? '' ),
		);
	}

	/**
	 * Extract and sanitize write payload values.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	private function create_payload( WP_REST_Request $request, $for_create ) {
		$data = array();

		if ( $for_create || $request->has_param( 'name' ) ) {
			$data['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}

		if ( $for_create || $request->has_param( 'account_type' ) ) {
			$data['account_type'] = sanitize_key( (string) ( $request->get_param( 'account_type' ) ?: 'asset' ) );
		}

		if ( $for_create || $request->has_param( 'initial_balance' ) ) {
			$data['initial_balance'] = sanitize_text_field( (string) ( $request->get_param( 'initial_balance' ) ?? '0.0000' ) );
		}

		if ( $for_create || $request->has_param( 'currency' ) || $request->has_param( 'currency_code' ) ) {
			$data['currency'] = strtoupper( sanitize_text_field( (string) ( $request->get_param( 'currency' ) ?: $request->get_param( 'currency_code' ) ?: 'TWD' ) ) );
		}

		if ( $for_create || $request->has_param( 'account_group' ) ) {
			$data['account_group'] = sanitize_text_field( (string) ( $request->get_param( 'account_group' ) ?: 'cash' ) );
		}

		if ( $for_create || $request->has_param( 'group_id' ) ) {
			$group_id         = absint( $request->get_param( 'group_id' ) );
			$data['group_id'] = $group_id ? $group_id : null;
		}

		if ( $for_create || $request->has_param( 'currency_id' ) ) {
			$currency_id         = absint( $request->get_param( 'currency_id' ) );
			$data['currency_id'] = $currency_id ? $currency_id : null;
		}

		if ( $for_create || $request->has_param( 'is_hidden' ) || $request->has_param( 'hidden' ) ) {
			$data['is_hidden'] = rest_sanitize_boolean( $request->get_param( 'is_hidden' ) ?? $request->get_param( 'hidden' ) );
		}

		if ( $for_create || $request->has_param( 'is_active' ) ) {
			$data['is_active'] = rest_sanitize_boolean( $request->get_param( 'is_active' ) ?? true );
		}

		if ( $for_create || $request->has_param( 'default_confirmed' ) ) {
			$data['default_confirmed'] = rest_sanitize_boolean( $request->get_param( 'default_confirmed' ) ?? false );
		}

		if ( $for_create || $request->has_param( 'statement_date' ) ) {
			$statement_date = $request->get_param( 'statement_date' );
			$statement_date = absint( $statement_date );
			$data['statement_date'] = $statement_date ? $statement_date : null;
		}

		if ( $for_create || $request->has_param( 'payment_date' ) ) {
			$payment_date = $request->get_param( 'payment_date' );
			$payment_date = absint( $payment_date );
			$data['payment_date'] = $payment_date ? $payment_date : null;
		}

		return $data;
	}

	/**
	 * Route arguments for create/update endpoints.
	 *
	 * @param bool $require_book Whether book_id is required.
	 * @return array<string, array<string, mixed>>
	 */
	private function write_args( $require_book ) {
		return array(
			'book_id'         => array(
				'type'              => 'integer',
				'required'          => $require_book,
				'sanitize_callback' => 'absint',
			),
			'name'            => array(
				'type'              => 'string',
				'required'          => $require_book,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'account_type'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'initial_balance' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'currency'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'currency_code'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'account_group'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'group_id'        => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'currency_id'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'is_hidden'       => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'is_active'       => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'default_confirmed' => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'hidden'          => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'statement_date'  => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'payment_date'    => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}
}
