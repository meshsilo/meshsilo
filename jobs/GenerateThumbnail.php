<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ThumbnailGenerator.php';

class GenerateThumbnail extends Job
{
    public function handle(array $data): void
    {
        $modelId = $data['model_id'] ?? null;
        if (!$modelId) {
            throw new \Exception('Missing model_id');
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT id, file_path, file_type FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $model = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$model) {
            return; // Model was deleted before job ran
        }

        $thumbnail = ThumbnailGenerator::generateThumbnail($model);
        if ($thumbnail) {
            logInfo('Background thumbnail generated', ['model_id' => $modelId, 'thumbnail' => $thumbnail]);
        }
    }
}
