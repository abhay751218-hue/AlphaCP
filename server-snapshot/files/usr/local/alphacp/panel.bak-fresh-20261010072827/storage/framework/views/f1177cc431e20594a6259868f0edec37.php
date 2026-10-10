<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $__env->yieldContent('title', 'Login'); ?> · AlphaCP</title>
    <link rel="stylesheet" href="<?php echo e(asset('assets/panel.css')); ?>?v=<?php echo e(config('acp.version')); ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="wm-mark"><?php echo $__env->yieldContent('wordmark', config('acp.brand.name', 'AlphaCP')); ?></div>
        <div class="wm-sub"><?php echo $__env->yieldContent('wordmark-sub', 'Control Panel'); ?></div>

        <div class="card">
            <?php echo $__env->make('partials.flash', [], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php echo $__env->yieldContent('content'); ?>
        </div>

        <div class="auth-foot">
            AlphaCP <?php echo e(config('acp.version')); ?> · <?php echo e(parse_url(config('app.url'), PHP_URL_HOST) ?: 'server'); ?>

        </div>
    </div>
</div>
</body>
</html>
<?php /**PATH /usr/local/alphacp/panel/resources/views/layouts/guest.blade.php ENDPATH**/ ?>