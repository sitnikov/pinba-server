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

##### Normalization and filtering

Each worker can normalize and filter incoming requests before they are
buffered. The pipeline runs in a fixed order, so the filters always see the
already-normalized values:

```
lowercase → rewrite → include → exclude → buffer
```

All blocks are optional and apply per field; supported fields are `hostname`,
`server_name`, `script_name` and `schema`. See
[config.example.json](config.example.json) for a complete annotated example.

**Pattern syntax** — identical everywhere a pattern is accepted (`include`
and `exclude`):

- a plain string is an fnmatch-style mask: `mail.*`, `*.example.com`,
  `backend-??`; a string without wildcards must match exactly
- a string wrapped in slashes (optionally with trailing PCRE modifiers, e.g.
  `/…/i`) is a regular expression: `/^dev-/`, `/(^|\.)example\.(net|co\.uk)$/`
- a leading slash alone does not make a regex: script paths such as
  `/health.php` or `/api/health` are ordinary masks
- an unknown field, a malformed rule or an invalid regex aborts startup with
  a clear error instead of silently filtering nothing
- an empty pattern list is a no-op

The blocks:

- `lowercase` — list of fields to fold to lower case (ASCII), e.g.
  `["server_name"]`.
- `rewrite` — per-field list of `["/regex/", "replacement"]` pairs applied in
  order (rewrite always uses regular expressions — a replacement needs
  capture semantics, so masks are not supported here). An empty replacement
  deletes the match, e.g. `[["/^www\\./", ""]]` folds `www.example.com` into
  `example.com`.
- `include` — allowlist: when non-empty for a field, only requests matching
  at least one of its patterns pass; everything else is dropped. Keep it
  empty (or absent) to accept all domains.
- `exclude` — denylist: a request matching any pattern of any listed field is
  dropped.

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
