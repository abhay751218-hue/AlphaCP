
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $__env->yieldContent('title', 'Dashboard'); ?> · <?php echo e(config('acp.brand.name', 'AlphaCP')); ?></title>
    <link rel="stylesheet" href="<?php echo e(asset('assets/panel.css')); ?>?v=<?php echo e(@filemtime(public_path('assets/panel.css')) ?: config('acp.version')); ?>">
</head>
<body class="mode-<?php echo e(($panelMode ?? 'cpanel') === 'whm' ? 'whm' : 'cpanel'); ?>">
<div class="nav-backdrop" aria-hidden="true"></div>

<aside class="sidenav" id="acp-side">
    <div class="side-brand">
        <span class="logo">A</span>
        <span>
            <?php echo e(config('acp.brand.name', 'AlphaCP')); ?>

            <small><?php echo e(($panelMode ?? 'cpanel') === 'whm' ? 'Server Manager' : 'Account Panel'); ?> · <?php echo e(config('acp.version')); ?></small>
        </span>
    </div>
    <?php if(($panelMode ?? 'cpanel') === 'whm'): ?>
        <?php echo $__env->make('partials.whm-sidebar', [], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php else: ?>
        <?php echo $__env->make('partials.cpanel-sidebar', [], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?>
</aside>

<div class="main-col">
<header class="mainbar">
    <button class="nav-toggle" id="acp-nav-toggle" type="button" aria-label="Menu" aria-expanded="false">☰</button>
    <span class="crumb"><?php echo $__env->yieldContent('title', 'Dashboard'); ?></span>
    <span class="spacer"></span>
    <input type="search" id="acp-search" class="searchbox" placeholder="Find functions quickly by typing here (/)" autocomplete="off" aria-label="Search tools">
    <button class="nav-toggle" id="acp-dark-toggle" type="button" aria-label="Toggle dark mode" title="Dark mode">🌙</button>
    <details class="notif">
        <summary aria-label="Notifications" title="Notifications">🔔</summary>
        <div class="menu-pop">
            <p class="help" style="margin:0 0 4px">Koi nayi notification nahi.</p>
            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('audit.view')): ?><a href="<?php echo e(route('audit.index')); ?>">Audit log dekho →</a><?php endif; ?>
        </div>
    </details>
    <details class="user-menu">
        <summary>
            <span class="avatar"><?php echo e(strtoupper(substr(auth()->user()?->username ?? 'A', 0, 1))); ?></span>
            <span class="uname"><?php echo e(auth()->user()?->username ?? 'Guest'); ?><small><?php echo e(auth()->user()?->role?->label ?? 'user'); ?></small></span>
            <span class="caret">▾</span>
        </summary>
        <div class="menu-pop">
            <a href="<?php echo e(route('security.password')); ?>">Password &amp; Security</a>
            <a href="<?php echo e(route('security.index')); ?>">Two-Factor (2FA)</a>
            <a href="<?php echo e(route('security.sessions')); ?>">Active Sessions</a>
            <form method="post" action="<?php echo e(route('logout')); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit">Log out</button>
            </form>
        </div>
    </details>
</header>

<main class="wrap main-main">
    <?php echo $__env->make('partials.flash', [], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <div class="page-head">
        <div>
            <h1><?php echo $__env->yieldContent('title', 'Dashboard'); ?></h1>
            <p><?php echo $__env->yieldContent('subtitle', ''); ?></p>
        </div>
        <div class="push row">
            <?php echo $__env->yieldContent('actions'); ?>
        </div>
    </div>

    <?php echo $__env->yieldContent('content'); ?>
</main>

<footer class="wrap muted" style="padding-top:0; font-size:12.5px">
    <?php echo e(config('acp.brand.name', 'AlphaCP')); ?> <?php echo e(config('acp.version')); ?> — <?php echo e(config('acp.brand.tagline', 'Hosting control panel')); ?>

</footer>
</div>


<script src="<?php echo e(asset('assets/panel.js')); ?>?v=<?php echo e(@filemtime(public_path('assets/panel.js')) ?: config('acp.version')); ?>" defer></script>
</body>
</html>
<?php /**PATH /usr/local/alphacp/panel/resources/views/layouts/panel.blade.php ENDPATH**/ ?>