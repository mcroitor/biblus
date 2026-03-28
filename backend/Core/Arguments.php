<?php

namespace Core;

/**
 * Class to handle command-line arguments and options.
 * Argument definition format:
 * [
 *     'name' => [
 *         'short' => 'n',
 *         'long' => 'name',
 *         'description' => 'Description of the argument',
 *         'required' => true,
 *         'default' => 'default_value'
 *     ]
 * ]
 */
class Arguments
{
    private static $args = [];
    private static $values = [];

    /**
     * Проверяет, что все обязательные аргументы присутствуют и не равны null.
     * Выбрасывает исключение, если какой-либо обязательный аргумент отсутствует.
     * @throws \InvalidArgumentException
     */

    public static function Set($arguments)
    {
        self::$args = $arguments;
        self::$values = [];
        foreach ($arguments as $name => $def) {
            self::$values[$name] = $def['default'] ?? null;
        }
    }

    public static function Append(string $name, array $definition)
    {
        self::$args[$name] = $definition;
        self::$values[$name] = $definition['default'] ?? null;
    }

    public static function Get($name)
    {
        return self::$args[$name] ?? null;
    }

    public static function GetValue($name)
    {
        return self::$values[$name] ?? null;
    }

    public static function All()
    {
        return self::$args;
    }

    public static function Values()
    {
        return self::$values;
    }

    public static function ShortOptions()
    {
        $shortOpts = '';
        foreach (self::$args as $name => $def) {
            if (isset($def['short'])) {
                $shortOpts .= $def['short'];
                if (!empty($def['required'])) {
                    $shortOpts .= ':';
                } else if (isset($def['default'])) {
                    $shortOpts .= '::';
                }
            }
        }
        return $shortOpts;
    }

    public static function LongOptions()
    {
        $longOpts = [];
        foreach (self::$args as $name => $def) {
            if (isset($def['long'])) {
                $opt = $def['long'];
                if (!empty($def['required'])) {
                    $opt .= ':';
                } else if (isset($def['default'])) {
                    $opt .= '::';
                }
                $longOpts[] = $opt;
            }
        }
        return $longOpts;
    }

    public static function Help()
    {
        $helpText = "Options:\n";
        foreach (self::$args as $name => $def) {
            $short = isset($def['short']) ? "-{$def['short']}," : "   ";
            $long = isset($def['long']) ? "--{$def['long']}" : '';
            $desc = $def['description'] ?? '';
            $helpText .= "  {$short} {$long}\t{$desc}\n";
        }
        return $helpText;
    }
    
    private static function GetArgumentName($opt)
    {
        foreach (self::$args as $name => $def) {
            if (isset($def['short']) && $def['short'] === $opt) {
                return $name;
            }
            if (isset($def['long']) && $def['long'] === $opt) {
                return $name;
            }
        }
        return null;
    }

    public static function GetRequiredArguments()
    {
        $required = [];
        foreach (self::$args as $name => $def) {
            if (!empty($def['required'])) {
                $required[] = $name;
            }
        }
        return $required;
    }

    public static function ValidateRequired(array $result) {
        $required = self::GetRequiredArguments();
        foreach ($required as $requiredArg) {
            $shortOpt = self::$args[$requiredArg]['short'] ?? null;
            $longOpt = self::$args[$requiredArg]['long'] ?? null;
            if (($shortOpt && !isset($result[$shortOpt])) && ($longOpt && !isset($result[$longOpt]))) {
                throw new \InvalidArgumentException("Missing required argument: {$requiredArg}");
            }
        }
    }

    public static function Parse()
    {
        $shortOpts = self::ShortOptions();
        $longOpts = self::LongOptions();
        $result = getopt($shortOpts, $longOpts);

        try {
            self::ValidateRequired($result);
        }
        catch (\InvalidArgumentException $e) {
            echo "Error: " . $e->getMessage() . "\n";
            echo self::Help();
            exit(1);
        }

        foreach ($result as $key => $value) {
            $argName = self::GetArgumentName($key);
            if ($argName !== null) {
                self::$values[$argName] = ($value !== false) ? $value : true;
            }
        }
        return $result;
    }
}
