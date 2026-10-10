
<?php $__currentLoopData = $sections; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $section): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php
        $liveCount = collect($section['items'])->where('status', 'live')->count();
    ?>
    <div class="card mt sect-card">
        <div class="sect-head">
            <h3><?php echo $__env->make('partials.icons', ['icon' => $section['icon'] ?? $key, 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> <?php echo e($section['label']); ?></h3>
            <span class="count"><?php echo e($liveCount); ?> live / <?php echo e(count($section['items'])); ?></span>
            <button class="chev" type="button" aria-label="Toggle section">▾</button>
        </div>
        <div class="sect-body grid tiles">
            <?php $__currentLoopData = $section['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php echo $__env->make('partials.tile', ['item' => $item], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
<?php /**PATH /usr/local/alphacp/panel/resources/views/partials/dash-sections.blade.php ENDPATH**/ ?>