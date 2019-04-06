<?php

namespace Cron;

use \DateTime;
use \DateTimeZone;
use \Exception;

/**
 * Cron expression parser and validator
 *
 * @author René Pollesch
 */
class CronExpression
{
    /**
     * Weekday name look-up table
     */
    private const WEEKDAY_NAMES = [
        'sun' => 0,
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6
    ];

    /**
     * Month name look-up table
     */
    private const MONTH_NAMES = [
        'jan' => 1,
        'feb' => 2,
        'mar' => 3,
        'apr' => 4,
        'may' => 5,
        'jun' => 6,
        'jul' => 7,
        'aug' => 8,
        'sep' => 9,
        'oct' => 10,
        'nov' => 11,
        'dec' => 12
    ];

    /**
     * Value boundaries
     */
    private const VALUE_BOUNDARIES = [
        0 => [
            'min' => 0,
            'max' => 59,
            'mod' => 1
        ],
        1 => [
            'min' => 0,
            'max' => 23,
            'mod' => 1
        ],
        2 => [
            'min' => 1,
            'max' => 31,
            'mod' => 1
        ],
        3 => [
            'min' => 1,
            'max' => 12,
            'mod' => 1
        ],
        4 => [
            'min' => 0,
            'max' => 7,
            'mod' => 0
        ]
    ];

    /**
     * Time zone
     *
     * @var DateTimeZone|null
     */
    protected $timeZone = null;

    /**
     * Matching registers
     *
     * @var array|null
     */
    protected $registers = null;

    /**
     * Class constructor sets cron expression property
     *
     * @param string $expression cron expression
     * @param DateTimeZone|null $timeZone
     */
    public function __construct(string $expression, DateTimeZone $timeZone = null)
    {
        $this->timeZone = $timeZone;

        try {
            $this->registers = $this->parse($expression);
        } catch (Exception $e) {
            $this->registers = null;
        }
    }

    /**
     * Calculate next matching timestamp
     *
     * @param mixed $start either a \DateTime object, a timestamp or null for current date/time
     * @return int|bool next matching timestamp, or false on error
     * @throws Exception
     */
    public function getNext($start = null)
    {
        $result = false;

        if ($this->isValid()) {
            if ($start instanceof DateTime) {
                $timestamp = $start->getTimestamp();
            } elseif ((int)$start > 0) {
                $timestamp = $start;
            } else {
                $timestamp = time();
            }

            $now = new DateTime('now', $this->timeZone);
            $now->setTimestamp(intval(ceil($timestamp / 60)) * 60);

            if ($this->isMatching($now)) {
                $now->modify('+1 minute');
            }

            $pointer = sscanf($now->format('i G j n Y'), '%d %d %d %d %d');

            do {
                $current = $this->adjust($now, $pointer);
            } while ($this->forward($now, $current));

            $result = $now->getTimestamp();
        }

        return $result;
    }

    /**
     * @param DateTime $now
     * @param array $pointer
     * @return array
     */
    private function adjust(DateTime $now, array &$pointer): array
    {
        $current = sscanf($now->format('i G j n Y w'), '%d %d %d %d %d %d');

        if ($pointer[1] !== $current[1]) {
            $pointer[1] = $current[1];
            $now->setTime($current[1], 0);
        } elseif ($pointer[0] !== $current[0]) {
            $pointer[0] = $current[0];
            $now->setTime($current[1], $current[0]);
        } elseif ($pointer[4] !== $current[4]) {
            $pointer[4] = $current[4];
            $now->setDate($current[4], 1, 1);
            $now->setTime(0, 0);
        } elseif ($pointer[3] !== $current[3]) {
            $pointer[3] = $current[3];
            $now->setDate($current[4], $current[3], 1);
            $now->setTime(0, 0);
        } elseif ($pointer[2] !== $current[2]) {
            $pointer[2] = $current[2];
            $now->setTime(0, 0);
        }

        return $current;
    }

    /**
     * @param DateTime $now
     * @param array $current
     * @return bool
     */
    private function forward(DateTime $now, array $current): bool
    {
        $result = false;

        if (isset($this->registers[3][$current[3]]) === false) {
            $now->modify('+1 month');
            $result = true;
        } elseif (false === (isset($this->registers[2][$current[2]]) && isset($this->registers[4][$current[5]]))) {
            $now->modify('+1 day');
            $result = true;
        } elseif (isset($this->registers[1][$current[1]]) === false) {
            $now->modify('+1 hour');
            $result = true;
        } elseif (isset($this->registers[0][$current[0]]) === false) {
            $now->modify('+1 minute');
            $result = true;
        }

        return $result;
    }

    /**
     * Parse and validate cron expression
     *
     * @return bool true if expression is valid, or false on error
     */
    public function isValid(): bool
    {
        return null !== $this->registers;
    }

    /**
     * Match current or given date/time against cron expression
     *
     * @param mixed $now \DateTime object, timestamp or null
     * @return bool
     * @throws Exception
     */
    public function isMatching($now = null): bool
    {
        if (false === ($now instanceof DateTime)) {
            $now = (new DateTime())->setTimestamp($now === null ? time() : $now);
        }

        if ($this->timeZone !== null) {
            $now->setTimezone($this->timeZone);
        }

        try {
            $result = null !== $this->registers && $this->match(sscanf($now->format('i G j n w'), '%d %d %d %d %d'));
        } catch (Exception $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * @param array $segments
     * @return bool
     * @throws Exception
     */
    private function match(array $segments): bool
    {
        $result = true;

        foreach ($this->registers as $i => $item) {
            if (isset($item[(int)$segments[$i]]) === false) {
                $result = false;
                break;
            }
        }

        return $result;
    }

    /**
     * Parse whole cron expression
     *
     * @param string $expression
     * @return array
     * @throws Exception
     */
    private function parse(string $expression): array
    {
        $segments = preg_split('/\s+/', trim($expression));

        if (is_array($segments) && sizeof($segments) === 5) {
            $registers = array_fill(0, 5, []);

            foreach ($segments as $index => $segment) {
                $this->parseSegment($registers[$index], $index, $segment);
            }

            if (isset($registers[4][7])) {
                $registers[4][0] = true;
            }

            return $registers;
        }

        throw new Exception('invalid number of segments');
    }

    /**
     * Parse one segment of a cron expression
     *
     * @param array $register
     * @param int $index
     * @param string $segment
     * @throws Exception
     */
    private function parseSegment(array &$register, $index, $segment)
    {
        $allowed = [false, false, false, self::MONTH_NAMES, self::WEEKDAY_NAMES];

        // month names, weekdays
        if ($allowed[$index] !== false && isset($allowed[$index][strtolower($segment)])) {
            // cannot be used together with lists or ranges
            $register[$allowed[$index][strtolower($segment)]] = true;
        } else {
            // split up current segment into single elements, e.g. "1,5-7,*/2" => [ "1", "5-7", "*/2" ]
            foreach (explode(',', $segment) as $element) {
                $this->parseElement($register, $index, $element);
            }
        }
    }

    /**
     * @param array $register
     * @param int $index
     * @param string $element
     * @throws Exception
     */
    private function parseElement(array &$register, int $index, string $element)
    {
        $step = 1;
        $segments = explode('/', $element);

        if (sizeof($segments) > 1) {
            $this->validateStepping($segments, $index);

            $element = (string)$segments[0];
            $step = (int)$segments[1];
        }

        if (is_numeric($element)) {
            $this->validateValue($element, $index, $step);
            $register[intval($element)] = true;
        } else {
            $this->parseRange($register, $index, $element, $step);
        }
    }

    /**
     * Parse range of values, e.g. "5-10"
     *
     * @param array $register
     * @param int $index
     * @param string $range
     * @param int $stepping
     * @throws Exception
     */
    private function parseRange(array &$register, int $index, string $range, int $stepping)
    {
        if ($range === '*') {
            $range = [self::VALUE_BOUNDARIES[$index]['min'], self::VALUE_BOUNDARIES[$index]['max']];
        } else {
            $range = explode('-', $range);
        }

        $this->validateRange($range, $index);
        $this->fillRange($register, $index, $range, $stepping);
    }

    /**
     * Validate whether a given range of values exceeds allowed value boundaries
     *
     * @param array $range
     * @param int $index
     * @throws Exception
     */
    private function validateRange(array $range, int $index)
    {
        if (sizeof($range) !== 2) {
            throw new Exception('invalid range notation');
        }

        foreach ($range as $value) {
            $this->validateValue($value, $index);
        }
    }

    /**
     * @param string $value
     * @param int $index
     * @param int $step
     * @throws Exception
     */
    private function validateValue(string $value, int $index, int $step = 1)
    {
        if ((string)$value !== (string)(int)$value) {
            throw new Exception('non-integer value');
        }

        if (intval($value) < self::VALUE_BOUNDARIES[$index]['min'] ||
            intval($value) > self::VALUE_BOUNDARIES[$index]['max']
        ) {
            throw new Exception('value out of boundary');
        }

        if ($step !== 1) {
            throw new Exception('invalid combination of value and stepping notation');
        }
    }

    /**
     * @param array $segments
     * @param int $index
     * @throws Exception
     */
    private function validateStepping(array $segments, int $index)
    {
        if (sizeof($segments) !== 2) {
            throw new Exception('invalid stepping notation');
        }

        if ((int)$segments[1] < 1 || (int)$segments[1] > self::VALUE_BOUNDARIES[$index]['max']) {
            throw new Exception('stepping out of allowed range');
        }
    }

    /**
     * @param array $register
     * @param int $index
     * @param array $range
     * @param int $stepping
     */
    private function fillRange(array &$register, int $index, array $range, int $stepping)
    {
        $boundary = self::VALUE_BOUNDARIES[$index]['max'] + self::VALUE_BOUNDARIES[$index]['mod'];
        $length = $range[1] - $range[0];

        if ($range[0] > $range[1]) {
            $length += $boundary;
        }

        for ($i = 0; $i <= $length; $i += $stepping) {
            $register[($range[0] + $i) % $boundary] = true;
        }
    }
}
