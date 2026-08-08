<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Pinba/Request.php';
require_once __DIR__ . '/GPBMetadata/Pinba.php';

use Workerman\Worker;
use Workerman\Timer;
use Pinba\Request;

if (!ini_get('date.timezone')) {
    ini_set('date.timezone', date_default_timezone_get());//exec('date +"%Z"');
}

$config = [
    'host:port' => '0.0.0.0:30002',
    'lokiUrl' => 'http://127.0.0.1:3100/api/prom/push',
    'db.table' => 'pinba.requests',
    'timer' => 60,
];

// pecl install event
//Worker::$eventLoopClass = '\Workerman\Events\Event';

$tcp_worker = new Worker("udp://{$config['host:port']}");

$request = null;
$streams = [];

$tcp_worker->onWorkerStart = function () use (&$request, &$streams, &$config){
    $request = new Request();
    Timer::add($config['timer'],
        function() use (&$streams, &$config)
        {
            if (!$streams) {
                return;
            }

            $post = json_encode(['streams' => $streams], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            //{"streams": [{"labels": "{foo=\"bar\"}","entries": [{ "ts": "2018-12-18T08:28:06.801064-04:00", "line": "baz" }]}]}
            $r = @file_get_contents($config['lokiUrl'], false,
                stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => $post, 'ignore_errors' => true, 'timeout' => 10]]));

            $status = 0;
            if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                $status = (int)$m[1];
            }

            if ($r === false || $status < 200 || $status >= 300) {
                // keep the buffer and retry on the next tick
                error_log("pinba-server: push to {$config['lokiUrl']} failed (HTTP $status): " . trim((string)$r));
                return;
            }

            $streams = [];
        }
    );
};

$tcp_worker->onMessage = function($connection, $data) use (&$request, &$streams)
{
    $request->clear();
    $request->mergeFromString($data);

    //echo $data . "\n";
    //$json = $request->serializeToJsonString();
    //echo "$json\n\n";
    //$data = json_decode($json);

    $row = [
        'labels' => [
            'hostname' => $request->getHostname(),
            'server_name' => $request->getServerName(),
            'script_name' => $request->getScriptName(),
            'status' => $request->getStatus(),
            'schema' => $request->getSchema(),
        ],
        'entries' => [
            'doc_size' => $request->getDocumentSize(),
            'mem_peak_usage' => $request->getMemoryPeak(),
            'req_time' => $request->getRequestTime(),
            'ru_utime' => $request->getRuUtime(),
            'ru_stime' => $request->getRuStime(),
            'memory_footprint' => $request->getMemoryFootprint(),
            'req_count' => $request->getRequestCount() ?: 1,
        ],
    ];

    $dictionary = $request->getDictionary();
    $tagNames = $request->getTagName();
    $tagValue = $request->getTagValue();

    foreach ($tagNames as $tagId => $tagName) {
        $row['labels'][$dictionary[$tagName]] = $dictionary[$tagValue[$tagId]];
    }

    $timerHitCounts = $request->getTimerHitCount();
    $timerValue = $request->getTimerValue();
    $timerTagCount = $request->getTimerTagCount();
    $timerTagName = $request->getTimerTagName();
    $timerTagValue = $request->getTimerTagValue();

    if ($timerHitCounts->count()) {
        $row['entries']['timers'] = [
            'value' => [],
            'hit_count' => [],
            'tag_name' => [],
            'tag_value' => [],
        ];

        $timerTagId = 0;
        foreach ($timerHitCounts as $timerId => $timerHitCount) {
            $row['entries']['timers']['value'][]= $timerValue[$timerId];
            $row['entries']['timers']['hit_count'][]= $timerHitCount;

            $timerTagNames = [];
            $timerTagValues = [];
            for ($i = 0; $i < $timerTagCount[$timerId]; $i++) {
                $timerTagNames[]= $dictionary[$timerTagName[$timerTagId]];
                $timerTagValues[]= $dictionary[$timerTagValue[$timerTagId]];

                $timerTagId++;
            }
            $row['entries']['timers']['tag_name'][]= $timerTagNames;
            $row['entries']['timers']['tag_value'][]= $timerTagValues;
        }
        $row['entries']['timers'] = json_encode($row['entries']['timers'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    //var_export($row);
    $stream = [
        'labels' => [],
        'entries' => [],
    ];


    foreach ($row['labels'] as $name => $value) {
        $value = strtr((string)$value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']);
        $stream['labels'][]= "$name=\"$value\"";
    }
    $stream['labels'] = '{' . join(',', $stream['labels']) . '}';
    $stream['entries'][] = ['ts' => date(DATE_RFC3339), 'line' => json_encode($row['entries'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    if (count($streams) > 500000) {
        error_log("pinba-server: loki stream buffer exceeded 500k entries, dropping buffered streams");
        $streams = [];
    }
    $streams[]= $stream;
};


Worker::runAll();