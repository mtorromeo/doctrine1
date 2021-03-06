<?php

use Laminas\Validator\ValidatorInterface;

class Doctrine_Validator
{
    /**
     * This was undefined, added for static analysis and set to public so api isn't changed
     *
     * @var array
     */
    public $stack;

    /**
     * Get a validator instance for the passed $name
     *
     * @param string $name Name of the validator or the validator class name
     * @param mixed[] $options The validator options
     */
    public static function getValidator(string $name, array $options = []): ValidatorInterface
    {
        $validators = Doctrine_Manager::getInstance()->getValidators();

        if (isset($validators[$name])) {
            $validatorClass = $validators[$name];
            if (is_array($validatorClass)) {
                $options = array_merge($validatorClass['options'], $options);
                $validatorClass = $validatorClass['class'];
            }
        } elseif (class_exists($name) && in_array(ValidatorInterface::class, class_implements($name) ?: [])) {
            $validatorClass = $name;
        } else {
            throw new Doctrine_Exception("Validator named '$name' not available.");
        }

        return new $validatorClass($options);
    }

    /**
     * Validates a given record and saves possible errors in Doctrine_Validator::$stack
     */
    public function validateRecord(Doctrine_Record $record): void
    {
        /** @phpstan-var Doctrine_Table */
        $table = $record->getTable();

        // if record is transient all fields will be validated
        // if record is persistent only the modified fields will be validated
        $fields = $record->exists() ? $record->getModified() : $record->getData();
        foreach ($fields as $fieldName => $value) {
            $table->validateField($fieldName, $value, $record);
        }
    }

    /**
     * Validates the length of a field.
     *
     * @param  string          $value         Value to validate
     * @param  string          $type          Type of field being validated
     * @param  string|null|int $maximumLength Maximum length allowed for the column
     */
    public static function validateLength($value, $type, $maximumLength): bool
    {
        if ($maximumLength === null) {
            return true;
        }
        if ($type === 'timestamp' || $type === 'integer' || $type === 'enum' || $type === 'set') {
            return true;
        } elseif ($type == 'array' || $type == 'object') {
            $length = strlen(serialize($value));
        } elseif ($type == 'decimal' || $type == 'float') {
            $value = abs($value);

            $localeInfo = localeconv();
            $decimalPoint = $localeInfo['mon_decimal_point'] ? $localeInfo['mon_decimal_point'] : $localeInfo['decimal_point'];
            $e = explode($decimalPoint, (string) $value);

            $length = strlen($e[0]);

            if (isset($e[1])) {
                $length = $length + strlen($e[1]);
            }
        } elseif ($type == 'blob') {
            $length = strlen($value);
        } else {
            $validator = new Laminas\Validator\StringLength([
                'max' => $maximumLength,
            ]);
            return $validator->isValid($value);
        }
        if ($length > $maximumLength) {
            return false;
        }
        return true;
    }

    /**
     * Whether or not errors exist on this validator
     */
    public function hasErrors(): bool
    {
        return count($this->stack) > 0;
    }

    /**
     * Validate the type of the passed variable
     *
     * @param  mixed  $var  Variable to validate
     * @param  string $type Type of the variable expected
     */
    public static function isValidType($var, $type): bool
    {
        if ($var instanceof Doctrine_Expression) {
            return true;
        } elseif ($var === null) {
            return true;
        } elseif (is_object($var)) {
            return $type == 'object';
        } elseif (is_array($var)) {
            return $type == 'array';
        }

        switch ($type) {
            case 'float':
            case 'double':
            case 'decimal':
                return (string) $var == strval(floatval($var));
            case 'integer':
                return (string) $var == strval(round(floatval($var)));
            case 'string':
                return is_string($var) || is_numeric($var);
            case 'blob':
                return is_string($var) || is_resource($var);
            case 'clob':
            case 'gzip':
                return is_string($var);
            case 'array':
                return is_array($var);
            case 'object':
                return is_object($var);
            case 'boolean':
                return is_bool($var) || (is_numeric($var) && ($var == 0 || $var == 1));
            case 'timestamp':
                $validator = new Doctrine_Validator_Timestamp();
                return $validator->isValid($var);
            case 'time':
                $validator = new Doctrine_Validator_Time();
                return $validator->isValid($var);
            case 'date':
                $validator = new Doctrine_Validator_Date();
                return $validator->isValid($var);
            case 'enum':
                return is_string($var) || is_int($var);
            case 'set':
                return is_array($var) || is_string($var);
            default:
                return true;
        }
    }
}
