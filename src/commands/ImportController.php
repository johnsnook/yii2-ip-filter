<?php

/**
 * This file is part of the Yii2 extension module, yii2-visitor
 *
 * @author John Snook
 * @date Aug 4, 2018
 * @license https://snooky.biz/site/license
 * @copyright 2018 John Snook Consulting
 */

namespace johnsnook\visitors\commands;

use johnsnook\visitors\helpers\ProgressBar;
use johnsnook\visitors\models\Visitor;
use johnsnook\visitors\models\Visits;
use johnsnook\visitors\models\VisitorAgent;
use Kassner\LogParser\LogParser;
use yii\helpers\Console;

/**
 * Import apache2 access log into the database for a quick start
 */
class ImportController extends \yii\console\Controller {

    /**
     *
     * @param type $list
     * @return boolean
     */
    public function countAndConfirm($list) {
        $totes = 0;
        foreach (($list) as $filename) {
            if (!file_exists($filename)) {
                die("File not found: $filename\n");
            }
            echo "$filename size " . filesize($filename) . "\n";
            $arr = file($filename, FILE_IGNORE_NEW_LINES);
            $count = count($arr);
            echo "Lines $count\n";
            $totes += $count;
        }
        echo "Total lines $totes\n";
        if (Console::confirm("Do you want to import $totes records?\n")) {
            return $totes;
        } else {
            return false;
        }
    }

    /**
     * Parses Apache2 log files into the database to kickstart your data
     * @example
     * 24.99.237.149 - - [30/Jun/2018:12:31:51 -0400] "GET /minecraft/get-log?offset=188 HTTP/1.1" 200 26 "https://snooky.biz/minecraft" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.99 Safari/537.36"
     *
     * @param string $logDir
     * @param string $list Comma separated list of log files to parse
     */
    function actionLogs($logDir = '/etc/httpd/logs', $list = null) {
        Console::clearScreen();
        if (!is_dir($logDir)) {
            echo "Invalid log directory";
        }

        if (!empty($list)) {
            $files = explode(',', $list);
            foreach ($files as &$file) {
                $file = "$logDir/$file";
            }
        } else {
            $files = glob("$logDir/access*");
        }
        $total = $this->countAndConfirm($files);
        if ($total === false) {
            die("User cancled\n");
        }
        Console::clearScreen();

        $transaction = \Yii::$app->db->beginTransaction();

        $screen = Console::getScreenSize();
        $fileProgress = new ProgressBar([
            'label' => $logDir . ': ',
            'lineNumber' => $screen[1] - 2,
            'progressColor' => [Console::FG_BLUE, Console::BOLD],
        ]);
        $fileProgress->start($total);
        $i = 0;
        foreach ($files as $file) {
            $parser = new LogParser();
            $parser->setFormat('%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"');
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $fileProgress->label = $file;
            $j = 1;

            foreach ($lines as $line) {
                try {
                    $fileProgress->update($i++);
                    $this->showMsg(Console::ansiFormat("Line $j", [Console::FG_GREEN, Console::BOLD]));
                    $j++;
                    $entry = $parser->parse($line);
                    if (empty($ip = $entry->host)) {
                        continue;
                    }
                    if (strstr($entry->request, 'GET /assets') || strstr($entry->request, 'GET /css') || strstr($entry->request, 'GET /favicon.png')) {
                        continue;
                    }
                    $request = split(' ', $entry->request);
                    $request = isset($request[1]) ? [1] : $entry->request;

                    /**
                     * Try to find existing visitor record, and creates a new one if not found
                     * Also logs this visit in the access_log
                     */
                    $visitor = Visitor::findOne($ip);
                    if (is_null($visitor)) {
                        $visitor = new Visitor(['ip' => $ip]);
                        if (!$visitor->save()) {
                            die(json_encode($visitor->errors));
                        }
                        $visitor->refresh();
                    }

                    /*
                     * don't put in log table
                     */
                    if (array_key_exists('whitelist', $visitor->ignorables) && in_array($ip, $visitor->ignorables['whitelist'])) {
                        continue;
                    }
                    $dt = new \DateTime;
                    $log = new Visits([
                        'ip' => $ip,
                        'request' => $request,
                        'referer' => $entry->HeaderReferer !== '-' ? $entry->HeaderReferer : null,
                        'user_agent' => $entry->HeaderUserAgent,
                        'created_at' => $dt->setTimestamp($entry->stamp),
                    ]);
                    $log->save(false);
                    Visitor::incrementCount($ip);
                    VisitorAgent::log($log->user_agent);

                    if ($j % 1000) {
                        $transaction->commit();
                        $transaction = \Yii::$app->db->beginTransaction();
                    }
                } catch (\Exception $e) {

                    $this->showMsg(Console::ansiFormat($this->exception2arr($e), [Console::FG_RED, Console::BOLD]), true);
                    continue;
                }
            }
        }
        $fileProgress->end();
        $transaction->commit();
    }

    /**
     * Displays a message above the progress bar
     *
     * @param string $msg
     * @param boolean $err
     */
    private function showMsg($msg, $err = false) {
        $screen = Console::getScreenSize();
        $lineNo = $err ? 1 : $screen[1] - 5;
        Console::moveCursorTo(1, $lineNo);
        Console::clearLine();
        Console::moveCursorTo(1, $lineNo + 1);
        Console::clearLine();
        Console::moveCursorTo(1, $lineNo);
        echo Console::renderColoredString($msg);
    }

    /**
     * Makes an exception printable
     *
     * @param \Exception $e
     * @return string
     */
    private function exception2arr($e) {
        $out = [];
        $out['message'] = $e->getMessage();
        $out['file'] = $e->getFile();
        $out['line'] = $e->getLine();
        $out['trace'] = $e->getTrace();
        return json_encode($out, 224);
    }

    /**
     * Returns an array with file name and extension
     *
     * @param string $fileName
     * @return array
     */
    private function nameAndExt($fileName) {
        return [substr($fileName, 0, strrpos($fileName, '.')), substr($fileName, strrpos($fileName, '.') + 1)];
    }

    /**
     * Dumps message at top of screen
     *
     * @param int $projProgress
     * @param int $docsProgress
     * @param int $docsRelProgress
     * @param array $config
     */
    private function heads(&$projProgress, &$docsProgress, &$docsRelProgress, $config) {
        Console::saveCursorPosition();
        $screen = Console::getScreenSize();
        Console::moveCursorTo(1, $screen[1] - 5);
        Console::clearLine();
        echo Console::renderColoredString("%W{$config['title']} %n");
        Console::moveCursorTo(1, $screen[1] - 4);
        Console::clearLine();
        echo Console::renderColoredString("%R" . str_repeat('═', $screen[0]) . "%n");
        $projProgress->update($config['projProgress']);
        $docsProgress->update($config['docsProgress']);
        $docsRelProgress->update($config['docsRelProgress']);
        Console::restoreCursorPosition();
    }

}
