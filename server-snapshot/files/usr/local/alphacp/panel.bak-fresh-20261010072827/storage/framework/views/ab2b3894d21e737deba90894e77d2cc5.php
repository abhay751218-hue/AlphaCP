
<?php
    $whmSections = \App\Support\ModuleCatalog::sectionsFor(auth()->user());
?>
<nav class="whm-side" id="whm-side" aria-label="WHM navigation">
  <input type="search" id="whm-search" class="whm-search" placeholder="Search features…" autocomplete="off" aria-label="Search features">
  <a class="whm-home" href="<?php echo e(route('dashboard')); ?>"><?php echo $__env->make('partials.icons', ['icon' => 'home', 'cls' => 'hico'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?> Home</a>
  <div class="whm-tree" id="whm-tree">
    <section class="whm-group" id="whm-favs" style="display:none">
      <button type="button" class="whm-groupbtn" aria-expanded="true">Favorites</button>
      <div class="whm-items"></div>
    </section>
    <?php $__currentLoopData = $whmSections; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $sec): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <section class="whm-group">
        <button type="button" class="whm-groupbtn" aria-expanded="true"><?php echo e($sec['label']); ?></button>
        <div class="whm-items">
          <?php $__currentLoopData = $sec['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php if(!empty($item['route']) && \Illuminate\Support\Facades\Route::has($item['route'])): ?>
              <div class="whm-item"><a href="<?php echo e(route($item['route'])); ?>" data-favname="<?php echo e($item['name']); ?>"><?php echo e($item['name']); ?></a><button type="button" class="whm-fav" title="Favorites me add/remove karo" aria-label="Favorite">☆</button></div>
            <?php else: ?>
              <span class="whm-soon" title="ye module is roadmap slice ke baad aayega"><?php echo e($item['name']); ?> <em>soon</em></span>
            <?php endif; ?>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
      </section>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
  </div>
</nav>
<?php /**PATH /usr/local/alphacp/panel/resources/views/partials/whm-sidebar.blade.php ENDPATH**/ ?>