<?php
/**
 * Tag REST controller.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes tag CRUD routes.
 */
final class Balance_Beacon_REST_Tag_Controller extends Balance_Beacon_REST_Base_Controller {
	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tags' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'book_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tags',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_tag' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tags/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_tag' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tags/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_tag' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);
	}

	public function get_tags( WP_REST_Request $request ) {
		try {
			return rest_ensure_response(
				( new Balance_Beacon_Tag_Service() )->get_all(
					absint( $request->get_param( 'book_id' ) ),
					get_current_user_id()
				)
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	public function create_tag( WP_REST_Request $request ) {
		try {
			$id = ( new Balance_Beacon_Tag_Service() )->create(
				$this->payload( $request ),
				get_current_user_id()
			);

			return rest_ensure_response(
				array(
					'id'      => $id,
					'success' => true,
				)
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	public function update_tag( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Tag_Service() )->update(
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

	public function delete_tag( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Tag_Service() )->delete( absint( $request['id'] ), get_current_user_id() );

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

	private function payload( WP_REST_Request $request ) {
		$payload = array(
			'book_id' => absint( $request->get_param( 'book_id' ) ),
			'name'    => sanitize_text_field( $request->get_param( 'name' ) ),
		);
		if ( $request->has_param( 'is_hidden' ) ) $payload['is_hidden'] = rest_sanitize_boolean( $request->get_param( 'is_hidden' ) ) ? 1 : 0;
		return $payload;
	}
}
