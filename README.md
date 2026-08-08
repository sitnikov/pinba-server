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

To verify the installation, send a test packet and check the table:

- `php tools/send_test_packet.php 127.0.0.1 30002`

##### Systemd autostart script
- `sudo systemd/install.sh` — installs and starts `pinba-server.service`
- `sudo systemd/install.sh pinba-server-loki` — the same for the Loki variant
- `sudo systemd/uninstall.sh` — stops and removes the unit

##### Stats for 24 hours with about 10 RPS on php-fpm and 450 RPS on nginx:

|table|rows|size, Mb|description|
|---|---|---|---|
|requests|1kk|26|raw data|
|report_by_all|56k|2|aggregated data by minutes|
|nginx_requests|45kk|0|raw data, engine=null|
|nginx_report_by_all|300k|9|aggregated data by minutes|

##### Info
- publications: [reddit(en)](https://www.reddit.com/r/PHP/comments/bigszu/statistics_and_monitoring_of_php_scripts_in_real/), [habr(ru)](https://habr.com/ru/post/444610/)
- the installation of ClickHouse, pinba-server, pinba module for php and nginx on [Ubuntu 18.04 LTS](https://github.com/pinba-server/pinba-server/blob/master/docker/ubuntu18.04/Dockerfile) and [Centos 7](https://github.com/pinba-server/pinba-server/blob/master/docker/centos7/Dockerfile).

##### Grafana
dashboard [#10011](https://grafana.com/dashboards/10011)

![grafana_dashboard.png](https://raw.githubusercontent.com/pinba-server/pinba-server/master/grafana_dashboard.png)

##### License
MIT License.

##### See also
- [ClickHouse-Ninja/Proton](https://github.com/ClickHouse-Ninja/Proton) - golang version of pinba-server for clickhouse 
- [olegfedoseev/pinba-influxdb](https://github.com/olegfedoseev/pinba-influxdb) - golang version of pinba-server for influxdb
- [nginxhouse](https://github.com/nginxhouse/nginxhouse) - nginx logs visualizer
