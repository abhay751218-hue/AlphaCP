
<?php
    $__known = ['folder','mail','globe','database','shield','shield-check','cog','user','users','disk','hdd','chip','gauge','queue','audit','box','lock','clock','home','services','plug','target','chart','server','file','image','send','archive','git','trash','inbox','filter','calendar','key','terminal','refresh','alert','wrench','list','sliders','dns','ban'];
    $ico = $icon ?? 'folder';
    if (!in_array($ico, $__known, true)) {
        $h = strtolower($ico); // hint: tool name + route
        switch (true) {
            // files
            case (bool) preg_match('/file manager|file restoration|file and directory|mime/', $h): $ico = 'file'; break;
            case (bool) preg_match('/image/', $h):                                   $ico = 'image'; break;
            case (bool) preg_match('/privacy|password|two-factor|2fa/', $h):         $ico = 'lock'; break;
            case (bool) preg_match('/disk|quota/', $h):                              $ico = 'hdd'; break;
            case (bool) preg_match('/ftp/', $h):                                     $ico = 'send'; break;
            case (bool) preg_match('/backup|archive/', $h):                          $ico = 'archive'; break;
            case (bool) preg_match('/git/', $h):                                     $ico = 'git'; break;
            case (bool) preg_match('/trash/', $h):                                   $ico = 'trash'; break;
            // email
            case (bool) preg_match('/forwarder|routing|importer/', $h):              $ico = 'inbox'; break;
            case (bool) preg_match('/filter|spam|boxtrapper/', $h):                  $ico = 'filter'; break;
            case (bool) preg_match('/calendar/', $h):                                $ico = 'calendar'; break;
            case (bool) preg_match('/encryption|token|api|key|license/', $h):        $ico = 'key'; break;
            case (bool) preg_match('/mail|autorespon|deliver|address/', $h):         $ico = 'mail'; break;
            // domains / dns
            case (bool) preg_match('/zone|dns|nameserver|cluster/', $h):             $ico = 'dns'; break;
            case (bool) preg_match('/domain|alias|redirect|park|forwarding|network/', $h): $ico = 'globe'; break;
            // databases
            case (bool) preg_match('/mysql|database|postgres|phpmyadmin|sql/', $h):  $ico = 'database'; break;
            // metrics
            case (bool) preg_match('/visitors|bandwidth|awstats|raw access|resource|metric|stat|usage|monitor|errors$/', $h): $ico = 'chart'; break;
            // security
            case (bool) preg_match('/ssl|tls|certificate/', $h):                     $ico = 'shield-check'; break;
            case (bool) preg_match('/ip blocker|block|ban|leech/', $h):              $ico = 'ban'; break;
            case (bool) preg_match('/sec|auth|shield|hotlink|modsec|firewall|policies|policy/', $h): $ico = 'shield'; break;
            case (bool) preg_match('/session/', $h):                                 $ico = 'clock'; break;
            // software
            case (bool) preg_match('/ssh|terminal|shell|composer|node/', $h):        $ico = 'terminal'; break;
            case (bool) preg_match('/wordpress|installer|toolkit|software|optimize|app/', $h): $ico = 'box'; break;
            case (bool) preg_match('/php|ini editor/', $h):                          $ico = 'cog'; break;
            case (bool) preg_match('/cron/', $h):                                    $ico = 'clock'; break;
            // advanced
            case (bool) preg_match('/indexes/', $h):                                 $ico = 'list'; break;
            case (bool) preg_match('/error pages/', $h):                             $ico = 'alert'; break;
            case (bool) preg_match('/handlers|apache|tweak|setting|config/', $h):    $ico = 'wrench'; break;
            // whm
            case (bool) preg_match('/account|user|reseller|contact/', $h):           $ico = 'users'; break;
            case (bool) preg_match('/package/', $h):                                 $ico = 'box'; break;
            case (bool) preg_match('/transfer|restore|review|sync|update/', $h):     $ico = 'refresh'; break;
            case (bool) preg_match('/service|daemon|process|queue|task|reboot|status|system information/', $h): $ico = 'gauge'; break;
            case (bool) preg_match('/audit|log|report/', $h):                        $ico = 'audit'; break;
            default: $ico = 'chip'; break;
        }
    }
    // legacy alias
    if ($ico === 'disk') { $ico = 'hdd'; }
    $cls = $cls ?? 'ico';
    $__svg = '<svg class="' . e($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
?>
<?php switch($ico):
    case ('folder'): ?><?php echo $__svg; ?><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg><?php break; ?>
    <?php case ('file'): ?><?php echo $__svg; ?><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 13h6M9 17h6"/></svg><?php break; ?>
    <?php case ('image'): ?><?php echo $__svg; ?><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M4 17l5-4 3 2 4-4 4 4"/></svg><?php break; ?>
    <?php case ('mail'): ?><?php echo $__svg; ?><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg><?php break; ?>
    <?php case ('inbox'): ?><?php echo $__svg; ?><path d="M4 5h16v14H4z"/><path d="M4 13h5l1.5 2h3L15 13h5"/></svg><?php break; ?>
    <?php case ('filter'): ?><?php echo $__svg; ?><path d="M4 6h16l-6 7v5l-4 2v-7z"/></svg><?php break; ?>
    <?php case ('send'): ?><?php echo $__svg; ?><path d="M21 3 10 14"/><path d="M21 3l-7 18-4-7-7-4z"/></svg><?php break; ?>
    <?php case ('calendar'): ?><?php echo $__svg; ?><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 9.5h16M8 3v4M16 3v4"/></svg><?php break; ?>
    <?php case ('globe'): ?><?php echo $__svg; ?><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z"/><path d="M3 12h18"/><path d="M12 3c2.5 2.6 3.8 5.7 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.7-3.8-9s1.3-6.4 3.8-9z"/></svg><?php break; ?>
    <?php case ('dns'): ?><?php echo $__svg; ?><circle cx="6" cy="6" r="2.4"/><circle cx="18" cy="6" r="2.4"/><circle cx="12" cy="18" r="2.4"/><path d="M7.8 7.6 10.6 16M16.2 7.6 13.4 16M8.4 6h7.2"/></svg><?php break; ?>
    <?php case ('database'): ?><?php echo $__svg; ?><path d="M12 3c4.4 0 8 1.3 8 3s-3.6 3-8 3-8-1.3-8-3 3.6-3 8-3z"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg><?php break; ?>
    <?php case ('shield'): ?><?php echo $__svg; ?><path d="M12 3l7 3v6c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6z"/></svg><?php break; ?>
    <?php case ('shield-check'): ?><?php echo $__svg; ?><path d="M12 3l7 3v6c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6z"/><path d="m9 11.5 2.2 2.2L15.5 9"/></svg><?php break; ?>
    <?php case ('ban'): ?><?php echo $__svg; ?><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg><?php break; ?>
    <?php case ('lock'): ?><?php echo $__svg; ?><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><?php break; ?>
    <?php case ('key'): ?><?php echo $__svg; ?><circle cx="8" cy="14" r="4"/><path d="M11 11 20 2"/><path d="M16 6l3 3M14 8l2 2"/></svg><?php break; ?>
    <?php case ('cog'): ?><?php echo $__svg; ?><path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/></svg><?php break; ?>
    <?php case ('wrench'): ?><?php echo $__svg; ?><path d="M14 7a4.5 4.5 0 0 1 6-4.2L17 6l1 1 3.2-3A4.5 4.5 0 0 1 17 10c-.6 0-1.2-.1-1.7-.3L7 18a2.1 2.1 0 0 1-3-3l8.3-8.3C14 7 14 7 14 7z"/></svg><?php break; ?>
    <?php case ('sliders'): ?><?php echo $__svg; ?><path d="M4 7h10M18 7h2M4 12h4M12 12h8M4 17h12M20 17h0"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="17" r="2"/></svg><?php break; ?>
    <?php case ('user'): ?><?php echo $__svg; ?><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg><?php break; ?>
    <?php case ('users'): ?><?php echo $__svg; ?><circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><path d="M16 5.4a3.5 3.5 0 0 1 0 6.2M18.5 14.9c1.8.8 3 2.3 3 4.1"/></svg><?php break; ?>
    <?php case ('hdd'): ?><?php echo $__svg; ?><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg><?php break; ?>
    <?php case ('chip'): ?><?php echo $__svg; ?><rect x="7" y="7" width="10" height="10" rx="2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5.5 5.5 7 7M17 17l1.5 1.5M18.5 5.5 17 7M7 17l-1.5 1.5"/></svg><?php break; ?>
    <?php case ('gauge'): ?><?php echo $__svg; ?><path d="M4 14a8 8 0 1 1 16 0"/><path d="M12 14l4-4"/><path d="M4 19h16"/></svg><?php break; ?>
    <?php case ('queue'): ?><?php echo $__svg; ?><path d="M4 6h16M4 12h16M4 18h10"/><circle cx="19" cy="18" r="2"/></svg><?php break; ?>
    <?php case ('audit'): ?><?php echo $__svg; ?><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12l2 2 4-4"/></svg><?php break; ?>
    <?php case ('list'): ?><?php echo $__svg; ?><path d="M8 6h12M8 12h12M8 18h12"/><path d="M4 6h.01M4 12h.01M4 18h.01"/></svg><?php break; ?>
    <?php case ('box'): ?><?php echo $__svg; ?><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/></svg><?php break; ?>
    <?php case ('clock'): ?><?php echo $__svg; ?><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg><?php break; ?>
    <?php case ('home'): ?><?php echo $__svg; ?><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/></svg><?php break; ?>
    <?php case ('services'): ?><?php echo $__svg; ?><path d="M7 7h.01M7 12h.01M7 17h.01"/><path d="M11 7h6M11 12h6M11 17h6"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg><?php break; ?>
    <?php case ('plug'): ?><?php echo $__svg; ?><path d="M9 7V3M15 7V3"/><path d="M7 7h10v4a5 5 0 0 1-10 0z"/><path d="M12 16v5"/></svg><?php break; ?>
    <?php case ('target'): ?><?php echo $__svg; ?><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/></svg><?php break; ?>
    <?php case ('chart'): ?><?php echo $__svg; ?><path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/></svg><?php break; ?>
    <?php case ('server'): ?><?php echo $__svg; ?><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/></svg><?php break; ?>
    <?php case ('terminal'): ?><?php echo $__svg; ?><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/></svg><?php break; ?>
    <?php case ('git'): ?><?php echo $__svg; ?><circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="9" r="2.5"/><path d="M6 8.5v7M8.5 6.6c4 .8 7 2 7.1 4.9v2"/></svg><?php break; ?>
    <?php case ('archive'): ?><?php echo $__svg; ?><rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/></svg><?php break; ?>
    <?php case ('trash'): ?><?php echo $__svg; ?><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v5M14 11v5"/></svg><?php break; ?>
    <?php case ('refresh'): ?><?php echo $__svg; ?><path d="M20 11a8 8 0 1 0-2.3 6"/><path d="M20 5v6h-6"/></svg><?php break; ?>
    <?php case ('alert'): ?><?php echo $__svg; ?><path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17.3h.01"/></svg><?php break; ?>
    <?php default: ?><?php echo $__svg; ?><rect x="7" y="7" width="10" height="10" rx="2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5.5 5.5 7 7M17 17l1.5 1.5M18.5 5.5 17 7M7 17l-1.5 1.5"/></svg>
<?php endswitch; ?>
<?php /**PATH /usr/local/alphacp/panel/resources/views/partials/icons.blade.php ENDPATH**/ ?>