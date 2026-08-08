<?php

declare(strict_types=1);

// Send a test pinba packet to a running pinba-server.
// Usage: php tools/send_test_packet.php [host] [port]

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Pinba/Request.php';
require_once __DIR__ . '/../GPBMetadata/Pinba.php';

use Pinba\Request;

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 30002);

$request = new Request();
$request->setHostname(gethostname() ?: 'test-host')
    ->setServerName('test.local')
    ->setScriptName('/pinba-test.php')
    ->setRequestCount(1)
    ->setDocumentSize(1024)
    ->setMemoryPeak(2 * 1024 * 1024)
    ->setRequestTime(0.123)
    ->setRuUtime(0.05)
    ->setRuStime(0.01)
    ->setStatus(200)
    ->setSchema('http');
$request->setDictionary(['group', 'test', 'db', 'mysql']);
$request->setTagName([0]);
$request->setTagValue([1]);
$request->setTimerHitCount([1]);
$request->setTimerValue([0.042]);
$request->setTimerTagCount([1]);
$request->setTimerTagName([2]);
$request->setTimerTagValue([3]);

$bin = $request->serializeToString();

$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock === false || socket_sendto($sock, $bin, strlen($bin), 0, $host, $port) === false) {
    fwrite(STDERR, "failed to send packet to udp://$host:$port\n");
    exit(1);
}
socket_close($sock);

echo "sent test packet (" . strlen($bin) . " bytes) to udp://$host:$port\n";
echo "check your storage in ~1 flush interval, e.g.:\n";
echo "  clickhouse-client -q \"SELECT * FROM pinba.requests WHERE script_name = '/pinba-test.php' ORDER BY timestamp DESC LIMIT 1 FORMAT Vertical\"\n";
