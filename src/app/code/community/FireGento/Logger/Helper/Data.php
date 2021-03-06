<?php
/**
 * This file is part of a FireGento e.V. module.
 *
 * This FireGento e.V. module is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 5
 *
 * @category  FireGento
 * @package   FireGento_Logger
 * @author    FireGento Team <team@firegento.com>
 * @copyright 2013 FireGento Team (http://www.firegento.com)
 * @license   http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 */
/**
 * Helper Class
 *
 * @category FireGento
 * @package  FireGento_Logger
 * @author   FireGento Team <team@firegento.com>
 */
class FireGento_Logger_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH_PRIORITY = 'general/priority';
    const XML_PATH_MAX_DAYS = 'db/max_days_to_keep';

    /**
     * @var null
     */
    protected $_targetMap = null;

    /**
     * Get logger config value
     *
     * @param  string $path Config Path
     * @return string Config Value
     */
    public function getLoggerConfig($path)
    {
        return (string) Mage::getConfig()->getNode('default/logger/'.$path);
    }

    /**
     * Returns an array of targets mapped or null if there was an error or there is no map.
     * Keys are target codes, values are bool indicating if backtrace is enabled
     *
     * @param  string $filename Filename
     * @return null|array Mapped Targets
     */
    public function getMappedTargets($filename)
    {
        if ($this->_targetMap === null) {
            $targetMap = $this->getLoggerConfig('general/target_map');
            if ($targetMap) {
                $this->_targetMap = @unserialize($targetMap);
            } else {
                $this->_targetMap = false;
            }
        }
        if (! $this->_targetMap) {
            return null;
        }
        $targets = array();
        foreach ($this->_targetMap as $map) {
            if (@preg_match('/^'.$map['pattern'].'$/', $filename)) {
                $targets[$map['target']] = (int) $map['backtrace'];
                if ((int) $map['stop_on_match']) {
                    break;
                }
            }
        }
        return $targets;
    }

    /**
     * The maximun of days to keep log messages in the database table.
     *
     * @return string Days to keep
     */
    public function getMaxDaysToKeep()
    {
        return $this->getLoggerConfig(self::XML_PATH_MAX_DAYS);
    }

    /**
     * Add priority filte to writer instance
     *
     * @param Zend_Log_Writer_Abstract $writer     Writer Instance
     * @param null|string              $configPath Config Path
     */
    public function addPriorityFilter(Zend_Log_Writer_Abstract $writer, $configPath = null)
    {
        $priority = null;
        if ($configPath) {
            $priority = $this->getLoggerConfig($configPath);
            if ($priority == 'default') {
                $priority = null;
            }
        }
        if ( ! $configPath || ! strlen($priority)) {
            $priority = $this->getLoggerConfig(self::XML_PATH_PRIORITY);
        }
        if ($priority !== null && $priority != Zend_Log::WARN) {
            $writer->addFilter(new Zend_Log_Filter_Priority((int) $priority));
        }
    }

    /**
     * Add useful metadata to the event
     *
     * @param array       &$event          Event Data
     * @param null|string $notAvailable    Not available
     * @param bool        $enableBacktrace Flag for Backtrace
     */
    public function addEventMetadata(&$event, $notAvailable = null, $enableBacktrace = false)
    {
        $event['file'] = $notAvailable;
        $event['line'] = $notAvailable;
        $event['backtrace'] = $notAvailable;
        $event['store_code'] = Mage::app()->getStore()->getCode();

        // Add request time
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $event['time_elapsed'] = sprintf('%f', microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']);
        } else {
            $event['time_elapsed'] = sprintf('%d', time() - $_SERVER['REQUEST_TIME']);
        }

        // Find file and line where message originated from and optionally get backtrace lines
        $basePath = dirname(Mage::getBaseDir()).'/'; // 1 level up in case deployed with symlinks from parent directory
        $nextIsFirst = false;                        // Skip backtrace frames until we reach Mage::log(Exception)
        $recordBacktrace = false;
        $maxBacktraceLines = $enableBacktrace ? (int) $this->getLoggerConfig('general/max_backtrace_lines') : 0;
        $backtraceFrames = array();
        if (version_compare(PHP_VERSION, '5.3.6') < 0 ) {
            $debugBacktrace = debug_backtrace(false);
        } elseif (version_compare(PHP_VERSION, '5.4.0') < 0) {
            $debugBacktrace = debug_backtrace(
                $maxBacktraceLines > 0 ? 0 : DEBUG_BACKTRACE_IGNORE_ARGS
            );
        } else {
            $debugBacktrace = debug_backtrace(
                $maxBacktraceLines > 0 ? 0 : DEBUG_BACKTRACE_IGNORE_ARGS,
                $maxBacktraceLines + 10
            );
        }

        foreach ($debugBacktrace as $frame) {
            if (($nextIsFirst && $frame['function'] == 'logException')
                || (
                    isset($frame['type'])
                    && $frame['type'] == '::'
                    && $frame['class'] == 'Mage'
                    && substr($frame['function'], 0, 3) == 'log'
                )
            ) {
                if (isset($frame['file']) && isset($frame['line'])) {
                    $event['file'] = str_replace($basePath, '', $frame['file']);
                    $event['line'] = $frame['line'];
                    if ($maxBacktraceLines) {
                        $backtraceFrames = array();
                    } elseif ($nextIsFirst) {
                        break;
                    } else {
                        continue;
                    }
                }

                // Don't record backtrace for Mage::logException
                if ($frame['function'] == 'logException') {
                    break;
                }

                $nextIsFirst = true;
                $recordBacktrace = true;
                continue;
            }

            if ($recordBacktrace) {
                if (count($backtraceFrames) >= $maxBacktraceLines) {
                    break;
                }
                $backtraceFrames[] = $frame;
                continue;
            }
        }

        if ($backtraceFrames) {
            $backtrace = array();
            foreach ($backtraceFrames as $index => $frame) {
                // Set file
                if (empty($frame['file'])) {
                    $frame['file'] = 'unknown_file';
                } else {
                    $frame['file'] = str_replace($basePath, '', $frame['file']);
                }

                // Set line
                if (empty($frame['line'])) {
                    $frame['line'] = 0;
                }

                $function = (isset($frame['class']) ? "{$frame['class']}{$frame['type']}":'').$frame['function'];
                $args = array();
                if (isset($frame['args'])) {
                    foreach ($frame['args'] as $value) {
                        $args[] = (is_object($value)
                            ? get_class($value)
                            : ( is_array($value)
                                ? 'array('.count($value).')'
                                : ( is_string($value)
                                    ? "'".(strlen($value) > 28 ? "'".substr($value, 0, 25)."...'" : $value)."'"
                                    : gettype($value)."($value)"
                                )
                            )
                        );
                    }
                }

                $args = implode(', ', $args);
                $backtrace[] = "#{$index} {$frame['file']}:{$frame['line']} $function($args)";
            }

            $event['backtrace'] = implode("\n", $backtrace);
        }

        foreach (array('REQUEST_METHOD', 'REQUEST_URI', 'HTTP_USER_AGENT') as $key) {
            if (!empty($_SERVER[$key])) {
                $event[$key] = $_SERVER[$key];
            } else {
                $event[$key] = $notAvailable;
            }
        }

        if ($event['REQUEST_METHOD'] == $notAvailable) {
            $event['REQUEST_METHOD'] = php_sapi_name();
        }
        if ($event['REQUEST_URI'] == $notAvailable && isset($_SERVER['PHP_SELF'])) {
            $event['REQUEST_URI'] = $_SERVER['PHP_SELF'];
        }

        // Fetch request data
        $requestData = array();
        if (!empty($_GET)) {
            $requestData[] = '  GET|'.substr(@json_encode($_GET), 0, 1000);
        }
        if (!empty($_POST)) {
            $requestData[] = '  POST|'.substr(@json_encode($_POST), 0, 1000);
        }
        if (!empty($_FILES)) {
            $requestData[] = '  FILES|'.substr(@json_encode($_FILES), 0, 1000);
        }
        if (Mage::registry('raw_post_data')) {
            $requestData[] = '  RAWPOST|'.substr(Mage::registry('raw_post_data'), 0, 1000);
        }
        $event['REQUEST_DATA'] = $requestData ? implode("\n", $requestData) : $notAvailable;


        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $event['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $event['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
        } else {
            $event['REMOTE_ADDR'] = $notAvailable;
        }

        // Add hostname to log message ...
        if (gethostname() !== false) {
            $event['HOSTNAME'] = gethostname();
        } else {
            $event['HOSTNAME'] = 'Could not determine hostname !';
        }
    }
}
