<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Pinba/Request.php';
require_once __DIR__ . '/GPBMetadata/Pinba.php';

use Pinba\Request;
use Swoole\Server;


$config = [
    'host' => '0.0.0.0',
    'port' => 30002,
    'clickhouseUrl' => 'http://127.0.0.1:8123?user=default',
    'db.table' => 'pinba.requests',
    'timer' => 60,
];

$server = new Server($config['host'], $config['port'], SWOOLE_BASE, SWOOLE_SOCK_UDP);

$request = null;
$jsonRows = '';
$request = new Request();

$server->on('WorkerStart', function (Server $server) use ($request, $config, &$jsonRows)
{
    $server->tick(1000, function () use ($request, $config, &$jsonRows) {
        if ($jsonRows === '') {
            return;
        }

        $r = @file_get_contents("{$config['clickhouseUrl']}&query=INSERT%20INTO%20{$config['db.table']}%20FORMAT%20JSONEachRow", false,
            stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-type: text/plain', 'content' => $jsonRows, 'ignore_errors' => true, 'timeout' => 10]]));

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        if ($r === false || $status < 200 || $status >= 300) {
            // keep the buffer and retry on the next tick
            error_log("pinba-server: insert into {$config['db.table']} failed (HTTP $status): " . trim((string)$r));
            return;
        }

        $jsonRows = '';
    });
});

$server->on('Packet', function (Server $server, $data, $addr) use (&$request, &$jsonRows, $config)
{
    $request->clear();
    $request->mergeFromString($data);

    //echo $data . "\n";
    //$json = $request->serializeToJsonString();
    //echo "$json\n\n";
    //$data = json_decode($json);

    $row = [
        'hostname' => $request->getHostname(),
        'server_name' => $request->getServerName(),
        'script_name' => $request->getScriptName(),
        'doc_size' => $request->getDocumentSize(),
        'mem_peak_usage' => $request->getMemoryPeak(),
        'req_time' => $request->getRequestTime(),
        'ru_utime' => $request->getRuUtime(),
        'ru_stime' => $request->getRuStime(),
        'status' => $request->getStatus(),
        'memory_footprint' => $request->getMemoryFootprint(),
        'schema' => $request->getSchema(),
        'tags.name' => [],
        'tags.value' => [],
        'timers.value' => [],
        'timers.hit_count' => [],
        'timers.tag_name' => [],
        'timers.tag_value' => [],
        'req_count' => $request->getRequestCount() ?: 1,
        'timestamp' => date("Y-m-d H:i:s"),
    ];

    $dictionary = $request->getDictionary();
    $tagNames = $request->getTagName();
    $tagValue = $request->getTagValue();

    foreach ($tagNames as $tagId => $tagName) {
        $row['tags.name'][] = $dictionary[$tagName];
        $row['tags.value'][] = $dictionary[$tagValue[$tagId]];
    }

    $timerHitCounts = $request->getTimerHitCount();
    $timerValue = $request->getTimerValue();
    $timerTagCount = $request->getTimerTagCount();
    $timerTagName = $request->getTimerTagName();
    $timerTagValue = $request->getTimerTagValue();

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
    if (strlen($jsonRows) > 64 * 1024 * 1024) {
        error_log("pinba-server: buffer for {$config['db.table']} exceeded 64MB, dropping buffered rows");
        $jsonRows = '';
    }
    $jsonRows .= json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    //var_export($r);
});

$server->start();
