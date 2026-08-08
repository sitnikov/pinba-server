<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Pinba/Request.php';
require_once __DIR__ . '/GPBMetadata/Pinba.php';

use Workerman\Worker;
use Workerman\Timer;
use Pinba\Request;

if (!ini_get('date.timezone')) {
    ini_set('date.timezone', date_default_timezone_get()); //fix for workerman trouble with timezone
}

$configFile = getenv('PINBA_CONFIG') ?: __DIR__ . '/config.json';
$config = json_decode((string)@file_get_contents($configFile), true);

if (!is_array($config) || empty($config['workers']) || !is_array($config['workers'])) {
    fwrite(STDERR, "pinba-server: cannot read worker list from $configFile\n");
    exit(1);
}

$pinbaWorkers = [];

foreach ($config['workers'] as $i => $workerConfig) {
    $pinbaWorkers[$i] = new PinbaWorker($workerConfig['host'], $workerConfig['port'], $workerConfig['clickhouseUrl'], $workerConfig['clickhouseTable'], $workerConfig['timer']);
}

Worker::runAll();

class PinbaWorker {
    // ~2x headroom below the 200M systemd MemoryLimit
    const MAX_BUFFER_BYTES = 64 * 1024 * 1024;

    public $clickhouseUrl;
    public $clickhouseTable;
    public $timer;
    public $worker;
    public $request;
    public $rows = '';

    public function __construct($host, $port, $clickhouseUrl, $clickhouseTable, $timer)
    {
        $this->worker = new Worker("udp://$host:$port");
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onMessage = [$this, 'onMessage'];

        $this->clickhouseUrl = $clickhouseUrl;
        $this->clickhouseTable = $clickhouseTable;
        $this->timer = $timer;
    }

    public function onWorkerStart() {
        $this->request = new Request();

        Timer::add($this->timer, [$this, 'flush']);
    }

    public function flush() {
        if ($this->rows === '') {
            return;
        }

        $r = @file_get_contents("{$this->clickhouseUrl}&query=INSERT+INTO+{$this->clickhouseTable}+FORMAT+JSONEachRow", false,
            stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: text/plain', 'content' => $this->rows, 'ignore_errors' => true, 'timeout' => 10]]));

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        if ($r === false || $status < 200 || $status >= 300) {
            // keep the buffer and retry on the next tick
            error_log("pinba-server: insert into {$this->clickhouseTable} failed (HTTP $status): " . trim((string)$r));
            return;
        }

        $this->rows = '';
    }

    public function onMessage($connection, $data)
    {
        $this->request->clear();
        $this->request->mergeFromString($data);

        //echo $data . "\n";
        //$json = $this->request->serializeToJsonString();
        //echo "{$this->clickhouseTable}: $json\n\n";
        //$data = json_decode($json);

        $row = [
            'hostname' => $this->request->getHostname(),
            'server_name' => $this->request->getServerName(),
            'script_name' => $this->request->getScriptName(),
            'doc_size' => $this->request->getDocumentSize(),
            'mem_peak_usage' => $this->request->getMemoryPeak(),
            'req_time' => $this->request->getRequestTime(),
            'ru_utime' => $this->request->getRuUtime(),
            'ru_stime' => $this->request->getRuStime(),
            'status' => $this->request->getStatus(),
            'memory_footprint' => $this->request->getMemoryFootprint(),
            'schema' => $this->request->getSchema(),
            'tags.name' => [],
            'tags.value' => [],
            'timers.value' => [],
            'timers.hit_count' => [],
            'timers.tag_name' => [],
            'timers.tag_value' => [],
            'req_count' => $this->request->getRequestCount() ?: 1,
            'timestamp' => date("Y-m-d H:i:s"),
        ];

        $dictionary = $this->request->getDictionary();
        $tagNames = $this->request->getTagName();
        $tagValue = $this->request->getTagValue();

        foreach ($tagNames as $tagId => $tagName) {
            $row['tags.name'][] = $dictionary[$tagName];
            $row['tags.value'][] = $dictionary[$tagValue[$tagId]];
        }

        $timerHitCounts = $this->request->getTimerHitCount();
        $timerValue = $this->request->getTimerValue();
        $timerTagCount = $this->request->getTimerTagCount();
        $timerTagName = $this->request->getTimerTagName();
        $timerTagValue = $this->request->getTimerTagValue();

        if ($timerHitCounts->count()) {
            $timerTagId = 0;
            foreach ($timerHitCounts as $timerId => $timerHitCount) {
                $row['timers.value'][]= $timerValue[$timerId];
                $row['timers.hit_count'][]= $timerHitCount;

                $timerTagNames = [];
                $timerTagValues = [];
                for ($i = 0; $i < $timerTagCount[$timerId]; $i++) {
                    $timerTagNames[]= $dictionary[$timerTagName[$timerTagId]];
                    $timerTagValues[]= $dictionary[$timerTagValue[$timerTagId]];

                    $timerTagId++;
                }
                $row['timers.tag_name'][]= $timerTagNames;
                $row['timers.tag_value'][]= $timerTagValues;
            }
        }

        //var_export($row);
        if (strlen($this->rows) > self::MAX_BUFFER_BYTES) {
            error_log("pinba-server: buffer for {$this->clickhouseTable} exceeded " . self::MAX_BUFFER_BYTES . " bytes, dropping buffered rows");
            $this->rows = '';
        }
        $this->rows .= json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
