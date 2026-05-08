<?php

namespace Tests\Clickhouse;

use ClickHouseDB\Client;
use PHPUnit\Framework\TestCase;
use WonderGame\EsUtility\Clickhouse\Model as BaseModel;

/**
 * Real ClickHouse CRUD test.
 *
 * Fill clickhouseConfig() before running:
 * php easyswoole phpunit tests/Clickhouse/Model.php
 */
class Model extends TestCase
{
    protected static $client;

    protected static $tableName = '';

    public static function setUpBeforeClass(): void
    {
        $config = self::clickhouseConfig();
        if (empty($config['host'])) {
            self::markTestSkipped('Please fill ClickHouse connection config in tests/Clickhouse/Model.php');
        }

        self::$tableName = 'es_utility_clickhouse_model_test_' . date('YmdHis') . '_' . mt_rand(1000, 9999);
        self::$client = new Client($config);

        if (!empty($config['database'])) {
            self::$client->database($config['database']);
        }
        if (isset($config['timeout']) && method_exists(self::$client, 'setTimeout')) {
            self::$client->setTimeout($config['timeout']);
        }
        if (isset($config['connect_timeout']) && method_exists(self::$client, 'setConnectTimeOut')) {
            self::$client->setConnectTimeOut($config['connect_timeout']);
        } elseif (isset($config['connect_timeout']) && method_exists(self::$client, 'setConnectTimeout')) {
            self::$client->setConnectTimeout($config['connect_timeout']);
        }

        self::$client->write('DROP TABLE IF EXISTS `' . self::$tableName . '`');
        self::$client->write(
            'CREATE TABLE `' . self::$tableName . '` (' .
            'id UInt64, ' .
            'name String, ' .
            'status UInt8, ' .
            'created_at DateTime' .
            ') ENGINE = MergeTree() ORDER BY id'
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$client instanceof Client && self::$tableName) {
            self::$client->write('DROP TABLE IF EXISTS `' . self::$tableName . '`');
        }
    }

    public function testRealClickhouseCrud()
    {
        $model = $this->newModel([
            'id' => 1,
            'name' => 'alpha',
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($model->save());

        $row = $this->newModel()->get(1);
        $this->assertInstanceOf(ClickhouseModelFixture::class, $row);
        $this->assertEquals('alpha', $row['name']);
        $this->assertEquals(1, (int)$row['status']);

        $this->assertTrue($this->newModel()->insertAll([
            [
                'id' => 2,
                'name' => 'beta',
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id' => 3,
                'name' => 'gamma',
                'status' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]));

        $rows = $this->newModel()
            ->field(['id', 'name'])
            ->where('status', 1)
            ->order('id', 'asc')
            ->all();
        $this->assertCount(2, $rows);
        $this->assertEquals(['alpha', 'beta'], array_column(array_map(function ($row) {
            return $row->toArray();
        }, $rows), 'name'));

        $this->assertEquals(3, $this->newModel()->count());
        $this->assertEquals('beta', $this->newModel()->where('id', 2)->val('name'));
        $this->assertEquals([1, 2], $this->newModel()->where('status', 1)->order('id', 'asc')->column('id'));

        $this->assertTrue($this->newModel(['id' => 2])->update(['name' => 'beta-updated', 'status' => 2]));
        $this->waitMutationDone();
        $updated = $this->newModel()->get(2);
        $this->assertEquals('beta-updated', $updated['name']);
        $this->assertEquals(2, (int)$updated['status']);

        $this->assertTrue($this->newModel()->where('id', 3)->destroy());
        $this->waitMutationDone();
        $this->assertEquals(2, $this->newModel()->count());
        $this->assertNull($this->newModel()->get(3));
    }

    protected function newModel(array $data = []): ClickhouseModelFixture
    {
        return new ClickhouseModelFixture($data, self::$tableName);
    }

    public static function client(): Client
    {
        return self::$client;
    }

    protected function waitMutationDone(int $timeout = 10): void
    {
        $deadline = time() + $timeout;
        do {
            $row = self::$client->select(
                "SELECT count() AS count FROM system.mutations " .
                "WHERE database = currentDatabase() " .
                "AND table = '" . self::$tableName . "' " .
                "AND is_done = 0"
            )->fetchOne();

            if ((int)($row['count'] ?? 0) === 0) {
                return;
            }
            usleep(200000);
        } while (time() < $deadline);

        $this->fail('ClickHouse mutation wait timeout');
    }

    protected static function clickhouseConfig(): array
    {
        return [
            'host' => 'dev_clickhouse', // TODO: fill your ClickHouse host, e.g. 127.0.0.1
            'port' => 8123,
            'username' => 'default',
            'password' => 'dev_clickhouse',
            'database' => 'default',
            'timeout' => 10,
            'connect_timeout' => 5,
        ];
    }
}

class ClickhouseModelFixture extends BaseModel
{
    protected $pk = 'id';

    public function getClient(): Client
    {
        return Model::client();
    }
}
