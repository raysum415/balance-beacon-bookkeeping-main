<?php
defined( 'ABSPATH' ) || exit;

$app_url = get_permalink();
$views   = array(
	'dashboard'      => 'Dashboard',
	'transactions'   => '交易',
	'books'          => '帳本',
	'analytics'      => '分析',
	'balance-history' => '餘額歷史',
	'categories'     => '分類',
	'accounts'       => '帳戶',
	'credit-cards'   => '信用卡',
	'settings'       => '設定',
);
$user    = wp_get_current_user();
$avatar  = get_avatar_url( $user->ID, array( 'size' => 64 ) );
?>
<div id="balance-app-root" class="alignfull flex h-screen w-full max-w-full overflow-hidden bg-gray-100" data-balance-view="<?php echo esc_attr( $view ); ?>">
	<div id="balance-app-sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-black/40 md:hidden"></div>
	<aside id="balance-app-sidebar" class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full border-r bg-white p-4 shadow-lg transition-transform md:static md:translate-x-0 md:flex md:shrink-0 md:flex-col">
		<div class="mb-6 flex items-center justify-between">
			<span class="text-xl font-bold text-blue-700">Balance Beacon</span>
			<button id="balance-app-sidebar-close" type="button" class="text-2xl text-gray-500 md:hidden" aria-label="關閉選單">&times;</button>
		</div>
		<nav class="space-y-1">
			<?php foreach ( $views as $key => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'balance_view', $key, $app_url ) ); ?>" class="block rounded-lg px-3 py-2 <?php echo $view === $key ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-100'; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
	</aside>

	<div class="flex min-w-0 flex-1 flex-col">
		<header class="sticky top-0 z-20 flex shrink-0 items-center justify-between border-b bg-white px-4 py-3 shadow-sm md:px-6">
			<div class="flex items-center gap-3">
				<button id="balance-app-menu-toggle" type="button" class="rounded p-2 text-xl hover:bg-gray-100 md:hidden" aria-label="開啟選單">&#9776;</button>
				<h1 class="text-lg font-semibold"><?php echo esc_html( $views[ $view ] ); ?></h1>
			</div>
			<div class="flex items-center gap-3">
				<a href="<?php echo esc_url( add_query_arg( 'balance_view', 'transactions', $app_url ) . '#btn-add-transaction' ); ?>" class="rounded bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700">快速新增交易</a>
				<div class="hidden text-right sm:block">
					<div class="text-sm font-medium"><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></div>
					<div class="text-xs text-gray-500"><?php echo esc_html( $user->user_email ); ?></div>
				</div>
				<img class="h-9 w-9 rounded-full" src="<?php echo esc_url( $avatar ); ?>" alt="使用者頭像">
			</div>
		</header>

		<main class="min-h-0 w-full flex-1 overflow-y-auto">
			<div class="mx-auto w-full max-w-7xl p-4 md:p-6">
				<?php if ( 'transactions' === $view ) : ?>
					<?php include BALANCE_BEACON_PATH . 'templates/shortcodes/transactions.php'; ?>
				<?php elseif ( 'settings' === $view ) : ?>
					<?php include BALANCE_BEACON_PATH . 'templates/shortcodes/settings.php'; ?>
				<?php else : ?>
					<div class="rounded-lg bg-white p-10 text-center shadow">
						<h2 class="text-xl font-semibold">此功能頁面尚未移植</h2>
						<p class="mt-2 text-gray-500">目前可使用交易與設定功能。</p>
						<a class="mt-4 inline-block text-blue-600 hover:underline" href="<?php echo esc_url( add_query_arg( 'balance_view', 'transactions', $app_url ) ); ?>">前往交易</a>
					</div>
				<?php endif; ?>
			</div>
		</main>
	</div>
</div>
