
<?php
    $live    = ($item['status'] ?? 'step') === 'live';
    $addon   = ($item['status'] ?? 'step') === 'addon';
    $href    = $live ? route($item['route']) : null;
    $classes = 'tile' . ($live ? ' live' : ' disabled');
?>

<?php if($live): ?>
    <a class="<?php echo e($classes); ?>" href="<?php echo e($href); ?>">
<?php else: ?>
    <div class="<?php echo e($classes); ?>" title="<?php echo e($addon ? 'Optional module' : 'Step ' . $item['step'] . ' me aayega'); ?>">
<?php endif; ?>

    <span class="tchip"><?php echo $__env->make('partials.icons', ['icon' => $item['icon'] ?? (($item['name'] ?? '') . ' ' . ($item['route'] ?? '')), 'cls' => 'tico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></span>
    <span>
        <span class="name"><?php echo e($item['name']); ?></span>
        <span class="sub">
            <?php if($live): ?>
                Ready
            <?php elseif($addon): ?>
                Optional (<?php echo e($item['step']); ?>)
            <?php else: ?>
                <?php echo e($item['step']); ?> me aayega
            <?php endif; ?>
        </span>
    </span>

<?php if($live): ?>
    </a>
<?php else: ?>
    </div>
<?php endif; ?>
<?php /**PATH /usr/local/alphacp/panel/resources/views/partials/tile.blade.php ENDPATH**/ ?>