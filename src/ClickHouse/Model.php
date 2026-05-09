<?php

namespace WonderGame\EsUtility\ClickHouse;

use ArrayAccess;
use ClickHouseDB\Client;
use ClickHouseDB\Quote\FormatLine;
use JsonSerializable;

/**
 * Lightweight model base built on smi2/phpclickhouse.
 *
 * Config example:
 * CLICKHOUSE.default = [
 *     'host' => '127.0.0.1',
 *     'port' => 8123,
 *     'username' => 'default',
 *     'password' => '',
 *     'database' => 'default',
 *     'timeout' => 10,
 *     'connect_timeout' => 5,
 * ];
 */
abstract class Model implements ArrayAccess, JsonSerializable
{
    protected $connectionName = 'default';

    protected $tableName = '';

    protected $pk = 'id';

    protected $data = [];

    protected $where = [];

    protected $fields = ['*'];

    protected $orders = [];

    protected $limit = null;

    protected $offset = null;

    protected $lastSql = '';

    protected static $clients = [];

    public function __construct($data = [], $tabname = '', $connectionName = '')
    {
        $tabname && $this->tableName = $tabname;
        $connectionName && $this->connectionName = $connectionName;

        if (!$this->tableName) {
            $this->tableName = $this->_getTable();
        }

        $data && $this->data($data);
    }

    protected function _getTable()
    {
        $name = basename(str_replace('\\', '/', get_called_class()));
        return function_exists('parse_name') ? parse_name($name) : strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    public function getClient(): Client
    {
        if (isset(static::$clients[$this->connectionName])) {
            return static::$clients[$this->connectionName];
        }

        $config = function_exists('config') ? (config('CLICKHOUSE.' . $this->connectionName) ?: []) : [];
        if (!$config && $this->connectionName === 'default' && function_exists('config')) {
            $rootConfig = config('CLICKHOUSE') ?: [];
            $config = isset($rootConfig['host']) ? $rootConfig : [];
        }
        if (!$config) {
            throw new \RuntimeException("ClickHouse config not found: CLICKHOUSE.{$this->connectionName}");
        }

        $client = new Client($config);
        if (!empty($config['database'])) {
            $client->database($config['database']);
        }
        if (isset($config['timeout']) && method_exists($client, 'setTimeout')) {
            $client->setTimeout($config['timeout']);
        }
        if (isset($config['connect_timeout']) && method_exists($client, 'setConnectTimeOut')) {
            $client->setConnectTimeOut($config['connect_timeout']);
        } elseif (isset($config['connect_timeout']) && method_exists($client, 'setConnectTimeout')) {
            $client->setConnectTimeout($config['connect_timeout']);
        }

        return static::$clients[$this->connectionName] = $client;
    }

    public function getConnectionName()
    {
        return $this->connectionName;
    }

    public function tableName()
    {
        return $this->tableName;
    }

    public function getTableName()
    {
        return $this->tableName();
    }

    public function getPk()
    {
        return $this->pk;
    }

    public function data($data = null, $setter = true)
    {
        if ($data === null) {
            return $this->data;
        }

        foreach ($data as $key => $value) {
            $this->setAttr($key, $value, $setter);
        }
        return $this;
    }

    public function setAttr($name, $value, $setter = true)
    {
        $method = 'set' . ucfirst($name) . 'Attr';
        $this->data[$name] = $setter && method_exists($this, $method) ? $this->$method($value, $this->data) : $value;
        return $this;
    }

    public function getAttr($name)
    {
        $value = $this->data[$name] ?? null;
        $method = 'get' . ucfirst($name) . 'Attr';
        return method_exists($this, $method) ? $this->$method($value, $this->data) : $value;
    }

    public function where($whereProps, $whereValue = null, $operator = '=')
    {
        if (is_callable($whereProps)) {
            $whereProps($this);
            return $this;
        }

        if (!is_array($whereProps) && !is_string($whereProps) && $whereValue === null) {
            return $this->where($this->pk, $whereProps);
        }

        if (is_string($whereProps) && $whereValue === null) {
            $this->where[] = ['raw' => $whereProps];
            return $this;
        }

        if (is_array($whereProps) && $whereValue === null) {
            foreach ($whereProps as $field => $value) {
                if (is_array($value) && array_key_exists(1, $value)) {
                    $this->where($field, $value[0], $value[1]);
                } else {
                    $this->where($field, $value);
                }
            }
            return $this;
        }

        $this->where[] = [
            'field' => $whereProps,
            'value' => $whereValue,
            'operator' => strtoupper($operator),
        ];
        return $this;
    }

    public function field($fields)
    {
        $this->fields = is_array($fields) ? $fields : array_filter(array_map('trim', explode(',', $fields)));
        return $this;
    }

    public function order($field, $sort = 'ASC')
    {
        if (is_array($field)) {
            foreach ($field as $key => $value) {
                $this->order($key, $value);
            }
            return $this;
        }

        $this->orders[$field] = strtoupper($sort ?: 'ASC');
        return $this;
    }

    public function limit($offset, $limit = null)
    {
        if ($limit === null) {
            $this->limit = (int)$offset;
            $this->offset = null;
        } else {
            $this->offset = (int)$offset;
            $this->limit = (int)$limit;
        }
        return $this;
    }

    public function page($page = 1, $limit = 20)
    {
        $page = max(1, (int)$page);
        return $this->limit(($page - 1) * (int)$limit, (int)$limit);
    }

    public function save($data = null)
    {
        $data !== null && $this->data($data);
        if (!$this->data) {
            return false;
        }

        $this->insertRows([$this->data], array_keys($this->data));
        return true;
    }

    public function insertAll(array $data)
    {
        if (!$data) {
            return false;
        }

        $columns = array_keys(reset($data));
        $this->insertRows($data, $columns);
        return true;
    }

    public function update($data = null, $where = null)
    {
        $data === null && $data = $this->data;
        if (!$data) {
            return false;
        }

        $where !== null && $this->where($where);
        if (!$this->where) {
            $pkValue = $this->data[$this->pk] ?? ($data[$this->pk] ?? null);
            if ($pkValue !== null) {
                $this->where($this->pk, $pkValue);
                unset($data[$this->pk]);
            }
        }
        $whereSql = $this->buildWhereSql();
        if (!$whereSql) {
            throw new \RuntimeException('ClickHouse update must have where condition');
        }
        if (!$data) {
            return false;
        }

        $sets = [];
        foreach ($data as $field => $value) {
            $sets[] = $this->quoteIdentifier($field) . ' = ' . $this->quoteValue($value);
        }

        return $this->write('ALTER TABLE ' . $this->quoteTable($this->tableName) . ' UPDATE ' . implode(', ', $sets) . ' WHERE ' . $whereSql);
    }

    public function destroy($where = null)
    {
        $where !== null && $this->where($where);

        if (!$this->where && isset($this->data[$this->pk])) {
            $this->where($this->pk, $this->data[$this->pk]);
        }

        $whereSql = $this->buildWhereSql();
        if (!$whereSql) {
            throw new \RuntimeException('ClickHouse delete must have where condition');
        }

        return $this->write('ALTER TABLE ' . $this->quoteTable($this->tableName) . ' DELETE WHERE ' . $whereSql);
    }

    public function get($where = null)
    {
        $where !== null && $this->where($where);
        $rows = $this->limit(1)->selectRows();
        $this->resetQuery();

        if (!$rows) {
            return null;
        }

        $model = new static([], $this->tableName, $this->connectionName);
        $model->data(reset($rows), false);
        return $model;
    }

    public function all($where = null)
    {
        $where !== null && $this->where($where);
        $rows = $this->selectRows();
        $this->resetQuery();

        $result = [];
        foreach ($rows as $row) {
            $model = new static([], $this->tableName, $this->connectionName);
            $result[] = $model->data($row, false);
        }
        return $result;
    }

    public function count($field = '*')
    {
        $sql = 'SELECT count(' . ($field === '*' ? '*' : $this->quoteIdentifier($field)) . ') AS count FROM ' . $this->quoteTable($this->tableName);
        if ($where = $this->buildWhereSql()) {
            $sql .= ' WHERE ' . $where;
        }

        $row = $this->selectOne($sql);
        $this->resetQuery();
        return (int)($row['count'] ?? 0);
    }

    public function val($field)
    {
        $row = $this->field([$field])->limit(1)->selectOne();
        $this->resetQuery();
        return $row[$field] ?? null;
    }

    public function column($field)
    {
        $rows = $this->field([$field])->selectRows();
        $this->resetQuery();
        return array_column($rows, $field);
    }

    public function toArray()
    {
        $data = [];
        foreach ($this->data as $key => $value) {
            $data[$key] = $this->getAttr($key);
        }
        return $data;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function lastSql()
    {
        return $this->lastSql;
    }

    public function _clone()
    {
        return clone $this;
    }

    public function __get($name)
    {
        return $this->getAttr($name);
    }

    public function __set($name, $value)
    {
        $this->setAttr($name, $value);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->getAttr($offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->setAttr($offset, $value);
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    protected function selectRows($sql = null)
    {
        $sql = $sql ?: $this->buildSelectSql();
        $this->lastSql = $sql;
        $this->traceSql($sql);
        return $this->getClient()->select($sql)->rows();
    }

    protected function selectOne($sql = null)
    {
        $sql = $sql ?: $this->buildSelectSql();
        $this->lastSql = $sql;
        $this->traceSql($sql);
        return $this->getClient()->select($sql)->fetchOne();
    }

    protected function write($sql)
    {
        $this->lastSql = $sql;
        $this->traceSql($sql);
        $this->getClient()->write($sql);
        $this->resetQuery();
        return true;
    }

    protected function insertRows(array $rows, array $columns)
    {
        $sql = $this->buildInsertSql($this->tableName, $rows, $columns);
        $this->lastSql = $sql;
        $this->traceSql($sql);
        $this->getClient()->insert($this->tableName, $rows, $columns);
    }

    protected function traceSql($sql)
    {
        if (function_exists('trace')) {
            try {
                trace($sql, 'info', 'sql');
            } catch (\Throwable $throwable) {
                // PHPUnit or non-EasySwoole bootstrap may not initialize Logger.
            }
        }
    }

    protected function buildInsertSql($table, array $rows, array $columns)
    {
        $sql = 'INSERT INTO ' . $this->quoteTable($table);

        if ($columns) {
            $sql .= ' (' . implode(',', array_map([$this, 'quoteIdentifier'], $columns)) . ') ';
        }

        $sql .= ' VALUES ';
        foreach ($rows as $row) {
            $sql .= ' (' . FormatLine::Insert($row) . '), ';
        }

        return trim($sql, ', ');
    }

    protected function buildSelectSql()
    {
        $sql = 'SELECT ' . $this->buildFieldSql() . ' FROM ' . $this->quoteTable($this->tableName);
        if ($where = $this->buildWhereSql()) {
            $sql .= ' WHERE ' . $where;
        }
        if ($this->orders) {
            $orders = [];
            foreach ($this->orders as $field => $sort) {
                $orders[] = $this->quoteIdentifier($field) . ' ' . (strtoupper($sort) === 'DESC' ? 'DESC' : 'ASC');
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ';
            $this->offset !== null && $sql .= $this->offset . ', ';
            $sql .= $this->limit;
        }
        return $sql;
    }

    protected function buildFieldSql()
    {
        if (!$this->fields || $this->fields === ['*']) {
            return '*';
        }
        return implode(', ', array_map([$this, 'quoteIdentifier'], $this->fields));
    }

    protected function buildWhereSql()
    {
        $parts = [];
        foreach ($this->where as $item) {
            if (isset($item['raw'])) {
                $parts[] = '(' . $item['raw'] . ')';
                continue;
            }

            $field = $this->quoteIdentifier($item['field']);
            $operator = strtoupper(trim($item['operator']));
            $value = $item['value'];

            if (in_array($operator, ['IN', 'NOT IN'], true)) {
                $values = is_array($value) ? $value : explode(',', $value);
                $parts[] = $field . ' ' . $operator . ' (' . implode(', ', array_map([$this, 'quoteValue'], $values)) . ')';
            } elseif (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
                $values = array_values((array)$value);
                if (count($values) < 2) {
                    throw new \InvalidArgumentException('Between condition needs two values');
                }
                $parts[] = $field . ' ' . $operator . ' ' . $this->quoteValue($values[0]) . ' AND ' . $this->quoteValue($values[1]);
            } elseif (in_array($operator, ['IS', 'IS NOT'], true) && $value === null) {
                $parts[] = $field . ' ' . $operator . ' NULL';
            } else {
                $parts[] = $field . ' ' . $operator . ' ' . $this->quoteValue($value);
            }
        }
        return implode(' AND ', $parts);
    }

    protected function quoteIdentifier($identifier)
    {
        if (
            $identifier === '*'
            || strpos($identifier, ' ') !== false
            || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(.*\)$/', $identifier)
        ) {
            return $identifier;
        }

        return implode('.', array_map(function ($part) {
            if ($part === '*') {
                return '*';
            }
            return '`' . str_replace('`', '``', $part) . '`';
        }, explode('.', $identifier)));
    }

    protected function quoteTable($table)
    {
        return $this->quoteIdentifier($table);
    }

    protected function quoteValue($value)
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value) . "'";
    }

    protected function resetQuery()
    {
        $this->where = [];
        $this->fields = ['*'];
        $this->orders = [];
        $this->limit = null;
        $this->offset = null;
    }
}
