<?php

declare(strict_types=1);

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
    $pinbaWorkers[$i] = new PinbaWorker(
        (string)$workerConfig['host'],
        (int)$workerConfig['port'],
        (string)$workerConfig['clickhouseUrl'],
        (string)$workerConfig['clickhouseTable'],
        (int)$workerConfig['timer'],
        (array)($workerConfig['exclude'] ?? [])
    );
}

Worker::runAll();

class PinbaWorker {
    // ~2x headroom below the 200M systemd MemoryLimit
    private const MAX_BUFFER_BYTES = 64 * 1024 * 1024;

    private const EXCLUDABLE_FIELDS = ['hostname', 'server_name', 'script_name', 'schema'];

    private Worker $worker;
    private Request $request;
    private string $rows = '';
    /** @var array<string, string[]> field => patterns */
    private array $exclude = [];

    public function __construct(
        string $host,
        int $port,
        private readonly string $clickhouseUrl,
        private readonly string $clickhouseTable,
        private readonly int $timer,
        array $exclude = [],
    ) {
        foreach ($exclude as $field => $patterns) {
            if (!in_array($field, self::EXCLUDABLE_FIELDS, true)) {
                fwrite(STDERR, "pinba-server: unknown exclude field '$field' (supported: " . implode(', ', self::EXCLUDABLE_FIELDS) . ")\n");
                exit(1);
            }
            foreach ((array)$patterns as $pattern) {
                $pattern = (string)$pattern;
                if ($pattern === '') {
                    continue;
                }
                // /.../ means a regular expression, anything else is an fnmatch mask like "mail.*"
                if (strlen($pattern) > 1 && $pattern[0] === '/' && @preg_match($pattern, '') === false) {
                    fwrite(STDERR, "pinba-server: invalid exclude regex $pattern for '$field'\n");
                    exit(1);
                }
                $this->exclude[$field][] = $pattern;
            }
        }

        $this->worker = new Worker("udp://$host:$port");
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onMessage = [$this, 'onMessage'];
    }

    private function isExcluded(): bool {
        foreach ($this->exclude as $field => $patterns) {
            $value = match ($field) {
                'hostname' => $this->request->getHostname(),
                'server_name' => $this->request->getServerName(),
                'script_name' => $this->request->getScriptName(),
                'schema' => $this->request->getSchema(),
            };
            foreach ($patterns as $pattern) {
                $matched = $pattern[0] === '/' && strlen($pattern) > 1
                    ? (bool)preg_match($pattern, $value)
                    : fnmatch($pattern, $value);
                if ($matched) {
                    return true;
                }
            }
        }

        return false;
    }

    public function onWorkerStart(): void {
        $this->request = new Request();

        Timer::add($this->timer, [$this, 'flush']);
    }

    public function flush(): void {
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
            error_log("pinba-server: insert into {$this->clickhouseTable} failed (HTTP $status): " . trim((string)$r));
            if ($status >= 400 && $status < 500) {
                // the server rejected the payload itself — retrying the same batch can never succeed
                error_log("pinba-server: dropping rejected batch (" . strlen($this->rows) . " bytes) for {$this->clickhouseTable}");
                $this->rows = '';
            }
            // otherwise keep the buffer and retry on the next tick
            return;
        }

        $this->rows = '';
    }

    public function onMessage($connection, string $data): void
    {
        $this->request->clear();
        $this->request->mergeFromString($data);

        if ($this->exclude && $this->isExcluded()) {
            return;
        }

        //echo $data . "\n";
        //$json = $this->request->serializeToJsonString();
        //echo "{$this->clickhouseTable}: $json\n\n";
        //$data = json_decode($json);

        $row = [
            'hostname' => $this->request->getHostname(),
            'server_name' => $this->request->getServerName(),
            'script_name' => $this->request->getScriptName(),
            'doc_size' => $this->request->getDocumentSize() & 0xFFFFFFFF,
            'mem_peak_usage' => $this->request->getMemoryPeak() & 0xFFFFFFFF,
            'req_time' => $this->request->getRequestTime(),
            'ru_utime' => $this->request->getRuUtime(),
            'ru_stime' => $this->request->getRuStime(),
            'status' => $this->request->getStatus() & 0xFFFFFFFF,
            'memory_footprint' => $this->request->getMemoryFootprint() & 0xFFFFFFFF,
            'schema' => $this->request->getSchema(),
            'tags.name' => [],
            'tags.value' => [],
            'timers.value' => [],
            'timers.hit_count' => [],
            'timers.tag_name' => [],
            'timers.tag_value' => [],
            'req_count' => ($this->request->getRequestCount() & 0xFFFFFFFF) ?: 1,
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
                $row['timers.hit_count'][]= $timerHitCount & 0xFFFFFFFF;

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
