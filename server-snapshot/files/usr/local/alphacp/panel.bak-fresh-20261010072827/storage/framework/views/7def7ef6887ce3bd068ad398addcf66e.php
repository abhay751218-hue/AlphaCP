<?php $__env->startSection('title', 'License & Trial'); ?>
<?php $__env->startSection('subtitle', 'Panel license status — customer websites and email are never blocked'); ?>

<?php $__env->startSection('content'); ?>
<?php
    $state = $status['state'] ?? 'uninitialized';
    $badge = match ($state) {
        'active', 'trial' => 'green',
        'notice', 'grace' => 'amber',
        'invalid', 'locked' => 'red',
        default => 'blue',
    };
?>

<div class="card">
    <div class="row" style="align-items:center; justify-content:space-between; gap:16px">
        <div>
            <h3><?php echo $__env->make('partials.icons', ['icon' => 'key', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Current status</h3>
            <p class="help"><?php echo e($status['message'] ?? 'Status unavailable.'); ?></p>
        </div>
        <span class="badge <?php echo e($badge); ?>" style="font-size:14px"><?php echo e($status['label'] ?? 'UNKNOWN'); ?></span>
    </div>

    <div class="grid cols-2 mt">
        <div>
            <div class="kv"><span>Tier</span><span><?php echo e($status['tier'] ?: '—'); ?></span></div>
            <div class="kv"><span>License ID</span><span class="mono"><?php echo e($status['license_uid'] ?: '—'); ?></span></div>
            <div class="kv"><span>Source</span><span><?php echo e($status['source'] ?: '—'); ?></span></div>
        </div>
        <div>
            <div class="kv"><span>Expires</span><span><?php echo e($status['expires_at'] ?: '—'); ?></span></div>
            <div class="kv"><span>Days left</span><span><?php echo e($status['days_left'] ?? '—'); ?></span></div>
            <div class="kv"><span>Fingerprint</span><span class="mono"><?php echo e($status['fingerprint'] ?: '—'); ?></span></div>
        </div>
    </div>
</div>

<div class="card mt">
    <h3><?php echo $__env->make('partials.icons', ['icon' => 'key', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Activate a Paid License</h3>
    <p class="help">If the license server is not configured, local trial stays active. Do not share the key in chat, logs, or screenshots.</p>
    <form method="post" action="<?php echo e(route('license.activate')); ?>" class="row mt" style="align-items:end; gap:12px">
        <?php echo csrf_field(); ?>
        <label style="flex:1">License key
            <input type="text" name="license_key" value="<?php echo e(old('license_key')); ?>" placeholder="ACPP-XXXX-XXXX-XXXX" maxlength="160" required>
        </label>
        <button class="btn" type="submit">Activate</button>
    </form>
    <?php $__errorArgs = ['license_key'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
        <p class="error mt"><?php echo e($message); ?></p>
    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
</div>

<div class="card mt">
    <h3><?php echo $__env->make('partials.icons', ['icon' => 'shield-check', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Golden Rule</h3>
    <p class="help">License expiry or a license-server outage will not stop customer websites, email, DNS, or backups. Only privileged panel actions degrade.</p>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.panel', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /usr/local/alphacp/panel/resources/views/license/index.blade.php ENDPATH**/ ?>