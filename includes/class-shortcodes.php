<?php
/** Shortcode rendering. */
defined( 'ABSPATH' ) || exit;

final class Balance_Beacon_Shortcodes {
	public function register() {
		add_shortcode( 'balance_transactions', array( $this, 'transactions' ) );
		add_shortcode( 'balance_settings', array( $this, 'settings' ) );
		add_shortcode( 'balance_app', array( $this, 'app' ) );
	}

	public function app( $atts = array(), $content = null ) {
		if ( ! is_user_logged_in() ) {
			return '<p>請先登入以使用記帳系統。</p>';
		}
		$atts = shortcode_atts( array( 'book_id' => '' ), $atts, 'balance_app' );
		$book_id = absint( $atts['book_id'] );
		if ( ! $book_id ) {
			global $wpdb;
			$book_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'balance_books WHERE user_id = %d ORDER BY is_default DESC, id ASC LIMIT 1', get_current_user_id() ) );
		}
		$view = isset( $_GET['balance_view'] ) ? sanitize_key( wp_unslash( $_GET['balance_view'] ) ) : 'dashboard';
		$allowed = array( 'dashboard', 'transactions', 'books', 'analytics', 'balance-history', 'categories', 'accounts', 'credit-cards', 'settings' );
		if ( ! in_array( $view, $allowed, true ) ) $view = 'dashboard';
		ob_start();
		include BALANCE_BEACON_PATH . 'templates/shortcodes/app.php';
		return ob_get_clean();
	}

	public function transactions( $atts = array(), $content = null ) {
		if ( ! is_user_logged_in() ) {
			return '<p>請先登入以檢視記帳資料。</p>';
		}

		$atts = shortcode_atts( array( 'book_id' => '' ), $atts, 'balance_transactions' );
		$book_id = absint( $atts['book_id'] );
		ob_start();
		include BALANCE_BEACON_PATH . 'templates/shortcodes/transactions.php';
		return ob_get_clean();
	}

	public function settings( $atts = array(), $content = null ) {
		if ( ! is_user_logged_in() ) {
			return '<p>請先登入以管理帳戶與分類。</p>';
		}
		$atts = shortcode_atts( array( 'book_id' => '' ), $atts, 'balance_settings' );
		$book_id = absint( $atts['book_id'] );
		ob_start();
		include BALANCE_BEACON_PATH . 'templates/shortcodes/settings.php';
		return ob_get_clean();
	}
}
