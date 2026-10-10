{{--
  AlphaCP icon sprite — cPanel-style stroke icons (24x24 grid, stroke 1.8, round caps).
  Loaded once per page, before any @include('partials.icon'). Pure SVG, no emoji, no
  external font dependency. Icon names are referenced from config/panel_modules.php;
  tools/sim/theme-check.py verifies every referenced icon exists here.
  Shapes inherit fill:none / stroke:currentColor from the consuming <svg class="ic">;
  a few glyphs need solid dots → they set fill="currentColor" stroke="none" inline
  (presentation attributes beat inherited CSS, so this is safe).
--}}
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
  <symbol id="i-home" viewBox="0 0 24 24"><path d="M4 11l8-7 8 7"/><path d="M6 9.5V20a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9.5"/><path d="M10 21v-6h4v6"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/></symbol>
  <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>
  <symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7.5l9 6 9-6"/></symbol>
  <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.7 2.7 4 5.7 4 9s-1.3 6.3-4 9c-2.7-2.7-4-5.7-4-9s1.3-6.3 4-9z"/></symbol>
  <symbol id="i-database" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5"/><path d="M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></symbol>
  <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20h16"/><path d="M7 20v-6"/><path d="M12 20V8"/><path d="M17 20v-9"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l7 2.8V11c0 4.6-3 8.4-7 10-4-1.6-7-5.4-7-10V5.8z"/></symbol>
  <symbol id="i-shield-check" viewBox="0 0 24 24"><path d="M12 3l7 2.8V11c0 4.6-3 8.4-7 10-4-1.6-7-5.4-7-10V5.8z"/><path d="M9 11.5l2 2 4-4.5"/></symbol>
  <symbol id="i-box" viewBox="0 0 24 24"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5"/><path d="M12 12L4 7.5"/><path d="M12 12v9"/></symbol>
  <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M5 4v6"/><path d="M5 14v6"/><path d="M12 4v10"/><path d="M12 18v2"/><path d="M19 4v2"/><path d="M19 10v10"/><path d="M2.5 12h5"/><path d="M9.5 16h5"/><path d="M16.5 8h5"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c.8-3.6 3.9-5.5 7.5-5.5s6.7 1.9 7.5 5.5"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 20c.7-3.2 3.3-5 6.5-5s5.8 1.8 6.5 5"/><path d="M16 5.6a3.5 3.5 0 0 1 0 5.8"/><path d="M17.8 15.4c2 .8 3.3 2.4 3.7 4.6"/></symbol>
  <symbol id="i-server" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01"/><path d="M7 16.5h.01"/></symbol>
  <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></symbol>
  <symbol id="i-chevron-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
  <symbol id="i-memory" viewBox="0 0 24 24"><rect x="7" y="7" width="10" height="10" rx="2"/><path d="M9 3v4"/><path d="M15 3v4"/><path d="M9 17v4"/><path d="M15 17v4"/><path d="M3 9h4"/><path d="M3 15h4"/><path d="M17 9h4"/><path d="M17 15h4"/></symbol>
  <symbol id="i-hdd" viewBox="0 0 24 24"><path d="M3 13l2.2-6.1A2 2 0 0 1 7.1 5.5h9.8a2 2 0 0 1 1.9 1.4L21 13v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 13h18"/><path d="M6.5 17h6"/><circle cx="17.5" cy="17" r="1" fill="currentColor" stroke="none"/></symbol>
  <symbol id="i-cpu" viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9.5" y="9.5" width="5" height="5" rx="1"/><path d="M9 2v3"/><path d="M15 2v3"/><path d="M9 19v3"/><path d="M15 19v3"/><path d="M2 9h3"/><path d="M2 15h3"/><path d="M19 9h3"/><path d="M19 15h3"/></symbol>
  <symbol id="i-list" viewBox="0 0 24 24"><path d="M8.5 6h12"/><path d="M8.5 12h12"/><path d="M8.5 18h12"/><path d="M3.5 6h.01"/><path d="M3.5 12h.01"/><path d="M3.5 18h.01"/></symbol>
  <symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 9.5a6 6 0 1 0-12 0c0 5.5-2.2 6.8-2.2 6.8h16.4S18 15 18 9.5"/><path d="M10.3 20a2 2 0 0 0 3.4 0"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16.5 9"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3.5L2.8 19.5h18.4z"/><path d="M12 10v4.5"/><path d="M12 17.5h.01"/></symbol>
  <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></symbol>
  <symbol id="i-terminal" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9l3 3-3 3"/><path d="M12.5 15H17"/></symbol>
  <symbol id="i-file" viewBox="0 0 24 24"><path d="M6 3h7.5L19 8.5V20a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M13.5 3v5.5H19"/></symbol>
  <symbol id="i-key" viewBox="0 0 24 24"><circle cx="7.5" cy="16" r="4"/><path d="M10.5 13L21 2.5"/><path d="M15.5 5.5l3 3"/><path d="M12.5 8.5l2 2"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></symbol>
  <symbol id="i-arrow-left" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="M11 18l-6-6 6-6"/></symbol>
  <symbol id="i-gauge" viewBox="0 0 24 24"><path d="M4.5 17.5a8.5 8.5 0 1 1 15 0"/><path d="M12 14l4-4.5"/><circle cx="12" cy="14.5" r="1.2" fill="currentColor" stroke="none"/></symbol>
  <symbol id="i-star" viewBox="0 0 24 24"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path d="M3.5 6.5h17"/><path d="M8.5 6.5V4.5a1 1 0 0 1 1-1h5a1 1 0 0 1 1 1v2"/><path d="M18.5 6.5l-.9 13a2 2 0 0 1-2 1.9h-7.2a2 2 0 0 1-2-1.9l-.9-13"/><path d="M10 10.5v6"/><path d="M14 10.5v6"/></symbol>
  <symbol id="i-archive" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4.5" rx="1"/><path d="M5 8.5V19a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19V8.5"/><path d="M10 12.5h4"/></symbol>
  <symbol id="i-git" viewBox="0 0 24 24"><path d="M6 3v12"/><circle cx="18" cy="6" r="2.6"/><circle cx="6" cy="18" r="2.6"/><path d="M18 8.6a9 9 0 0 1-9 9"/></symbol>
  <symbol id="i-cloud" viewBox="0 0 24 24"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></symbol>
  <symbol id="i-cog" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3"/><path d="M12 18.5v3"/><path d="M2.5 12h3"/><path d="M18.5 12h3"/><path d="M5.3 5.3l2.1 2.1"/><path d="M16.6 16.6l2.1 2.1"/><path d="M18.7 5.3l-2.1 2.1"/><path d="M7.4 16.6l-2.1 2.1"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></symbol>
  <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></symbol>
  <symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 15.5L16.5 11 8 19.5"/></symbol>
  <symbol id="i-send" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></symbol>
  <symbol id="i-inbox" viewBox="0 0 24 24"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></symbol>
  <symbol id="i-filter" viewBox="0 0 24 24"><path d="M22 3.5H2l8 9.5v6l4 2.5v-8.5z"/></symbol>
  <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M3 10.5h18"/></symbol>
  <symbol id="i-link" viewBox="0 0 24 24"><path d="M10 13.5a5 5 0 0 0 7.5.5l2.5-2.5a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 10.5a5 5 0 0 0-7.5-.5L4 12.5a5 5 0 0 0 7 7l1.5-1.5"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14"/><path d="M5 12h14"/></symbol>
  <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="11.5" height="11.5" rx="2"/><path d="M5.5 15H4.5a2 2 0 0 1-2-2V4.5a2 2 0 0 1 2-2H13a2 2 0 0 1 2 2v1"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></symbol>
  <symbol id="i-external" viewBox="0 0 24 24"><path d="M14 4h6v6"/><path d="M20 4L11 13"/><path d="M19 13.5V19a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5.5"/></symbol>
  <symbol id="i-wrench" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></symbol>
</svg>
