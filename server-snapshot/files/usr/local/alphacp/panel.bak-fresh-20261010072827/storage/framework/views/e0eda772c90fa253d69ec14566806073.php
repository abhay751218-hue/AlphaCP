<?php $__env->startSection('title', 'Updates'); ?>
<?php $__env->startSection('subtitle', 'Component versions + ab tak install hue update waves — sab asli data'); ?>

<?php $__env->startSection('actions'); ?>
    <a class="btn small secondary" href="<?php echo e(route('system.index')); ?>">System Information</a>
    <a class="btn small secondary" href="<?php echo e(route('license.index')); ?>">License</a>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="grid cols-2">
    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'refresh', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Current versions</h3>
        <dl class="kv">
            <dt>AlphaCP panel</dt><dd class="mono"><?php echo e($versions['panel']); ?></dd>
            <dt>paneld (agent)</dt><dd class="mono"><?php echo e($versions['agent']); ?></dd>
            <dt>PHP</dt><dd class="mono"><?php echo e($versions['php']); ?></dd>
            <dt>Framework</dt><dd class="mono">Laravel <?php echo e($versions['framework']); ?></dd>
            <dt>Node.js</dt><dd class="mono"><?php echo e($node ?? '—'); ?></dd>
        </dl>
    </div>
    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'target', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Updates kaise lagte hain</h3>
        <p class="help" style="margin:6px 0 0">AlphaCP self-hosted hai — har update ek signed installer wave
            hota hai (<span class="mono">alphacp-sync get &lt;commit&gt; … &lt;sha256&gt;</span>): pehle backup,
            phir install, phir health-check — fail ho to <strong>auto-rollback</strong>. Ab tak lage waves
            neeche (har installer apna <span class="mono">/var/log/alphacp-*.log</span> chhodta hai).</p>
    </div>
</div>

<div class="card mt">
    <div class="row mb">
        <h3 style="margin:0"><?php echo $__env->make('partials.icons', ['icon' => 'archive', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Installed update waves (<?php echo e(count($waves)); ?>)</h3>
        <span class="push"></span>
        <input type="search" class="searchbox" style="width:min(260px,100%)" placeholder="Filter waves…" data-filter-rows="#acp-updates tbody tr" aria-label="Filter waves">
    </div>
    <?php if(! $agentOk): ?>
        <p class="empty">Root agent offline — wave history nahi mil saki.</p>
    <?php elseif($waves === []): ?>
        <p class="empty">Koi wave log nahi mila.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table id="acp-updates">
            <thead><tr><th>#</th><th>Wave</th><th>Log file</th><th>Status</th></tr></thead>
            <tbody>
            <?php $__currentLoopData = $waves; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $w): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr>
                    <td class="mono"><?php echo e($loop->iteration); ?></td>
                    <td class="mono"><?php echo e($w); ?></td>
                    <td class="mono muted">/var/log/alphacp-<?php echo e($w); ?>.log</td>
                    <td><span class="badge green">installed</span></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.panel', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /usr/local/alphacp/panel/resources/views/updates/index.blade.php ENDPATH**/ ?>