<?php $__env->startSection('title', 'Login'); ?>

<?php $__env->startSection('wordmark', config('acp.brand.name', 'AlphaCP')); ?>

<?php $__env->startSection('wordmark-sub', (($portFamily ?? null) === 'whm') ? 'Server Manager' : ((($portFamily ?? null) === 'cpanel') ? 'Account Panel' : 'Control Panel')); ?>

<?php $__env->startSection('content'); ?>
    <h1><?php echo e(($portFamily ?? null) === 'whm' ? 'Server Manager Login' : (($portFamily ?? null) === 'cpanel' ? 'Account Panel Login' : 'Panel Login')); ?></h1>
    <p class="sub"><?php if(($portFamily ?? null) === 'whm'): ?>
            Root · Reseller — server management
        <?php elseif(($portFamily ?? null) === 'cpanel'): ?>
            Customer — hosting control
        <?php else: ?>
            Admin · Reseller · Customer — sab ek hi URL se
        <?php endif; ?></p>

    <form method="post" action="<?php echo e(route('login.attempt')); ?>">
        <?php echo csrf_field(); ?>

        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="<?php echo e(old('username')); ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button class="btn mt" type="submit" style="width:100%; justify-content:center">Login</button>
    </form>

    <p class="help mt">Too many failed passwords lock the account for a short time (brute-force protection).</p>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.guest', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /usr/local/alphacp/panel/resources/views/auth/login.blade.php ENDPATH**/ ?>