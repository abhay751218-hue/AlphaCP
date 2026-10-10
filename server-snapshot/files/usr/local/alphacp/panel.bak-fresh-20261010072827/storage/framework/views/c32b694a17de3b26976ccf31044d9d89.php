<?php $__currentLoopData = ['success' => 'success', 'warning' => 'warning', 'error' => 'error', 'info' => 'info']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $class): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php if(session($key)): ?>
        <div class="flash <?php echo e($class); ?>"><?php echo e(session($key)); ?></div>
    <?php endif; ?>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

<?php if($errors->any()): ?>
    <div class="flash error">
        <strong>Please fix the following:</strong>
        <ul style="margin:6px 0 0 18px">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>
<?php /**PATH /usr/local/alphacp/panel/resources/views/partials/flash.blade.php ENDPATH**/ ?>