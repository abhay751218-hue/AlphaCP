<?php $__env->startSection('title', config('acp.brand.name','AlphaCP').' — Server Manager Dashboard'); ?>
<?php $__env->startSection('subtitle', 'Server health, accounts, packages — customer sites are not created on this page; they use the account panel'); ?>

<?php $__env->startSection('actions'); ?>
    <a class="btn small secondary" href="<?php echo e(route('system.index', ['refresh' => 1])); ?>">Refresh stats</a>
    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('accounts.view')): ?>
        <a class="btn small secondary" href="<?php echo e(route('accounts.index')); ?>">Accounts</a>
    <?php endif; ?>
    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('system.tasks')): ?>
        <a class="btn small secondary" href="<?php echo e(route('system.tasks')); ?>">Task queue</a>
    <?php endif; ?>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="card">
    <h3>Quick links</h3>
    <p>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('accounts.create')): ?><a class="btn small" href="<?php echo e(route('accounts.create')); ?>">Create Account</a><?php endif; ?>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('accounts.view')): ?><a class="btn small secondary" href="<?php echo e(route('accounts.index')); ?>">List Accounts</a><?php endif; ?>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('packages.view')): ?><a class="btn small secondary" href="<?php echo e(route('packages.index')); ?>">Packages</a><?php endif; ?>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('accounts.view')): ?><a class="btn small secondary" href="<?php echo e(route('transfer-restore.index')); ?>">Transfer or Restore a Hosting Account</a><?php endif; ?>
    </p>
</div>

<div class="whm-tiles">
    <?php $__currentLoopData = $sections; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $sec): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $first = collect($sec['items'] ?? [])->first(fn ($it) => ($it['status'] ?? '') === 'live' && !empty($it['route'])); ?>
        <?php if($first): ?>
            <a class="wtile" href="<?php echo e(route($first['route'])); ?>">
                <?php echo $__env->make('partials.icons', ['icon' => $sec['icon'] ?? 'box', 'cls' => 'wtico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <span><?php echo e($sec['label']); ?></span>
                <em><?php echo e(count($sec['items'])); ?> tools</em>
            </a>
        <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>
<div class="grid cols-4">
    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'chip', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Memory</h3>
        <?php if($system): ?>
            <div class="stat"><span class="num"><?php echo e($system['memory']['used_pct']); ?>%</span>
                <span class="unit">used · <?php echo e($system['memory']['used_mb']); ?> / <?php echo e($system['memory']['total_mb']); ?> MB</span></div>
            <div class="meter <?php echo e($system['memory']['used_pct'] > 85 ? 'amber' : 'green'); ?>"><span style="width: <?php echo e(min(100, $system['memory']['used_pct'])); ?>%"></span></div>
        <?php else: ?>
            <p class="empty">No data from the agent (is paneld running?)</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'disk', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Disk (/)</h3>
        <?php if($system): ?>
            <div class="stat"><span class="num"><?php echo e($system['disk']['used_pct']); ?>%</span>
                <span class="unit"><?php echo e($system['disk']['used_gb']); ?> / <?php echo e($system['disk']['total_gb']); ?> GB</span></div>
            <div class="meter <?php echo e($system['disk']['used_pct'] > 85 ? 'amber' : 'green'); ?>"><span style="width: <?php echo e(min(100, $system['disk']['used_pct'])); ?>%"></span></div>
        <?php else: ?>
            <p class="empty">—</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'gauge', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Load · CPU</h3>
        <?php if($system): ?>
            <div class="stat"><span class="num"><?php echo e($system['load'][0]); ?></span>
                <span class="unit">1-min · <?php echo e($system['cpu_cores']); ?> cores</span></div>
            <p class="help"><?php echo e($system['os']); ?> · kernel <?php echo e($system['kernel']); ?> · <?php echo e($system['arch']); ?></p>
        <?php else: ?>
            <p class="empty">—</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'queue', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Agent queue</h3>
        <div class="stat"><span class="num"><?php echo e($queue['queued'] + $queue['running']); ?></span>
            <span class="unit">pending · <?php echo e($queue['success']); ?> done · <?php echo e($queue['failed']); ?> failed</span></div>
        <p class="help">paneld v<?php echo e($versions['agent']); ?> · <?php echo e($config_server ?? ''); ?>

            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('system.tasks')): ?> <a href="<?php echo e(route('system.tasks')); ?>">monitor →</a> <?php endif; ?></p>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'services', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Services</h3>
        <?php if($services): ?>
            <div class="table-wrap">
                <table>
                    <tr><th>Service</th><th>State</th><th>Boot</th></tr>
                    <?php $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $name => $state): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="mono"><?php echo e($name); ?></td>
                            <td>
                                <span class="badge <?php echo e($state['active'] === 'active' ? 'green' : ($state['active'] === 'inactive' ? 'amber' : 'red')); ?>">
                                    <?php echo e($state['active']); ?>

                                </span>
                            </td>
                            <td class="muted"><?php echo e($state['enabled']); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">Service status did not come from the agent.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?php echo $__env->make('partials.icons', ['icon' => 'audit', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Recent activity (audit)</h3>
        <?php if($audit->isEmpty()): ?>
            <p class="empty">No activity yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <?php $__currentLoopData = $audit; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="mono"><?php echo e($event->action); ?></td>
                            <td><span class="badge <?php echo e($event->severity === 'critical' ? 'red' : ($event->severity === 'warning' ? 'amber' : 'blue')); ?>"><?php echo e($event->severity); ?></span></td>
                            <td class="muted right"><?php echo e(\App\Support\Panel::ago($event->created_at)); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </table>
            </div>
            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('audit.view')): ?>
                <p class="help mt"><a href="<?php echo e(route('audit.index')); ?>">Poora audit log →</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php echo $__env->make('partials.dash-sections', [], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.panel', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /usr/local/alphacp/panel/resources/views/dashboard-whm.blade.php ENDPATH**/ ?>