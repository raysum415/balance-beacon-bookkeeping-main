<?php
/**
 * Category REST controller.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes category CRUD endpoints to the frontend.
 */
final class Balance_Beacon_REST_Category_Controller extends Balance_Beacon_REST_Base_Controller {
	/**
	 * Registers category routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'book_id' => array(
							'description'       => 'Book ID owned by the current user.',
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_category' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_category' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Handles GET /categories.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_categories( WP_REST_Request $request ) {
		try {
			$user_id = get_current_user_id();
			$book_id = $this->resolve_user_book_id( absint( $request->get_param( 'book_id' ) ), $user_id );

			if ( is_wp_error( $book_id ) ) {
				return $book_id;
			}

			$rows = ( new Balance_Beacon_Category_Service() )->get_categories_with_counts( $book_id, $user_id );

			return rest_ensure_response(
				array_map(
					array( $this, 'prepare_item_for_response' ),
					$rows
				)
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles POST /categories.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( WP_REST_Request $request ) {
		try {
			$user_id = get_current_user_id();
			$book_id = $this->resolve_user_book_id( absint( $request->get_param( 'book_id' ) ), $user_id );

			if ( is_wp_error( $book_id ) ) {
				return $book_id;
			}

			$id = ( new Balance_Beacon_Category_Service() )->create_category(
				$this->create_payload( $request, $book_id ),
				$user_id
			);

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
	 * Handles PUT /categories/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_category( WP_REST_Request $request ) {
		try {
			$user_id = get_current_user_id();
			$book_id = $this->resolve_user_book_id( absint( $request->get_param( 'book_id' ) ), $user_id );

			if ( is_wp_error( $book_id ) ) {
				return $book_id;
			}

			( new Balance_Beacon_Category_Service() )->update_category(
				absint( $request['id'] ),
				$this->create_payload( $request, $book_id ),
				$user_id
			);

			return rest_ensure_response(
				array(
					'id'      => absint( $request['id'] ),
					'book_id' => (int) $book_id,
					'success' => true,
				)
			);
		} catch ( Throwable $exception ) {
			return $this->service_error( $exception );
		}
	}

	/**
	 * Handles DELETE /categories/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_category( WP_REST_Request $request ) {
		try {
			( new Balance_Beacon_Category_Service() )->delete_category(
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
	 * Builds a sanitized category payload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param int             $book_id Resolved user-owned book ID.
	 * @return array<string, mixed>
	 */
	private function create_payload( WP_REST_Request $request, $book_id ) {
		$type = sanitize_key( $request->get_param( 'category_type' ) ?: $request->get_param( 'type' ) ?: 'expense' );

		$payload = array(
			'book_id'       => absint( $book_id ),
			'name'          => sanitize_text_field( $request->get_param( 'name' ) ),
			'category_type' => $type,
			'parent_id'     => $this->nullable_id( $request->get_param( 'parent_id' ) ),
		);
		if ( $request->has_param( 'is_hidden' ) ) {
			$payload['is_hidden'] = rest_sanitize_boolean( $request->get_param( 'is_hidden' ) ) ? 1 : 0;
		}
		return $payload;
	}

	/**
	 * Normalizes empty ID values to null.
	 *
	 * @param mixed $value Raw request value.
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
	 * Prepares category rows for JSON responses.
	 *
	 * @param array<string, mixed> $item Raw database row.
	 * @return array<string, mixed>
	 */
	public function prepare_item_for_response( $item ) {
		$type = isset( $item['type'] ) ? $item['type'] : ( isset( $item['category_type'] ) ? $item['category_type'] : 'expense' );

		return array(
			'id'                => isset( $item['id'] ) ? (int) $item['id'] : 0,
			'book_id'           => isset( $item['book_id'] ) ? (int) $item['book_id'] : 0,
			'user_id'           => isset( $item['user_id'] ) ? (int) $item['user_id'] : 0,
			'parent_id'         => ! empty( $item['parent_id'] ) ? (int) $item['parent_id'] : null,
			'name'              => isset( $item['name'] ) ? $item['name'] : '',
			'type'              => $type,
			'category_type'     => $type,
			'hidden'            => ! empty( $item['hidden'] ) ? (int) $item['hidden'] : 0,
			'is_hidden'         => ! empty( $item['is_hidden'] ) ? (int) $item['is_hidden'] : 0,
			'transaction_count' => isset( $item['transaction_count'] ) ? (int) $item['transaction_count'] : 0,
			'created_at'        => isset( $item['created_at'] ) ? $item['created_at'] : null,
			'updated_at'        => isset( $item['updated_at'] ) ? $item['updated_at'] : null,
		);
	}
}
