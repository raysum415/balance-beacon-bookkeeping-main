(function (window, document) {
    'use strict';
    var api = window.BalanceBeaconUI = {};
    api.showToast = function (message, type) {
        type = type || 'success';
        var host = document.getElementById('balance-beacon-toast-host');
        if (!host) { host = document.createElement('div'); host.id = 'balance-beacon-toast-host'; host.className = 'fixed right-4 top-4 z-[100] space-y-2'; document.body.appendChild(host); }
        var toast = document.createElement('div');
        toast.className = 'translate-y-0 rounded-lg px-4 py-3 text-sm text-white shadow-lg transition-all duration-300 ' + (type === 'error' ? 'bg-red-600' : 'bg-green-600');
        toast.textContent = message || '';
        host.appendChild(toast);
        window.setTimeout(function () { toast.classList.add('translate-x-8', 'opacity-0'); window.setTimeout(function () { toast.remove(); }, 300); }, 3200);
    };
    api.setButtonLoading = function (button, loading) {
        if (!button) return;
        if (loading) { button.dataset.originalContent = button.innerHTML; button.disabled = true; button.classList.add('opacity-60', 'cursor-wait'); button.innerHTML = '<span class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent align-[-2px]"></span>處理中...'; }
        else { button.disabled = false; button.classList.remove('opacity-60', 'cursor-wait'); if (button.dataset.originalContent) button.innerHTML = button.dataset.originalContent; }
    };
    api.showSkeleton = function (container) { if (!container) return; container.innerHTML = '<div class="animate-pulse space-y-3"><div class="h-4 rounded bg-gray-200"></div><div class="h-4 w-5/6 rounded bg-gray-200"></div><div class="h-4 w-2/3 rounded bg-gray-200"></div></div>'; };
    window.showToast = api.showToast;
    window.setButtonLoading = api.setButtonLoading;
    window.showSkeleton = api.showSkeleton;
}(window, document));
