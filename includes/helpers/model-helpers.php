<?php
// Model-specific helper functions (versions, dimensions, licenses, related models)
// =====================
// License Constants
// =====================

function getLicenseOptions()
{
    return [
        '' => 'No License Specified',
        'cc0' => 'CC0 (Public Domain)',
        'cc-by' => 'CC BY (Attribution)',
        'cc-by-sa' => 'CC BY-SA (Attribution-ShareAlike)',
        'cc-by-nc' => 'CC BY-NC (Attribution-NonCommercial)',
        'cc-by-nc-sa' => 'CC BY-NC-SA (Attribution-NonCommercial-ShareAlike)',
        'cc-by-nd' => 'CC BY-ND (Attribution-NoDerivatives)',
        'cc-by-nc-nd' => 'CC BY-NC-ND (Attribution-NonCommercial-NoDerivatives)',
        'mit' => 'MIT License',
        'gpl' => 'GPL (GNU General Public License)',
        'proprietary' => 'Proprietary / All Rights Reserved',
        'other' => 'Other'
    ];
}

function getLicenseName($key)
{
    $options = getLicenseOptions();
    return $options[$key] ?? $key;
}

// =====================
// Related Models Functions
// =====================

function getRelatedModels($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT rm.*, m.name, m.file_path, m.print_type, m.created_at
            FROM related_models rm
            JOIN models m ON rm.related_model_id = m.id
            WHERE rm.model_id = :model_id AND m.parent_id IS NULL
            ORDER BY rm.created_at DESC
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function addRelatedModel($modelId, $relatedModelId, $relationshipType = 'related')
{
    if ($modelId == $relatedModelId) {
        return false;
    }
    try {
        $db = getDB();
        // Add relation both ways
        $stmt = $db->prepare('INSERT OR IGNORE INTO related_models (model_id, related_model_id, relationship_type) VALUES (:model_id, :related_id, :type)');
        $stmt->execute([':model_id' => $modelId, ':related_id' => $relatedModelId, ':type' => $relationshipType]);
        $stmt->execute([':model_id' => $relatedModelId, ':related_id' => $modelId, ':type' => $relationshipType]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function removeRelatedModel($modelId, $relatedModelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM related_models WHERE (model_id = :model_id1 AND related_model_id = :related_id1) OR (model_id = :related_id2 AND related_model_id = :model_id2)');
        $stmt->execute([':model_id1' => $modelId, ':related_id1' => $relatedModelId, ':related_id2' => $relatedModelId, ':model_id2' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =====================
// Version History Functions
// =====================

function getModelVersions($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT mv.*, u.username as created_by_name
            FROM model_versions mv
            LEFT JOIN users u ON mv.created_by = u.id
            WHERE mv.model_id = :model_id
            ORDER BY mv.version_number DESC
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function addModelVersion($modelId, $filePath, $fileSize, $fileHash, $changelog = '', $createdBy = null)
{
    try {
        $db = getDB();
        // Get current max version
        $stmt = $db->prepare('SELECT MAX(version_number) as max_ver FROM model_versions WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $modelId]);
        $row = $stmt->fetch();
        $nextVersion = ($row && $row['max_ver']) ? $row['max_ver'] + 1 : 1;

        $stmt = $db->prepare('
            INSERT INTO model_versions (model_id, version_number, file_path, file_size, file_hash, changelog, created_by)
            VALUES (:model_id, :version, :file_path, :file_size, :file_hash, :changelog, :created_by)
        ');
        $stmt->execute([
            ':model_id' => $modelId,
            ':version' => $nextVersion,
            ':file_path' => $filePath,
            ':file_size' => $fileSize,
            ':file_hash' => $fileHash,
            ':changelog' => $changelog,
            ':created_by' => $createdBy
        ]);

        // Update model's current version
        $stmt = $db->prepare('UPDATE models SET current_version = :version WHERE id = :id');
        $stmt->execute([':version' => $nextVersion, ':id' => $modelId]);

        return $nextVersion;
    } catch (Exception $e) {
        return false;
    }
}

function getModelVersion($modelId, $versionNumber)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, model_id, version_number, file_path, file_size, file_hash, changelog, created_by, created_at FROM model_versions WHERE model_id = :model_id AND version_number = :version');
        $stmt->execute([':model_id' => $modelId, ':version' => $versionNumber]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// =====================
// Part Ordering Functions
// =====================

function updatePartOrder($partId, $sortOrder)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET sort_order = :sort_order WHERE id = :id');
        $stmt->execute([':sort_order' => $sortOrder, ':id' => $partId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function reorderParts($parentId, $partIds)
{
    try {
        $db = getDB();
        $db->beginTransaction();
        foreach ($partIds as $index => $partId) {
            $stmt = $db->prepare('UPDATE models SET sort_order = :sort_order WHERE id = :id AND parent_id = :parent_id');
            $stmt->execute([':sort_order' => $index, ':id' => $partId, ':parent_id' => $parentId]);
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollBack();
        }
        return false;
    }
}

// =====================
// Model Dimensions Functions
// =====================

function updateModelDimensions($modelId, $dimX, $dimY, $dimZ, $unit = 'mm')
{
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET dim_x = :x, dim_y = :y, dim_z = :z, dim_unit = :unit WHERE id = :id');
        $stmt->execute([':x' => $dimX, ':y' => $dimY, ':z' => $dimZ, ':unit' => $unit, ':id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getModelDimensions($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT dim_x, dim_y, dim_z, dim_unit FROM models WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['dim_x'] !== null) {
            return $row;
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}
