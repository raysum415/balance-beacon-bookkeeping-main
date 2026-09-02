<?php
/**
 * Currency REST controller.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes currency CRUD routes.
 */
final class Balance_Beacon_REST_Currency_Controller extends Balance_Beacon_REST_Base_Controller {
	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/currencies',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => function() { return is_user_logged_in(); },
					'args'                => array(
						'book_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => function() { return is_user_logged_in(); },
					'args'                => $this->write_args( true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/currencies/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => function() { return is_user_logged_in(); },
					'args'                => $this->write_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => function() { return is_user_logged_in(); },
				),
			)
		);
	}

	/**
	 * Handles GET /currencies.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( WP_REST_Request $request ) {
		try {
			return rest_ensure_response(
				( new Balance_Beacon_Currency_Service() )->get_all(
					absint( $request->get_param( 'book_id' ) ),
					get_current_user_id()
				)
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles POST /currencies.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$book_id = $this->resolve_user_book_id( absint( $request->get_param( 'book_id' ) ), $user_id );

		if ( is_wp_error( $book_id ) ) {
			return $book_id;
		}

		$payload            = $this->payload( $request );
		$payload['book_id'] = $book_id;

		try {
			$id = ( new Balance_Beacon_Currency_Service() )->create( $payload, $user_id );

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
	 * Handles PUT /currencies/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Currency_Service() )->update(
				absint( $request['id'] ),
				$this->payload( $request ),
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
	 * Handles DELETE /currencies/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Currency_Service() )->delete( absint( $request['id'] ), get_current_user_id() );

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
	 * Extract write payload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	private function payload( WP_REST_Request $request ) {
		return array(
			'book_id'  => absint( $request->get_param( 'book_id' ) ),
			'code'     => strtoupper( sanitize_text_field( (string) $request->get_param( 'code' ) ) ),
			'name'     => sanitize_text_field( (string) $request->get_param( 'name' ) ),
			'symbol'   => sanitize_text_field( (string) $request->get_param( 'symbol' ) ),
			'decimals' => absint( $request->get_param( 'decimals' ) ?? 2 ),
		);
	}

	/**
	 * Route arguments for write endpoints.
	 *
	 * @param bool $require_book Whether book_id is required.
	 * @return array<string, array<string, mixed>>
	 */
	private function write_args( $require_book ) {
		return array(
			'book_id'  => array(
				'type'              => 'integer',
				'required'          => $require_book,
				'sanitize_callback' => 'absint',
			),
			'code'     => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'name'     => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'symbol'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'decimals' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}
}
