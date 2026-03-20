<?php
/**
 * Model Card Partial
 *
 * Variables:
 *   $model        - Model array (id, name, creator, thumbnail_path, preview_path, preview_type,
 *                   preview_file_size, file_size, part_count, created_at, is_archived,
 *                   download_count)
 *   $cardOptions  - Optional array of display flags:
 *     'archivedClass'     (bool, default false) - Add 'archived' CSS class and badge
 *     'batchCheckbox'     (bool, default false) - Show batch selection checkbox
 *     'favoriteButton'    (bool, default false) - Show inline favorite/unfavorite button
 *     'downloadCount'     (bool, default false) - Show download count in model-info
 *     'pluginExtra'       (bool, default false) - Emit PluginManager model_card_extra filter
 *     'fileSizeLimit'     (bool, default false) - Skip lazy 3D preview for files > 5MB
 *     'customOnClick'     (string|null)         - Custom onclick attribute value (overrides default)
 *     'customOnKeydown'   (string|null)         - Custom onkeydown attribute value (overrides default)
 *     'wrapperClass'      (string, default '')  - Additional CSS class(es) to add to the article element
 */

$opts = array_merge([
    'archivedClass'   => false,
    'batchCheckbox'   => false,
    'favoriteButton'  => false,
    'downloadCount'   => false,
    'pluginExtra'     => false,
    'fileSizeLimit'   => false,
    'customOnClick'   => null,
    'customOnKeydown' => null,
    'wrapperClass'    => '',
], $cardOptions ?? []);

$cardUrl      = route('model.show', ['id' => $model['id']]);
$onClickAttr  = $opts['customOnClick']   ?? "window.location='" . $cardUrl . "'";
$onKeydownAttr = $opts['customOnKeydown'] ?? "if(event.key==='Enter')this.click()";
$archivedClass = ($opts['archivedClass'] && !empty($model['is_archived'])) ? ' archived' : '';
$wrapperClass = !empty($opts['wrapperClass']) ? ' ' . $opts['wrapperClass'] : '';

// Determine whether to show lazy 3D preview
$showLazy3d = empty($model['thumbnail_path']) && !empty($model['preview_path']);
if ($showLazy3d && $opts['fileSizeLimit']) {
    $previewSize = $model['preview_file_size'] ?? $model['file_size'] ?? 0;
    $showLazy3d  = $previewSize < 5242880;
}

$thumbSrcset = '';
if (!empty($model['thumbnail_path']) && function_exists('image_srcset')) {
    $thumbSrcset = image_srcset('storage/assets/' . $model['thumbnail_path'], [280, 560]);
}
?>
<article class="model-card<?= $archivedClass ?><?= $wrapperClass ?>" data-model-id="<?= $model['id'] ?>" onclick="<?= htmlspecialchars($onClickAttr) ?>" tabindex="0" role="link" aria-label="<?= htmlspecialchars($model['name']) ?>" onkeydown="<?= htmlspecialchars($onKeydownAttr) ?>">
    <div class="model-thumbnail"
        <?php if ($showLazy3d): ?>
        data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
        data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
        <?php endif; ?>>
        <?php if (!empty($model['thumbnail_path'])): ?>
        <img src="/assets/<?= htmlspecialchars($model['thumbnail_path']) ?>" alt="<?= htmlspecialchars($model['name']) ?>" class="model-thumbnail-image" loading="lazy" decoding="async"<?= $thumbSrcset ? ' srcset="' . htmlspecialchars($thumbSrcset) . '" sizes="(min-width: 280px) 280px, 100vw"' : '' ?>>
        <?php endif; ?>
        <?php if ($opts['batchCheckbox'] && isLoggedIn()): ?>
        <label class="model-select-checkbox" onclick="event.stopPropagation()">
            <input type="checkbox" class="model-checkbox" value="<?= $model['id'] ?>" onchange="updateBatchSelection()" aria-label="Select <?= htmlspecialchars($model['name']) ?>">
        </label>
        <?php endif; ?>
        <?php if ($model['part_count'] > 0): ?>
        <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
        <?php endif; ?>
        <?php if ($opts['archivedClass'] && !empty($model['is_archived'])): ?>
        <span class="archived-badge" style="position: absolute; bottom: 0.5rem; left: 0.5rem;">Archived</span>
        <?php endif; ?>
        <?php if ($opts['favoriteButton']): ?>
        <button type="button" class="model-card-favorite favorite-btn favorited" onclick="event.stopPropagation(); toggleFavorite(<?= $model['id'] ?>, this)" title="Remove from favorites" aria-label="Remove from favorites"><span aria-hidden="true">&#9829;</span></button>
        <?php endif; ?>
    </div>
    <div class="model-info">
        <h3 class="model-title"><?= htmlspecialchars($model['name']) ?></h3>
        <p class="model-creator"><?= $model['creator'] ? 'by ' . htmlspecialchars($model['creator']) : '' ?></p>
        <?php if (!empty($model['created_at'])): ?>
        <time class="model-date" datetime="<?= htmlspecialchars(date('c', strtotime($model['created_at']))) ?>" data-timestamp="<?= htmlspecialchars($model['created_at']) ?>"><?= date('M j, Y', strtotime($model['created_at'])) ?></time>
        <?php endif; ?>
        <?php if ($opts['downloadCount'] && isFeatureEnabled('download_tracking') && ($model['download_count'] ?? 0) > 0): ?>
        <p class="download-count mt-1"><?= number_format($model['download_count']) ?> downloads</p>
        <?php endif; ?>
    </div>
    <?php if ($opts['pluginExtra'] && class_exists('PluginManager')): ?>
    <?= PluginManager::applyFilter('model_card_extra', '', $model) ?>
    <?php endif; ?>
</article>
