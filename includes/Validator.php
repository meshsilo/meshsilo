<?php
/**
 * Input Validation System
 *
 * Provides fluent, chainable validation with clear error messages.
 * Supports common validation rules and custom validators.
 */

class Validator {
    private array $data = [];
    private array $rules = [];
    private array $errors = [];
    private array $validated = [];
    private array $customMessages = [];

    // Default error messages
    private static array $messages = [
        'required' => 'The %s field is required.',
        'string' => 'The %s field must be a string.',
        'integer' => 'The %s field must be an integer.',
        'numeric' => 'The %s field must be a number.',
        'email' => 'The %s field must be a valid email address.',
        'url' => 'The %s field must be a valid URL.',
        'min' => 'The %s field must be at least %s.',
        'max' => 'The %s field must not exceed %s.',
        'min_length' => 'The %s field must be at least %s characters.',
        'max_length' => 'The %s field must not exceed %s characters.',
        'between' => 'The %s field must be between %s and %s.',
        'in' => 'The %s field must be one of: %s.',
        'not_in' => 'The %s field must not be one of: %s.',
        'regex' => 'The %s field format is invalid.',
        'alpha' => 'The %s field must only contain letters.',
        'alpha_num' => 'The %s field must only contain letters and numbers.',
        'alpha_dash' => 'The %s field must only contain letters, numbers, dashes, and underscores.',
        'date' => 'The %s field must be a valid date.',
        'date_format' => 'The %s field must match the format %s.',
        'before' => 'The %s field must be a date before %s.',
        'after' => 'The %s field must be a date after %s.',
        'confirmed' => 'The %s confirmation does not match.',
        'same' => 'The %s and %s fields must match.',
        'different' => 'The %s and %s fields must be different.',
        'unique' => 'The %s has already been taken.',
        'exists' => 'The selected %s is invalid.',
        'file' => 'The %s must be a file.',
        'file_type' => 'The %s must be a file of type: %s.',
        'file_size' => 'The %s must not exceed %s.',
        'image' => 'The %s must be an image.',
        'boolean' => 'The %s field must be true or false.',
        'array' => 'The %s field must be an array.',
        'json' => 'The %s field must be valid JSON.',
        'ip' => 'The %s field must be a valid IP address.',
        'slug' => 'The %s field must be a valid slug (lowercase letters, numbers, and hyphens).',
        'uuid' => 'The %s field must be a valid UUID.',
    ];

    /**
     * Create a new validator instance
     */
    public function __construct(array $data = [], array $rules = []) {
        $this->data = $data;
        if (!empty($rules)) {
            $this->setRules($rules);
        }
    }

    /**
     * Static factory method
     */
    public static function make(array $data, array $rules = []): self {
        return new self($data, $rules);
    }

    /**
     * Validate request data
     */
    public static function request(array $rules = []): self {
        $data = array_merge($_GET, $_POST);

        // Include JSON body if present
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $json = json_decode(file_get_contents('php://input'), true);
            if (is_array($json)) {
                $data = array_merge($data, $json);
            }
        }

        return new self($data, $rules);
    }

    /**
     * Set validation rules
     */
    public function setRules(array $rules): self {
        foreach ($rules as $field => $fieldRules) {
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }
            $this->rules[$field] = $fieldRules;
        }
        return $this;
    }

    /**
     * Add a single rule
     */
    public function addRule(string $field, $rule): self {
        if (!isset($this->rules[$field])) {
            $this->rules[$field] = [];
        }
        $this->rules[$field][] = $rule;
        return $this;
    }

    /**
     * Set custom error messages
     */
    public function messages(array $messages): self {
        $this->customMessages = $messages;
        return $this;
    }

    /**
     * Run validation
     */
    public function validate(): bool {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $rules) {
            $value = $this->getValue($field);
            $isRequired = in_array('required', $rules) || in_array('required', array_map(fn($r) => is_string($r) ? explode(':', $r)[0] : '', $rules));

            // Skip validation if field is empty and not required
            if (!$isRequired && $this->isEmpty($value)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (!$this->validateRule($field, $value, $rule)) {
                    break; // Stop on first error for this field
                }
            }

            // Add to validated data if no errors
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }

        return empty($this->errors);
    }

    /**
     * Check if validation passed
     */
    public function passes(): bool {
        return $this->validate();
    }

    /**
     * Check if validation failed
     */
    public function fails(): bool {
        return !$this->validate();
    }

    /**
     * Get validation errors
     */
    public function errors(): array {
        return $this->errors;
    }

    /**
     * Get first error for a field
     */
    public function error(string $field): ?string {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * Get all errors as flat array
     */
    public function allErrors(): array {
        $all = [];
        foreach ($this->errors as $fieldErrors) {
            $all = array_merge($all, $fieldErrors);
        }
        return $all;
    }

    /**
     * Get validated data
     */
    public function validated(): array {
        return $this->validated;
    }

    /**
     * Get a validated value
     */
    public function get(string $field, $default = null) {
        return $this->validated[$field] ?? $default;
    }

    /**
     * Validate and throw exception on failure
     */
    public function validateOrFail(): array {
        if (!$this->validate()) {
            throw new ValidationException($this->errors);
        }
        return $this->validated;
    }

    /**
     * Get value from data using dot notation
     */
    private function getValue(string $field) {
        if (strpos($field, '.') === false) {
            return $this->data[$field] ?? null;
        }

        $keys = explode('.', $field);
        $value = $this->data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * Check if value is empty
     */
    private function isEmpty($value): bool {
        return $value === null || $value === '' || $value === [] ||
               (is_string($value) && trim($value) === '');
    }

    /**
     * Validate a single rule
     */
    private function validateRule(string $field, $value, $rule): bool {
        // Parse rule and parameters
        if (is_string($rule)) {
            $parts = explode(':', $rule, 2);
            $ruleName = $parts[0];
            $params = isset($parts[1]) ? explode(',', $parts[1]) : [];
        } elseif (is_callable($rule)) {
            return $this->validateCallback($field, $value, $rule);
        } else {
            return true;
        }

        // Get validation method
        $method = 'validate' . str_replace('_', '', ucwords($ruleName, '_'));

        if (method_exists($this, $method)) {
            $result = $this->$method($field, $value, $params);
            if ($result !== true) {
                $this->addError($field, $ruleName, $params);
                return false;
            }
            return true;
        }

        // Unknown rule
        return true;
    }

    /**
     * Validate with callback
     */
    private function validateCallback(string $field, $value, callable $callback): bool {
        $result = $callback($value, $field, $this->data);
        if ($result !== true) {
            $message = is_string($result) ? $result : "The $field field is invalid.";
            $this->errors[$field][] = $message;
            return false;
        }
        return true;
    }

    /**
     * Add an error message
     */
    private function addError(string $field, string $rule, array $params = []): void {
        // Check for custom message
        $customKey = "$field.$rule";
        if (isset($this->customMessages[$customKey])) {
            $message = $this->customMessages[$customKey];
        } elseif (isset($this->customMessages[$field])) {
            $message = $this->customMessages[$field];
        } elseif (isset(self::$messages[$rule])) {
            $message = self::$messages[$rule];
        } else {
            $message = "The $field field is invalid.";
        }

        // Format message with field name and parameters
        $fieldName = str_replace('_', ' ', $field);
        $args = array_merge([$fieldName], $params);
        $message = vsprintf($message, $args);

        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    // ========================================
    // Validation Rules
    // ========================================

    protected function validateRequired(string $field, $value, array $params): bool {
        return !$this->isEmpty($value);
    }

    protected function validateString(string $field, $value, array $params): bool {
        return is_string($value);
    }

    protected function validateInteger(string $field, $value, array $params): bool {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    protected function validateNumeric(string $field, $value, array $params): bool {
        return is_numeric($value);
    }

    protected function validateEmail(string $field, $value, array $params): bool {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function validateUrl(string $field, $value, array $params): bool {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function validateMin(string $field, $value, array $params): bool {
        $min = $params[0] ?? 0;
        if (is_numeric($value)) {
            return $value >= $min;
        }
        return strlen($value) >= $min;
    }

    protected function validateMax(string $field, $value, array $params): bool {
        $max = $params[0] ?? PHP_INT_MAX;
        if (is_numeric($value)) {
            return $value <= $max;
        }
        return strlen($value) <= $max;
    }

    protected function validateMinLength(string $field, $value, array $params): bool {
        return strlen($value) >= ($params[0] ?? 0);
    }

    protected function validateMaxLength(string $field, $value, array $params): bool {
        return strlen($value) <= ($params[0] ?? PHP_INT_MAX);
    }

    protected function validateBetween(string $field, $value, array $params): bool {
        $min = $params[0] ?? 0;
        $max = $params[1] ?? PHP_INT_MAX;
        if (is_numeric($value)) {
            return $value >= $min && $value <= $max;
        }
        $len = strlen($value);
        return $len >= $min && $len <= $max;
    }

    protected function validateIn(string $field, $value, array $params): bool {
        return in_array($value, $params, true);
    }

    protected function validateNotIn(string $field, $value, array $params): bool {
        return !in_array($value, $params, true);
    }

    protected function validateRegex(string $field, $value, array $params): bool {
        $pattern = $params[0] ?? '';
        return preg_match($pattern, $value) === 1;
    }

    protected function validateAlpha(string $field, $value, array $params): bool {
        return preg_match('/^[a-zA-Z]+$/', $value) === 1;
    }

    protected function validateAlphaNum(string $field, $value, array $params): bool {
        return preg_match('/^[a-zA-Z0-9]+$/', $value) === 1;
    }

    protected function validateAlphaDash(string $field, $value, array $params): bool {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $value) === 1;
    }

    protected function validateDate(string $field, $value, array $params): bool {
        return strtotime($value) !== false;
    }

    protected function validateDateFormat(string $field, $value, array $params): bool {
        $format = $params[0] ?? 'Y-m-d';
        $date = DateTime::createFromFormat($format, $value);
        return $date && $date->format($format) === $value;
    }

    protected function validateBefore(string $field, $value, array $params): bool {
        $date = $params[0] ?? 'now';
        return strtotime($value) < strtotime($date);
    }

    protected function validateAfter(string $field, $value, array $params): bool {
        $date = $params[0] ?? 'now';
        return strtotime($value) > strtotime($date);
    }

    protected function validateConfirmed(string $field, $value, array $params): bool {
        $confirmField = $field . '_confirmation';
        return $value === ($this->data[$confirmField] ?? null);
    }

    protected function validateSame(string $field, $value, array $params): bool {
        $otherField = $params[0] ?? '';
        return $value === ($this->data[$otherField] ?? null);
    }

    protected function validateDifferent(string $field, $value, array $params): bool {
        $otherField = $params[0] ?? '';
        return $value !== ($this->data[$otherField] ?? null);
    }

    protected function validateBoolean(string $field, $value, array $params): bool {
        return in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off'], true);
    }

    protected function validateArray(string $field, $value, array $params): bool {
        return is_array($value);
    }

    protected function validateJson(string $field, $value, array $params): bool {
        if (!is_string($value)) return false;
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function validateIp(string $field, $value, array $params): bool {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    protected function validateSlug(string $field, $value, array $params): bool {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
    }

    protected function validateUuid(string $field, $value, array $params): bool {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    protected function validateFile(string $field, $value, array $params): bool {
        return isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK;
    }

    protected function validateFileType(string $field, $value, array $params): bool {
        if (!isset($_FILES[$field])) return false;
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        return in_array($ext, $params);
    }

    protected function validateFileSize(string $field, $value, array $params): bool {
        if (!isset($_FILES[$field])) return false;
        $maxSize = $this->parseSize($params[0] ?? '10M');
        return $_FILES[$field]['size'] <= $maxSize;
    }

    protected function validateImage(string $field, $value, array $params): bool {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        $imageInfo = @getimagesize($_FILES[$field]['tmp_name']);
        return $imageInfo !== false;
    }

    /**
     * Parse size string (e.g., "10M", "1G") to bytes
     */
    private function parseSize(string $size): int {
        $size = trim($size);
        $last = strtoupper(substr($size, -1));
        $value = (int)$size;

        switch ($last) {
            case 'G': $value *= 1024;
            case 'M': $value *= 1024;
            case 'K': $value *= 1024;
        }

        return $value;
    }
}

/**
 * Validation Exception
 */
class ValidationException extends Exception {
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed') {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    public function errors(): array {
        return $this->errors;
    }

    public function firstError(): ?string {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? null;
        }
        return null;
    }
}

/**
 * Helper function
 */
function validate(array $data, array $rules): Validator {
    return Validator::make($data, $rules);
}
