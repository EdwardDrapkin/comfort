<?php
namespace Comfort\Validator;

use Comfort\Comfort;
use Comfort\Error;
use Comfort\Exception\DiscomfortException;
use Comfort\Exception\ValidationException;
use Comfort\ValidationError;

/**
 * Class AbstractValidator
 * @package Comfort\Validator
 */
abstract class AbstractValidator
{
    /**
     * @var \Closure[]
     */
    protected $validationStack = [];
    /**
     * @var Comfort
     */
    private $comfort;
    /**
     * @var boolean
     */
    private $toBool = true;
    /**
     * @var array
     */
    protected $errorHandlers = [
        'default' => [
            'message' => 'There was a validation error'
        ],
        'required' => [
            'message' => '%s is required',
            'default' => 'value'
        ]
    ];

    public function __construct(Comfort $comfort)
    {

        $this->comfort = $comfort;
    }

    /**
     * Execute validation stack
     *
     * @param mixed $value
     * @param null|string $key
     * @return bool|ValidationError
     */
    public function __invoke($value, $key = null)
    {
        try {
            reset($this->validationStack);

            do {
                /** @var callable $validator */
                $validator = current($this->validationStack);
                $retVal = $validator($value, $key);
                $value = $retVal === null ? $value : $retVal;
            } while (next($this->validationStack));

            return $value;
        }catch(ValidationException $validationException) {
            if ($this->toBool) {
                return false;
            }

            return ValidationError::fromException($validationException);
        }
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->comfort, $name], $arguments);
    }

    /**
     * Declare the value as being required
     *
     * @return $this
     */
    public function required()
    {
        $this->add(function($value) {
            if (is_null($value)) {
                $this->createError('required', $value);
            }
        });

        return $this;
    }

    /**
     * Add adhoc validator to validation stack
     *
     * @param callable $validation
     */
    public function add(callable $validation)
    {
        $this->validationStack[] = $validation;
    }

    /**
     * On validation failure whether to return false or a validation error
     *
     * @param bool $val
     * @return $this
     */
    public function toBool($val = true)
    {
        $this->toBool = (boolean)$val;

        return $this;
    }

    /**
     * Provide conditional validation
     *
     * @param $conditions
     * @return $this
     */
    public function alternatives($conditions)
    {
        $this->add(function($value, $nameKey) use($conditions) {
            foreach ($conditions as $condition) {

                $is = $condition['is'];
                $is->toBool(true);

                if (!isset($condition['then'])) {
                    $this->createError('alternatives.missing_then', $value, $nameKey);
                }

                if ($is($value)) {
                    if ($condition['then'] instanceof AbstractValidator) {
                        $reflObject = new \ReflectionObject($condition['then']);
                        $validationStack = $reflObject->getProperty('validationStack');
                        $validationStack->setAccessible(true);
                        foreach ($validationStack->getValue($condition['then']) as $validator) {
                            $this->validationStack[] = $validator;
                        }
                    } elseif (!is_null($condition['then'])) {
                        return $condition['then'];
                    }
                } elseif (isset($condition['else'])) {
                    if ($condition['else'] instanceof AbstractValidator) {
                        $reflObject = new \ReflectionObject($condition['else']);
                        $validationStack = $reflObject->getProperty('validationStack');
                        $validationStack->setAccessible(true);
                        foreach ($validationStack->getValue($condition['else']) as $validator) {
                            $this->validationStack[] = $validator;
                        }
                    } elseif (!is_null($condition['else'])) {
                        return $condition['else'];
                    }
                }
            }
        });

        return $this;
    }

    /**
     * Create an error with a formatted message
     *
     * @param string $key
     * @param null|string $value
     * @param null|string $valueKey
     * @throws DiscomfortException
     * @throws ValidationException
     */
    protected function createError($key, $value = null, $valueKey = null)
    {
        if (!array_key_exists($key, $this->errorHandlers)) {
            throw new ValidationException(
                $key,
                $this->errorHandlers['default']['message']
            );
        }

        $errorHandler = $this->errorHandlers[$key];
        if (!array_key_exists('message_formatter', $errorHandler)) {
            $messageFormatter = function($template, $value) {
                return sprintf($template, $value);
            };
        } else {
            $messageFormatter = $errorHandler['message_formatter'];
            if (!is_callable($messageFormatter)) {
                throw new DiscomfortException('"message_formatter" must be callable');
            }
        }

        $templateValue = "'{$value}'";
        if (!is_null($valueKey)) {
            $templateValue = $valueKey;
        }

        $errorMessage = $messageFormatter(
            $errorHandler['message'],
            $templateValue ?: $errorHandler['value']
        );

        throw new ValidationException($key, $errorMessage);
    }
}