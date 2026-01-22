<?php
/**
 * Advanced Audit Logging System
 *
 * Provides comprehensive audit logging for compliance and security
 * with export capabilities and detailed change tracking
 */

class AuditLogger {
    // Event types
    const TYPE_AUTH = 'auth';
    const TYPE_DATA = 'data';
    const TYPE_ADMIN = 'admin';
    const TYPE_SECURITY = 'security';
    const TYPE_SYSTEM = 'system';
    const TYPE_API = 'api';

    // Severity levels
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';
    const SEVERITY_CRITICAL = 'critical';

    private static $requestId = null;

    /**
     * Get or generate request ID for correlating logs
     */
    public static function getRequestId() {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(18));
        }
        return self::$requestId;
    }

    /**
     * Log an audit event
     */
    public static function log($eventType, $eventName, $data = [], $severity = self::SEVERITY_INFO) {
        if (getSetting('audit_logging_enabled', '1') !== '1') {
            return false;
        }

        $db = getDB();
        $userId = null;
        $sessionId = null;

        if (function_exists('isLoggedIn') && isLoggedIn()) {
            $user = getCurrentUser();
            $userId = $user['id'] ?? null;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = session_id();
        }

        $stmt = $db->prepare('
            INSERT INTO audit_log (
                event_type, event_name, severity, user_id, ip_address,
                user_agent, resource_type, resource_id, resource_name,
                old_value, new_value, metadata, session_id, request_id, created_at
            ) VALUES (
                :event_type, :event_name, :severity, :user_id, :ip,
                :user_agent, :resource_type, :resource_id, :resource_name,
                :old_value, :new_value, :metadata, :session_id, :request_id, :created_at
            )
        ');

        $stmt->bindValue(':event_type', $eventType, SQLITE3_TEXT);
        $stmt->bindValue(':event_name', $eventName, SQLITE3_TEXT);
        $stmt->bindValue(':severity', $severity, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':user_agent', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), SQLITE3_TEXT);
        $stmt->bindValue(':resource_type', $data['resource_type'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':resource_id', $data['resource_id'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':resource_name', $data['resource_name'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':old_value', isset($data['old_value']) ? json_encode($data['old_value']) : null, SQLITE3_TEXT);
        $stmt->bindValue(':new_value', isset($data['new_value']) ? json_encode($data['new_value']) : null, SQLITE3_TEXT);
        $stmt->bindValue(':metadata', isset($data['metadata']) ? json_encode($data['metadata']) : null, SQLITE3_TEXT);
        $stmt->bindValue(':session_id', $sessionId, SQLITE3_TEXT);
        $stmt->bindValue(':request_id', self::getRequestId(), SQLITE3_TEXT);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    /**
     * Log a data change with old/new values
     */
    public static function logDataChange($resourceType, $resourceId, $oldValue, $newValue, $resourceName = null) {
        return self::log(self::TYPE_DATA, 'data_change', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'resource_name' => $resourceName,
            'old_value' => $oldValue,
            'new_value' => $newValue
        ]);
    }

    /**
     * Log authentication event
     */
    public static function logAuth($eventName, $data = [], $severity = self::SEVERITY_INFO) {
        return self::log(self::TYPE_AUTH, $eventName, $data, $severity);
    }

    /**
     * Log security event
     */
    public static function logSecurity($eventName, $data = [], $severity = self::SEVERITY_WARNING) {
        return self::log(self::TYPE_SECURITY, $eventName, $data, $severity);
    }

    /**
     * Log admin action
     */
    public static function logAdmin($eventName, $data = []) {
        return self::log(self::TYPE_ADMIN, $eventName, $data);
    }

    /**
     * Log API access
     */
    public static function logAPI($eventName, $data = []) {
        return self::log(self::TYPE_API, $eventName, $data);
    }

    /**
     * Query audit logs with filters
     */
    public static function query($filters = [], $limit = 100, $offset = 0) {
        $db = getDB();

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = :event_type';
            $params[':event_type'] = $filters['event_type'];
        }

        if (!empty($filters['event_name'])) {
            $where[] = 'event_name LIKE :event_name';
            $params[':event_name'] = '%' . $filters['event_name'] . '%';
        }

        if (!empty($filters['severity'])) {
            $where[] = 'severity = :severity';
            $params[':severity'] = $filters['severity'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }

        if (!empty($filters['resource_type'])) {
            $where[] = 'resource_type = :resource_type';
            $params[':resource_type'] = $filters['resource_type'];
        }

        if (!empty($filters['resource_id'])) {
            $where[] = 'resource_id = :resource_id';
            $params[':resource_id'] = $filters['resource_id'];
        }

        if (!empty($filters['ip_address'])) {
            $where[] = 'ip_address = :ip_address';
            $params[':ip_address'] = $filters['ip_address'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(event_name LIKE :search OR resource_name LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM audit_log WHERE $whereClause";
        $stmt = $db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $total = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'];

        // Get results
        $sql = "
            SELECT al.*, u.username
            FROM audit_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $logs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Decode JSON fields
            if ($row['old_value']) $row['old_value'] = json_decode($row['old_value'], true);
            if ($row['new_value']) $row['new_value'] = json_decode($row['new_value'], true);
            if ($row['metadata']) $row['metadata'] = json_decode($row['metadata'], true);
            $logs[] = $row;
        }

        return [
            'data' => $logs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Export audit logs to CSV
     */
    public static function exportCSV($filters = [], $filename = null) {
        $result = self::query($filters, 100000, 0);

        if ($filename === null) {
            $filename = 'audit_log_' . date('Y-m-d_H-i-s') . '.csv';
        }

        $output = fopen('php://temp', 'r+');

        // Headers
        fputcsv($output, [
            'Timestamp',
            'Event Type',
            'Event Name',
            'Severity',
            'User ID',
            'Username',
            'IP Address',
            'Resource Type',
            'Resource ID',
            'Resource Name',
            'Old Value',
            'New Value',
            'Session ID',
            'Request ID'
        ]);

        // Data rows
        foreach ($result['data'] as $row) {
            fputcsv($output, [
                $row['created_at'],
                $row['event_type'],
                $row['event_name'],
                $row['severity'],
                $row['user_id'],
                $row['username'] ?? '',
                $row['ip_address'],
                $row['resource_type'],
                $row['resource_id'],
                $row['resource_name'],
                is_array($row['old_value']) ? json_encode($row['old_value']) : $row['old_value'],
                is_array($row['new_value']) ? json_encode($row['new_value']) : $row['new_value'],
                $row['session_id'],
                $row['request_id']
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return ['filename' => $filename, 'content' => $csv, 'count' => count($result['data'])];
    }

    /**
     * Export audit logs to JSON
     */
    public static function exportJSON($filters = [], $filename = null) {
        $result = self::query($filters, 100000, 0);

        if ($filename === null) {
            $filename = 'audit_log_' . date('Y-m-d_H-i-s') . '.json';
        }

        $json = json_encode($result['data'], JSON_PRETTY_PRINT);

        return ['filename' => $filename, 'content' => $json, 'count' => count($result['data'])];
    }

    /**
     * Generate compliance report
     */
    public static function generateComplianceReport($startDate, $endDate, $format = 'summary') {
        $db = getDB();

        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'summary' => [],
            'details' => []
        ];

        // Total events by type
        $stmt = $db->prepare('
            SELECT event_type, COUNT(*) as count
            FROM audit_log
            WHERE created_at >= :start AND created_at <= :end
            GROUP BY event_type
        ');
        $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
        $result = $stmt->execute();
        $report['summary']['events_by_type'] = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $report['summary']['events_by_type'][$row['event_type']] = $row['count'];
        }

        // Events by severity
        $stmt = $db->prepare('
            SELECT severity, COUNT(*) as count
            FROM audit_log
            WHERE created_at >= :start AND created_at <= :end
            GROUP BY severity
        ');
        $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
        $result = $stmt->execute();
        $report['summary']['events_by_severity'] = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $report['summary']['events_by_severity'][$row['severity']] = $row['count'];
        }

        // Authentication events
        $stmt = $db->prepare('
            SELECT event_name, COUNT(*) as count
            FROM audit_log
            WHERE created_at >= :start AND created_at <= :end
            AND event_type = :type
            GROUP BY event_name
        ');
        $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
        $stmt->bindValue(':type', self::TYPE_AUTH, SQLITE3_TEXT);
        $result = $stmt->execute();
        $report['summary']['auth_events'] = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $report['summary']['auth_events'][$row['event_name']] = $row['count'];
        }

        // Unique users
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT user_id) as count
            FROM audit_log
            WHERE created_at >= :start AND created_at <= :end
            AND user_id IS NOT NULL
        ');
        $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
        $report['summary']['unique_users'] = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['count'];

        // Unique IPs
        $stmt = $db->prepare('
            SELECT COUNT(DISTINCT ip_address) as count
            FROM audit_log
            WHERE created_at >= :start AND created_at <= :end
            AND ip_address IS NOT NULL
        ');
        $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
        $report['summary']['unique_ips'] = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['count'];

        // Security events (warning and above)
        $stmt = $db->prepare('
            SELECT event_name, severity, COUNT(*) as count
            FROM audit_log
            WHERE created_at >= :start AND created_at <= :end
            AND severity IN (:warn, :error, :critical)
            GROUP BY event_name, severity
            ORDER BY count DESC
        ');
        $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
        $stmt->bindValue(':warn', self::SEVERITY_WARNING, SQLITE3_TEXT);
        $stmt->bindValue(':error', self::SEVERITY_ERROR, SQLITE3_TEXT);
        $stmt->bindValue(':critical', self::SEVERITY_CRITICAL, SQLITE3_TEXT);
        $result = $stmt->execute();
        $report['summary']['security_events'] = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $report['summary']['security_events'][] = $row;
        }

        // If detailed report requested, include critical events
        if ($format === 'detailed') {
            $stmt = $db->prepare('
                SELECT al.*, u.username
                FROM audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.created_at >= :start AND al.created_at <= :end
                AND al.severity IN (:error, :critical)
                ORDER BY al.created_at DESC
                LIMIT 1000
            ');
            $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
            $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
            $stmt->bindValue(':error', self::SEVERITY_ERROR, SQLITE3_TEXT);
            $stmt->bindValue(':critical', self::SEVERITY_CRITICAL, SQLITE3_TEXT);
            $result = $stmt->execute();

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $report['details'][] = $row;
            }
        }

        return $report;
    }

    /**
     * Purge old audit logs
     */
    public static function purgeOldLogs($daysToKeep = 365) {
        $db = getDB();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

        $stmt = $db->prepare('DELETE FROM audit_log WHERE created_at < :cutoff');
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
        $stmt->execute();

        return $db->changes();
    }

    /**
     * Get statistics
     */
    public static function getStats() {
        $db = getDB();

        $stats = [];

        // Total logs
        $stats['total'] = $db->querySingle('SELECT COUNT(*) FROM audit_log');

        // Logs today
        $today = date('Y-m-d');
        $stmt = $db->prepare('SELECT COUNT(*) FROM audit_log WHERE date(created_at) = :today');
        $stmt->bindValue(':today', $today, SQLITE3_TEXT);
        $stats['today'] = $stmt->execute()->fetchArray()[0];

        // Oldest log
        $stats['oldest'] = $db->querySingle('SELECT MIN(created_at) FROM audit_log');

        // Database size estimate (row count * average row size)
        $stats['estimated_rows'] = $stats['total'];

        return $stats;
    }
}
