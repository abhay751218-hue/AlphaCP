<?php $__env->startSection('title', 'License Server'); ?>
<?php $__env->startSection('subtitle', 'Sellable signed licenses — customers ke liye keys issue/verify/revoke'); ?>

<?php $__env->startSection('actions'); ?>
    <a class="btn small secondary" href="<?php echo e(route('dashboard')); ?>">← Dashboard</a>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<?php if($newKey): ?>
<div class="card" style="border:2px solid #2a2">
    <h3>Naya license key (EK baar — copy karo)</h3>
    <p><code style="word-break:break-all"><?php echo e($newKey); ?></code></p>
    <p class="muted">Ye key customer ko do — unka panel ise offline verify karega.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3>License issue karo</h3>
    <form method="POST" action="<?php echo e(route('license-server.store')); ?>">
        <?php echo csrf_field(); ?>
        <label>Server ID
            <input type="text" name="server_id" placeholder="srv-001 / IP / domain" required>
        </label>
        <label>Plan
            <select name="plan" required>
                <option value="starter">starter (10 accounts)</option>
                <option value="pro">pro (50 accounts)</option>
                <option value="business">business (200 accounts)</option>
                <option value="owner">owner (UNLIMITED + lifetime)</option>
            </select>
        </label>
        <label>Din (validity; 0 = lifetime, sirf owner)
            <input type="number" name="days" value="365" min="0" max="36500" required>
        </label>
        <button class="btn" type="submit">Issue license</button>
    </form>
</div>

<?php if($publicPem): ?>
<div class="card">
    <h3>Public key (customer panel ke .env me ACP_LICENSE_PUBLIC_KEY)</h3>
    <pre style="word-break:break-all;white-space:pre-wrap"><?php echo e($publicPem); ?></pre>
    <p class="muted">Customer apne panel me ye key daalega — offline Ed25519 verify ke liye.</p>
</div>
<?php else: ?>
<div class="card">
    <p class="muted">Sodium extension is host par nahi — licenses online-verified mode me chalenge (hmac).</p>
</div>
<?php endif; ?>

<div class="card">
    <h3>Issued licenses (<?php echo e($keys->count()); ?>)</h3>
    <?php if($keys->isEmpty()): ?>
        <p class="muted">Abhi koi license nahi.</p>
    <?php else: ?>
        <table>
            <tr><th>Server</th><th>Plan</th><th>Expires</th><th>Status</th><th></th></tr>
            <?php $__currentLoopData = $keys; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td><?php echo e($k->server_id); ?></td>
                <td><?php echo e($k->plan); ?></td>
                <td><?php echo e($k->expires_at?->format('d M Y')); ?></td>
                <td><?php echo e($k->revoked ? 'REVOKED' : 'active'); ?></td>
                <td>
                    <?php if (! ($k->revoked)): ?>
                    <form method="POST" action="<?php echo e(route('license-server.destroy', $k)); ?>" onsubmit="return confirm('Revoke karein?')">
                        <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                        <button class="btn small danger" type="submit">Revoke</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </table>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.panel', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /usr/local/alphacp/panel/resources/views/license-server/index.blade.php ENDPATH**/ ?>