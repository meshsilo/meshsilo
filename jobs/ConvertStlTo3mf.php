<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/converter.php';

class ConvertStlTo3mf extends Job
{
    public function handle(array $data): void
    {
        $modelId = $data['model_id'] ?? null;
        if (!$modelId) {
            throw new \Exception('Missing model_id');
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();

        if (!$result->fetchArray()) {
            return; // Model was deleted before job ran
        }

        $convertResult = convertPartTo3MF($modelId);
        if ($convertResult['success']) {
            logInfo('Background STL to 3MF conversion', [
                'model_id' => $modelId,
                'original_size' => $convertResult['original_size'],
                'new_size' => $convertResult['new_size'],
                'savings' => $convertResult['savings']
            ]);
        }
    }
}
