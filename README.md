##### Requirements
- php >= 8.4 (with `pcntl`, `posix` and `sockets` extensions)
- composer
- clickhouse

##### Installation

- `cd /opt`
- `git clone https://github.com/pinba-server/pinba-server.git`
- `cd pinba-server`
- `composer install`
- `clickhouse-client -n < clickhouse/pinba.requests.sql`

##### Usage

- `php workerman_clickhouse.php start -d`
- `php workerman_clickhouse.php stop`

The list of UDP listeners and their ClickHouse targets is in `config.json`
(the path can be overridden with the `PINBA_CONFIG` environment variable).

A worker can drop unwanted requests with optional `include` (allowlist) and
`exclude` blocks. When `include` is non-empty, only requests matching one of
its patterns per field pass; `exclude` then drops matches. An empty list is a
no-op. Patterns are fnmatch-style masks (`mail.*`) or, when wrapped in
slashes, regular expressions; supported fields are `hostname`, `server_name`,
`script_name` and `schema`:

```json
"include": {
    "server_name": ["*.example.com", "example.com"]
},
"exclude": {
    "server_name": ["mail.*", "/^dev-/"],
    "script_name": ["/health.php"]
}
```

Optional `lowercase` and `rewrite` blocks normalize field values before they
are filtered and stored (in that order: lowercase, then rewrite, then
exclude). `lowercase` lists fields to fold to lower case (ASCII); each
`rewrite` rule is a `["/regex/", "replacement"]` pair applied in order —
e.g. stripping a leading `www.` from server names:

```json
"lowercase": ["server_name"],
"rewrite": {
    "server_name": [["/^www\\./", ""]]
}
```

To verify the installation, send a test packet and check the table:

- `php tools/send_test_packet.php 127.0.0.1 30002`

##### Systemd autostart script
- `sudo systemd/install.sh` — installs and starts `pinba-server.service`
- `sudo systemd/install.sh pinba-server-loki` — the same for the Loki variant
- `sudo systemd/uninstall.sh` — stops and removes the unit

##### Docker

A ready-to-use stack (pinba-server + ClickHouse + Grafana with the ClickHouse datasource plugin):

- `docker compose -f docker/docker-compose.yml up -d --build`
- `php tools/send_test_packet.php 127.0.0.1 30002` — send a test packet
- Grafana: http://localhost:3000 (admin/admin), import dashboard [#10011](https://grafana.com/dashboards/10011)

The pinba listeners are published on UDP ports 30002 (php) and 30003 (nginx).

##### Stats for 24 hours with about 10 RPS on php-fpm and 450 RPS on nginx:

|table|rows|size, Mb|description|
|---|---|---|---|
|requests|1kk|26|raw data|
|report_by_all|56k|2|aggregated data by minutes|
|nginx_requests|45kk|0|raw data, engine=null|
|nginx_report_by_all|300k|9|aggregated data by minutes|

##### Info
- publications: [reddit(en)](https://www.reddit.com/r/PHP/comments/bigszu/statistics_and_monitoring_of_php_scripts_in_real/), [habr(ru)](https://habr.com/ru/post/444610/)
- to monitor nginx as well, build [ngx_http_pinba_module](https://github.com/tony2001/ngx_http_pinba_module) — it makes nginx send pinba packets for every request (the `pinba.nginx_requests` worker on port 30003 stores them).

##### Grafana
dashboard [#10011](https://grafana.com/dashboards/10011)

![grafana_dashboard.png](https://raw.githubusercontent.com/pinba-server/pinba-server/master/grafana_dashboard.png)

##### License
MIT License.

##### See also
- [ClickHouse-Ninja/Proton](https://github.com/ClickHouse-Ninja/Proton) - golang version of pinba-server for clickhouse 
- [olegfedoseev/pinba-influxdb](https://github.com/olegfedoseev/pinba-influxdb) - golang version of pinba-server for influxdb
- [nginxhouse](https://github.com/nginxhouse/nginxhouse) - nginx logs visualizer
