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
        (array)($workerConfig['exclude'] ?? []),
        (array)($workerConfig['rewrite'] ?? []),
        (array)($workerConfig['lowercase'] ?? []),
        (array)($workerConfig['include'] ?? []),
        (array)($workerConfig['exclude_all_of'] ?? [])
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
    /** @var array<string, string[]> field => patterns; non-empty = only matching requests pass */
    private array $include = [];
    /** @var array<string, string[]> field => patterns */
    private array $exclude = [];
    /** @var array<int, array<string, string[]>> AND-groups: drop when every field of a group matches */
    private array $excludeAllOf = [];
    /** @var array<string, array{string, string}[]> field => [regex, replacement] rules */
    private array $rewrite = [];
    /** @var string[] fields to lowercase (ASCII) before rewrite/exclude */
    private array $lowercase = [];

    public function __construct(
        string $host,
        int $port,
        private readonly string $clickhouseUrl,
        private readonly string $clickhouseTable,
        private readonly int $timer,
        array $exclude = [],
        array $rewrite = [],
        array $lowercase = [],
        array $include = [],
        array $excludeAllOf = [],
    ) {
        $this->include = $this->compilePatterns($include, 'include');
        foreach ($excludeAllOf as $group) {
            if (!is_array($group) || !$group) {
                fwrite(STDERR, "pinba-server: each exclude_all_of entry must be a non-empty {field: pattern} object\n");
                exit(1);
            }
            $compiled = $this->compilePatterns($group, 'exclude_all_of');
            if ($compiled) {
                $this->excludeAllOf[] = $compiled;
            }
        }
        foreach ($lowercase as $field) {
            if (!in_array($field, self::EXCLUDABLE_FIELDS, true)) {
                fwrite(STDERR, "pinba-server: unknown lowercase field '$field' (supported: " . implode(', ', self::EXCLUDABLE_FIELDS) . ")\n");
                exit(1);
            }
            $this->lowercase[] = $field;
        }
        foreach ($rewrite as $field => $rules) {
            if (!in_array($field, self::EXCLUDABLE_FIELDS, true)) {
                fwrite(STDERR, "pinba-server: unknown rewrite field '$field' (supported: " . implode(', ', self::EXCLUDABLE_FIELDS) . ")\n");
                exit(1);
            }
            foreach ((array)$rules as $rule) {
                if (!is_array($rule) || count($rule) !== 2 || !is_string($rule[0]) || !is_string($rule[1])) {
                    fwrite(STDERR, "pinba-server: rewrite rule for '$field' must be a [\"/regex/\", \"replacement\"] pair\n");
                    exit(1);
                }
                if (@preg_match($rule[0], '') === false) {
                    fwrite(STDERR, "pinba-server: invalid rewrite regex {$rule[0]} for '$field'\n");
                    exit(1);
                }
                $this->rewrite[$field][] = [$rule[0], $rule[1]];
            }
        }
        $this->exclude = $this->compilePatterns($exclude, 'exclude');

        $this->worker = new Worker("udp://$host:$port");
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onMessage = [$this, 'onMessage'];
    }

    /** @return array<string, string[]> validated field => patterns, empty lists dropped */
    private function compilePatterns(array $config, string $kind): array {
        $compiled = [];
        foreach ($config as $field => $patterns) {
            if (!in_array($field, self::EXCLUDABLE_FIELDS, true)) {
                fwrite(STDERR, "pinba-server: unknown $kind field '$field' (supported: " . implode(', ', self::EXCLUDABLE_FIELDS) . ")\n");
                exit(1);
            }
            foreach ((array)$patterns as $pattern) {
                $pattern = (string)$pattern;
                if ($pattern === '') {
                    continue;
                }
                if ($this->isRegex($pattern) && @preg_match($pattern, '') === false) {
                    fwrite(STDERR, "pinba-server: invalid $kind regex $pattern for '$field'\n");
                    exit(1);
                }
                $compiled[$field][] = $pattern;
            }
        }

        return $compiled;
    }

    // "/.../" (with optional trailing PCRE modifiers) is a regular expression;
    // anything else — including paths like "/health.php" or "/api/health" —
    // is an fnmatch mask
    private function isRegex(string $pattern): bool {
        return strlen($pattern) > 2 && $pattern[0] === '/'
            && preg_match('#/[imsxuUXJ]*$#', $pattern) === 1;
    }

    private function matches(string $pattern, string $value): bool {
        return $this->isRegex($pattern)
            ? (bool)preg_match($pattern, $value)
            : fnmatch($pattern, $value);
    }

    private function getField(string $field): string {
        return match ($field) {
            'hostname' => $this->request->getHostname(),
            'server_name' => $this->request->getServerName(),
            'script_name' => $this->request->getScriptName(),
            'schema' => $this->request->getSchema(),
        };
    }

    private function setField(string $field, string $value): void {
        match ($field) {
            'hostname' => $this->request->setHostname($value),
            'server_name' => $this->request->setServerName($value),
            'script_name' => $this->request->setScriptName($value),
            'schema' => $this->request->setSchema($value),
        };
    }

    private function normalize(): void {
        foreach ($this->lowercase as $field) {
            $value = $this->getField($field);
            $lowered = strtolower($value);
            if ($lowered !== $value) {
                $this->setField($field, $lowered);
            }
        }

        foreach ($this->rewrite as $field => $rules) {
            $value = $this->getField($field);
            $rewritten = $value;
            foreach ($rules as [$pattern, $replacement]) {
                $rewritten = preg_replace($pattern, $replacement, $rewritten) ?? $rewritten;
            }
            if ($rewritten !== $value) {
                $this->setField($field, $rewritten);
            }
        }
    }

    private function isIncluded(): bool {
        // every allowlisted field must match at least one of its patterns
        foreach ($this->include as $field => $patterns) {
            $value = $this->getField($field);
            $matched = false;
            foreach ($patterns as $pattern) {
                if ($this->matches($pattern, $value)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function isExcluded(): bool {
        foreach ($this->exclude as $field => $patterns) {
            $value = $this->getField($field);
            foreach ($patterns as $pattern) {
                if ($this->matches($pattern, $value)) {
                    return true;
                }
            }
        }

        foreach ($this->excludeAllOf as $group) {
            foreach ($group as $field => $patterns) {
                $value = $this->getField($field);
                $matched = false;
                foreach ($patterns as $pattern) {
                    if ($this->matches($pattern, $value)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue 2; // this group doesn't apply, try the next one
                }
            }

            return true; // every field of the group matched
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

        if ($this->lowercase || $this->rewrite) {
            $this->normalize();
        }
        if ($this->include && !$this->isIncluded()) {
            return;
        }
        if (($this->exclude || $this->excludeAllOf) && $this->isExcluded()) {
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
