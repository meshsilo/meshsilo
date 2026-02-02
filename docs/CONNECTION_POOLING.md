# Connection Pooling

MeshSilo supports external connection pooling for high-traffic deployments.

## Why Connection Pooling?

- Eliminates connection overhead (~5-10ms per request)
- Reduces database server load
- Enables connection reuse across PHP-FPM workers
- Essential for MySQL deployments at scale

## Options

### 1. ProxySQL (Recommended for MySQL)

ProxySQL is a high-performance MySQL proxy that provides connection pooling, query caching, and load balancing.

```yaml
# docker-compose.yml addition
services:
  proxysql:
    image: proxysql/proxysql:latest
    ports:
      - "6033:6033"  # MySQL frontend
      - "6032:6032"  # Admin interface
    volumes:
      - ./proxysql.cnf:/etc/proxysql.cnf
    environment:
      - MYSQL_ROOT_PASSWORD=secret
```

```ini
# proxysql.cnf
datadir="/var/lib/proxysql"

admin_variables=
{
    admin_credentials="admin:admin"
    mysql_ifaces="0.0.0.0:6032"
}

mysql_variables=
{
    interfaces="0.0.0.0:6033"
    default_query_timeout=300000
    max_connections=2048
    server_version="8.0.0"
    monitor_enabled=true
}

mysql_servers =
(
    {
        address = "mysql"
        port = 3306
        hostgroup = 0
        max_connections = 100
    }
)

mysql_users =
(
    {
        username = "meshsilo"
        password = "secret"
        default_hostgroup = 0
        max_connections = 100
    }
)
```

Update your MeshSilo config to connect to ProxySQL:

```php
// config.local.php
define('DB_HOST', 'proxysql');
define('DB_PORT', 6033);
```

### 2. PgBouncer (For PostgreSQL)

If using PostgreSQL, PgBouncer provides lightweight connection pooling.

```yaml
# docker-compose.yml addition
services:
  pgbouncer:
    image: edoburu/pgbouncer:latest
    environment:
      - DATABASE_URL=postgres://user:pass@postgres:5432/meshsilo
      - POOL_MODE=transaction
      - MAX_CLIENT_CONN=1000
      - DEFAULT_POOL_SIZE=20
    ports:
      - "6432:6432"
```

### 3. PHP Persistent Connections

MeshSilo already uses PHP persistent connections (`PDO::ATTR_PERSISTENT`). This provides basic connection reuse within a single PHP-FPM worker.

For most deployments, this is sufficient. External pooling is recommended when:
- Running 50+ PHP-FPM workers
- Database connection time exceeds 5ms
- Experiencing "too many connections" errors

## Monitoring

Check connection usage:

```sql
-- MySQL
SHOW STATUS LIKE 'Threads_connected';
SHOW PROCESSLIST;

-- ProxySQL
SELECT * FROM stats_mysql_connection_pool;
SELECT * FROM stats_mysql_query_digest ORDER BY sum_time DESC LIMIT 10;
```

## Performance Impact

| Scenario | Without Pooling | With Pooling |
|----------|-----------------|--------------|
| Connection time | 5-10ms | <1ms |
| Max connections | 100-200 | 1000+ |
| Connection errors | Common at scale | Rare |

## Recommendations

| Deployment Size | Recommendation |
|-----------------|----------------|
| Small (<10 users) | PHP persistent connections (default) |
| Medium (10-100 users) | PHP persistent connections |
| Large (100+ users) | ProxySQL/PgBouncer |
| High traffic (1000+ req/s) | ProxySQL with read replicas |
