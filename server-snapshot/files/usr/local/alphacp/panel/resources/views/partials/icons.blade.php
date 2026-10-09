{{-- cPanel-jaisa clean stroke-SVG icon set (emoji nahi). Usage: @include('partials.icons', ['icon'=>'mail']) --}}
@php
    $__known = ['folder','mail','globe','database','shield','cog','user','disk','chip','gauge','queue','audit','box','lock','clock','home','services','plug','target','chart','server'];
    $ico = $icon ?? 'folder';
    if (!in_array($ico, $__known, true)) {
        switch (true) {
            case (bool) preg_match('/sec|auth|shield|pass|hotlink|modsec|firewall|block/', $ico): $ico = 'shield'; break;
            case (bool) preg_match('/user|account|reseller|contact|session/', $ico):            $ico = 'user'; break;
            case (bool) preg_match('/mail|email|forward|autorespon|filter|spam|deliver/', $ico): $ico = 'mail'; break;
            case (bool) preg_match('/dns|zone|domain|park|redirect|ssl|route/', $ico):           $ico = 'globe'; break;
            case (bool) preg_match('/db|mysql|database|postgres|sql/', $ico):                    $ico = 'database'; break;
            case (bool) preg_match('/stat|metric|chart|awstat|bandwidth|usage|monitor/', $ico):  $ico = 'chart'; break;
            case (bool) preg_match('/package|box|backup|archive|transfer/', $ico):               $ico = 'box'; break;
            case (bool) preg_match('/service|daemon|process|queue|cron|task|reboot/', $ico):     $ico = 'services'; break;
            case (bool) preg_match('/config|setting|tweak|php|software|plugin|theme/', $ico):    $ico = 'cog'; break;
            case (bool) preg_match('/log|audit|report/', $ico):                                  $ico = 'audit'; break;
            default: $ico = 'chip'; break;
        }
    }
@endphp
@switch($ico)
    @case('folder')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        @break
    @case('mail')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg>
        @break
    @case('globe')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z"/><path d="M3 12h18"/><path d="M12 3c2.5 2.6 3.8 5.7 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.7-3.8-9s1.3-6.4 3.8-9z"/></svg>
        @break
    @case('database')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c4.4 0 8 1.3 8 3s-3.6 3-8 3-8-1.3-8-3 3.6-3 8-3z"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
        @break
    @case('shield')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v6c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6z"/></svg>
        @break
    @case('cog')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/></svg>
        @break
    @case('user')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 20c0-3.3 3.6-5 8-5s8 1.7 8 5"/></svg>
        @break
    @case('disk')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5h14v14H5z"/><path d="M9 5v5h6V5"/><path d="M8 14h8v5H8z"/></svg>
        @break
    @case('chip')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 9h6v6H9z"/><path d="M4 4h16v16H4z"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"/></svg>
        @break
    @case('gauge')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14a8 8 0 0 1 16 0"/><path d="M12 14l4-4"/></svg>
        @break
    @case('queue')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
        @break
    @case('audit')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l4-1 11-11-3-3L5 16z"/><path d="M14 6l3 3"/></svg>
        @break
    @case('box')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8l9-4 9 4v8l-9 4-9-4z"/><path d="M3 8l9 4 9-4"/><path d="M12 12v8"/></svg>
        @break
    @case('lock')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 11h12v9H6z"/><path d="M9 11V8a3 3 0 0 1 6 0v3"/></svg>
        @break
    @case('clock')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z"/><path d="M12 7v5l3 3"/></svg>
        @break
    @case('home')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11l8-7 8 7"/><path d="M6 10v10h12V10"/></svg>
        @break
    @case('services')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5h6v6H5z"/><path d="M13 13h6v6h-6z"/><path d="M13 5h6v6h-6z"/><path d="M5 13h6v6H5z"/></svg>
        @break
    @case('plug')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3v5M15 3v5"/><path d="M7 8h10v4a5 5 0 0 1-10 0z"/><path d="M12 17v4"/></svg>
        @break
    @case('target')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z"/><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z"/><path d="M12 11.5a.5.5 0 1 0 0 1 .5.5 0 0 0 0-1z"/></svg>
        @break
    @case('chart')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V6"/><path d="M4 20h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-3"/></svg>
        @break
    @case('box')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8l9-4 9 4v8l-9 4-9-4z"/><path d="M3 8l9 4 9-4"/><path d="M12 12v8"/></svg>
        @break
    @case('server')
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>
        @break
    @default
        <svg class="{{ $cls ?? 'ico' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
@endswitch
