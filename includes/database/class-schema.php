<?php
/**
 * Database schema installer for Balance Beacon.
 *
 * @package Balance_Beacon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates Balance Beacon custom tables.
 */
class Balance_Beacon_Schema {
	/**
	 * Install or update all plugin tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::get_table_names();

		$sql = array(
			"CREATE TABLE {$tables['books']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				description text NULL,
				currency_code varchar(10) NOT NULL DEFAULT 'TWD',
				is_default tinyint(1) NOT NULL DEFAULT 0,
				settings longtext NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY currency_code (currency_code),
				KEY is_default (is_default)
			) {$charset_collate};",
			"CREATE TABLE {$tables['accounts']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				initial_balance decimal(15,4) NOT NULL DEFAULT 0.0000,
				currency varchar(10) NOT NULL DEFAULT 'TWD',
				account_group varchar(50) NOT NULL DEFAULT 'cash',
				is_hidden tinyint(1) NOT NULL DEFAULT 0,
				statement_date tinyint(3) unsigned NULL DEFAULT NULL,
				payment_date tinyint(3) unsigned NULL DEFAULT NULL,
				account_type varchar(50) NOT NULL DEFAULT 'asset',
				currency_code varchar(10) NOT NULL DEFAULT 'TWD',
				opening_balance decimal(20,4) NOT NULL DEFAULT 0.0000,
				current_balance decimal(20,4) NOT NULL DEFAULT 0.0000,
				group_id bigint(20) unsigned NULL,
				currency_id bigint(20) unsigned NULL,
				hidden tinyint(1) NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				default_confirmed tinyint(1) NOT NULL DEFAULT 0,
				settings longtext NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY group_id (group_id),
				KEY currency_id (currency_id),
				KEY account_group (account_group),
				KEY is_hidden (is_hidden),
				KEY statement_date (statement_date),
				KEY payment_date (payment_date),
				KEY account_type (account_type),
				KEY currency_code (currency_code),
				KEY is_active (is_active)
			) {$charset_collate};",
			"CREATE TABLE {$tables['account_settings']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				account_id bigint(20) unsigned NOT NULL,
				setting_key varchar(191) NOT NULL,
				setting_value longtext NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY account_setting (account_id, setting_key),
				KEY user_id (user_id),
				KEY book_id (book_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables['categories']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NULL DEFAULT NULL,
				name varchar(191) NOT NULL,
				category_type varchar(50) NOT NULL DEFAULT 'expense',
				color varchar(20) NULL,
				icon varchar(100) NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				is_hidden tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY parent_id (parent_id),
				KEY category_type (category_type),
				KEY is_active (is_active),
				KEY is_hidden (is_hidden)
			) {$charset_collate};",
			"CREATE TABLE {$tables['transactions']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				account_id bigint(20) unsigned NOT NULL,
				category_id bigint(20) unsigned NULL,
				transfer_account_id bigint(20) unsigned NULL,
				transaction_type varchar(50) NOT NULL DEFAULT 'expense',
				transaction_date date NOT NULL,
				amount decimal(20,4) NOT NULL DEFAULT 0.0000,
				to_amount decimal(15,4) NULL,
				currency_code varchar(10) NOT NULL DEFAULT 'TWD',
				exchange_rate decimal(15,6) NULL,
				description text NULL,
				store_name varchar(191) NULL,
				store_id bigint(20) unsigned NULL,
				tag_id bigint(20) unsigned NULL,
				member_id bigint(20) unsigned NULL,
				payment_method varchar(100) NULL,
				metadata longtext NULL,
				confirmed tinyint(1) NULL,
				is_reconciled tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY account_id (account_id),
				KEY category_id (category_id),
				KEY transfer_account_id (transfer_account_id),
				KEY store_id (store_id),
				KEY tag_id (tag_id),
				KEY member_id (member_id),
				KEY transaction_type (transaction_type),
				KEY transaction_date (transaction_date),
				KEY is_reconciled (is_reconciled)
			) {$charset_collate};",
			"CREATE TABLE {$tables['transaction_tags']} (
				transaction_id bigint(20) unsigned NOT NULL,
				tag_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (transaction_id, tag_id),
				KEY transaction_id (transaction_id),
				KEY tag_id (tag_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables['transaction_members']} (
				transaction_id bigint(20) unsigned NOT NULL,
				member_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (transaction_id, member_id),
				KEY transaction_id (transaction_id),
				KEY member_id (member_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables['transaction_remarks']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				transaction_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				remark text NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY transaction_id (transaction_id),
				KEY user_id (user_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables['tags']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				color varchar(20) NULL,
				is_hidden tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY book_tag_name (book_id, name),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY is_hidden (is_hidden)
			) {$charset_collate};",
			"CREATE TABLE {$tables['members']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				email varchar(191) NULL,
				color varchar(20) NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				is_hidden tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY is_active (is_active),
				KEY is_hidden (is_hidden)
			) {$charset_collate};",
			"CREATE TABLE {$tables['stores']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				aliases text NULL,
				metadata longtext NULL,
				is_hidden tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY name (name),
				KEY is_hidden (is_hidden)
			) {$charset_collate};",
			"CREATE TABLE {$tables['account_groups']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY book_group_name (book_id, name),
				KEY user_id (user_id),
				KEY book_id (book_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables['currencies']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				code varchar(10) NOT NULL,
				name varchar(191) NOT NULL,
				symbol varchar(20) NULL,
				decimals tinyint(3) unsigned NOT NULL DEFAULT 2,
				exchange_rate decimal(20,8) NOT NULL DEFAULT 1.00000000,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY book_currency_code (book_id, code),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY code (code),
				KEY is_active (is_active)
			) {$charset_collate};",
			"CREATE TABLE {$tables['credit_card_cycles']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				account_id bigint(20) unsigned NOT NULL,
				statement_day tinyint(3) unsigned NOT NULL,
				due_day tinyint(3) unsigned NOT NULL,
				grace_period_days smallint(5) unsigned NOT NULL DEFAULT 0,
				settings longtext NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY account_id (account_id),
				KEY is_active (is_active)
			) {$charset_collate};",
			"CREATE TABLE {$tables['credit_card_reminders']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				account_id bigint(20) unsigned NOT NULL,
				cycle_id bigint(20) unsigned NULL,
				remind_at datetime NOT NULL,
				status varchar(50) NOT NULL DEFAULT 'pending',
				message text NULL,
				metadata longtext NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY account_id (account_id),
				KEY cycle_id (cycle_id),
				KEY remind_at (remind_at),
				KEY status (status)
			) {$charset_collate};",
			"CREATE TABLE {$tables['billing_cycles']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				account_id bigint(20) unsigned NOT NULL,
				cycle_start date NOT NULL,
				cycle_end date NOT NULL,
				due_date date NULL,
				total_amount decimal(20,4) NOT NULL DEFAULT 0.0000,
				paid_amount decimal(20,4) NOT NULL DEFAULT 0.0000,
				status varchar(50) NOT NULL DEFAULT 'open',
				metadata longtext NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY account_id (account_id),
				KEY cycle_start (cycle_start),
				KEY cycle_end (cycle_end),
				KEY status (status)
			) {$charset_collate};",
			"CREATE TABLE {$tables['balance_history']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				book_id bigint(20) unsigned NOT NULL,
				account_id bigint(20) unsigned NULL,
				history_date date NOT NULL,
				balance decimal(20,4) NOT NULL DEFAULT 0.0000,
				currency_code varchar(10) NOT NULL DEFAULT 'TWD',
				metadata longtext NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY book_id (book_id),
				KEY account_id (account_id),
				KEY history_date (history_date),
				KEY currency_code (currency_code)
			) {$charset_collate};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Get plugin table names using the current WordPress table prefix.
	 *
	 * @return array<string, string>
	 */
	public static function get_table_names() {
		global $wpdb;

		return array(
			'books'                 => $wpdb->prefix . 'balance_books',
			'accounts'              => $wpdb->prefix . 'balance_accounts',
			'account_settings'      => $wpdb->prefix . 'balance_account_settings',
			'categories'            => $wpdb->prefix . 'balance_categories',
			'transactions'          => $wpdb->prefix . 'balance_transactions',
			'transaction_tags'      => $wpdb->prefix . 'balance_transaction_tags',
			'transaction_members'   => $wpdb->prefix . 'balance_transaction_members',
			'transaction_remarks'   => $wpdb->prefix . 'balance_transaction_remarks',
			'tags'                  => $wpdb->prefix . 'balance_tags',
			'members'               => $wpdb->prefix . 'balance_members',
			'stores'                => $wpdb->prefix . 'balance_stores',
			'account_groups'        => $wpdb->prefix . 'balance_account_groups',
			'currencies'            => $wpdb->prefix . 'balance_currencies',
			'credit_card_cycles'    => $wpdb->prefix . 'balance_credit_card_cycles',
			'credit_card_reminders' => $wpdb->prefix . 'balance_credit_card_reminders',
			'billing_cycles'        => $wpdb->prefix . 'balance_billing_cycles',
			'balance_history'       => $wpdb->prefix . 'balance_balance_history',
		);
	}
}
