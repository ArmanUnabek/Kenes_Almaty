<?php
/**
 * Validator класс для валидации входных данных.
 * Поддерживает различные правила валидации и безопасность от XSS.
 */

namespace App;

class ValidationException extends \Exception {}

class Validator
{
    /**
     * Ошибки валидации
     */
    private array $errors = [];

    /**
     * Правила валидации
     */
    private array $rules = [];

    /**
     * Данные для валидации
     */
    private array $data = [];

    /**
     * Пользовательские сообщения об ошибках
     */
    private array $messages = [];

    /**
     * Валидировать данные по правилам
     *
     * @param array $data Данные для валидации
     * @param array $rules Правила валидации (field => 'rule1|rule2')
     * @param array $messages Пользовательские сообщения об ошибках
     * @return bool True если валидация прошла, False если есть ошибки
     *
     * @example
     * $validator = new Validator();
     * $valid = $validator->validate($_POST, [
     *     'email' => 'required|email',
     *     'name' => 'required|string|min:2|max:255',
     *     'age' => 'required|integer|min:0|max:150',
     *     'password' => 'required|string|min:8',
     *     'date' => 'required|date',
     *     'phone' => 'required|phone',
     * ]);
     * if (!$valid) {
     *     echo json_encode(['errors' => $validator->getErrors()]);
     * }
     */
    public function validate(array $data, array $rules, array $messages = []): bool
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
        $this->errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            foreach ($ruleList as $rule) {
                $this->applyRule($field, $rule);
                if (isset($this->errors[$field])) {
                    break; // Остановиться на первой ошибке для этого поля
                }
            }
        }

        return count($this->errors) === 0;
    }

    /**
     * Применить одно правило валидации
     */
    private function applyRule(string $field, string $rule): void
    {
        $value = $this->data[$field] ?? null;
        list($ruleName, $ruleParam) = $this->parseRule($rule);

        switch ($ruleName) {
            case 'required':
                if (empty($value) && $value !== '0' && $value !== 0) {
                    $this->addError($field, $ruleName, "Поле '{$field}' обязательно");
                }
                break;

            case 'email':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, $ruleName, "Поле '{$field}' должно быть корректным email");
                }
                break;

            case 'string':
                if ($value !== null && !is_string($value)) {
                    $this->addError($field, $ruleName, "Поле '{$field}' должно быть строкой");
                }
                break;

            case 'integer':
                if ($value !== null && !is_numeric($value) && !is_int($value)) {
                    $this->addError($field, $ruleName, "Поле '{$field}' должно быть числом");
                }
                break;

            case 'min':
                if ($value !== null) {
                    $minLength = (int)$ruleParam;
                    if (is_string($value) && strlen($value) < $minLength) {
                        $this->addError($field, $ruleName, "Поле '{$field}' должно быть не менее {$minLength} символов");
                    } elseif (is_numeric($value) && (int)$value < $minLength) {
                        $this->addError($field, $ruleName, "Поле '{$field}' должно быть не менее {$minLength}");
                    }
                }
                break;

            case 'max':
                if ($value !== null) {
                    $maxLength = (int)$ruleParam;
                    if (is_string($value) && strlen($value) > $maxLength) {
                        $this->addError($field, $ruleName, "Поле '{$field}' должно быть не более {$maxLength} символов");
                    } elseif (is_numeric($value) && (int)$value > $maxLength) {
                        $this->addError($field, $ruleName, "Поле '{$field}' должно быть не более {$maxLength}");
                    }
                }
                break;

            case 'date':
                if ($value !== null && !$this->isValidDate($value)) {
                    $this->addError($field, $ruleName, "Поле '{$field}' должно быть корректной датой (YYYY-MM-DD)");
                }
                break;

            case 'phone':
                if ($value !== null && !$this->isValidPhone($value)) {
                    $this->addError($field, $ruleName, "Поле '{$field}' должно быть корректным номером телефона");
                }
                break;

            case 'url':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, $ruleName, "Поле '{$field}' должно быть корректным URL");
                }
                break;

            case 'in':
                if ($value !== null) {
                    $allowed = explode(',', $ruleParam);
                    $allowed = array_map('trim', $allowed);
                    if (!in_array($value, $allowed, true)) {
                        $this->addError($field, $ruleName, "Поле '{$field}' имеет недопустимое значение");
                    }
                }
                break;

            case 'regex':
                if ($value !== null && !preg_match($ruleParam, $value)) {
                    $this->addError($field, $ruleName, "Поле '{$field}' имеет неправильный формат");
                }
                break;
        }
    }

    /**
     * Распарсить правило (например, 'min:5' => ['min', '5'])
     */
    private function parseRule(string $rule): array
    {
        $parts = explode(':', $rule, 2);
        return [
            trim($parts[0]),
            isset($parts[1]) ? trim($parts[1]) : null,
        ];
    }

    /**
     * Проверить, является ли дата корректной (YYYY-MM-DD)
     */
    private function isValidDate(string $date): bool
    {
        $format = 'Y-m-d';
        $dt = \DateTime::createFromFormat($format, $date);
        return $dt && $dt->format($format) === $date;
    }

    /**
     * Проверить, является ли номер телефона корректным
     * Поддерживает форматы: +7XXXXXXXXXX, 87XXXXXXXXX, 07XXXXXXXXX
     */
    private function isValidPhone(string $phone): bool
    {
        return (bool)preg_match('/^(\+7|8|0)\d{9,11}$/', preg_replace('/[^0-9+]/', '', $phone));
    }

    /**
     * Добавить ошибку
     */
    private function addError(string $field, string $ruleName, string $defaultMessage): void
    {
        $messageKey = "{$field}.{$ruleName}";
        $message = $this->messages[$messageKey] ?? $defaultMessage;
        $this->errors[$field] = $message;
    }

    /**
     * Получить все ошибки
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Получить первую ошибку
     */
    public function getFirstError(): string
    {
        return reset($this->errors) ?: '';
    }

    /**
     * Получить ошибку для конкретного поля
     */
    public function getFieldError(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Есть ли ошибки
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Очистить данные от XSS (экранировать HTML)
     * Возвращает очищенный массив или строку
     */
    public static function sanitize($data): mixed
    {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }

        if (is_string($data)) {
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }

        return $data;
    }

    /**
     * Экранировать данные для вывода в HTML
     */
    public static function escape(string $data): string
    {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}
