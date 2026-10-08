{{-- Dispatcher: DashboardController ab dashboard-whm / dashboard-cpanel use karta hai. --}}
@include($panelMode === 'whm' ? 'dashboard-whm' : 'dashboard-cpanel', [])
