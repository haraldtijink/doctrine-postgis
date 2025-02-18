<?php

declare(strict_types=1);

namespace Jsor\Doctrine\PostGIS\Event;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Event\SchemaAlterTableChangeColumnEventArgs;
use Doctrine\DBAL\Event\SchemaAlterTableEventArgs;
use Doctrine\DBAL\Event\SchemaColumnDefinitionEventArgs;
use Doctrine\DBAL\Event\SchemaCreateTableEventArgs;
use Doctrine\DBAL\Event\SchemaIndexDefinitionEventArgs;
use Doctrine\DBAL\Events;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Types\Type;
use Jsor\Doctrine\PostGIS\Schema\CreateTableSqlGenerator;
use Jsor\Doctrine\PostGIS\Schema\SchemaManager;
use Jsor\Doctrine\PostGIS\Schema\SpatialIndexSqlGenerator;
use Jsor\Doctrine\PostGIS\Types\GeographyType;
use Jsor\Doctrine\PostGIS\Types\GeometryType;
use Jsor\Doctrine\PostGIS\Types\PostGISType;
use LogicException;
use RuntimeException;

class DBALSchemaEventSubscriber implements EventSubscriber
{
    protected ?Connection $connection = null;
    protected ?SchemaManager $schemaManager = null;
    protected bool $postConnectCalled = false;

    public function getSubscribedEvents(): array
    {
        return [
            Events::postConnect,
            Events::onSchemaCreateTable,
            Events::onSchemaColumnDefinition,
            Events::onSchemaIndexDefinition,
            Events::onSchemaAlterTable,
            Events::onSchemaAlterTableChangeColumn,
        ];
    }

    public function postConnect(ConnectionEventArgs $args): void
    {
        if ($this->postConnectCalled) {
            // Allows multiple postConnect calls for the same connection
            // instance. This is done by PrimaryReadReplicaConnection for example
            // when switching primary/replica connections.
            if ($this->connection === $args->getConnection()) {
                return;
            }

            throw new LogicException(
                sprintf(
                    'It looks like you have registered the %s to more than one connection. Please register one instance per connection.',
                    static::class
                )
            );
        }

        $this->connection = $args->getConnection();
        $this->schemaManager = new SchemaManager($this->connection);
        $this->postConnectCalled = true;

        if (!Type::hasType(PostGISType::GEOMETRY)) {
            Type::addType(PostGISType::GEOMETRY, GeometryType::class);
        }

        if (!Type::hasType(PostGISType::GEOGRAPHY)) {
            Type::addType(PostGISType::GEOGRAPHY, GeographyType::class);
        }
    }

    public function onSchemaCreateTable(SchemaCreateTableEventArgs $args): void
    {
        $generator = new CreateTableSqlGenerator($args->getPlatform());

        /** @var array{primary?: array<string>, indexes?: array<Index>, foreignKeys?: ForeignKeyConstraint|array<ForeignKeyConstraint>} $options */
        $options = $args->getOptions();

        $args
            ->addSql(
                $generator->getSql(
                    $args->getTable(),
                    $args->getColumns(),
                    $options
                )
            )
            ->preventDefault()
        ;
    }

    public function onSchemaAlterTable(SchemaAlterTableEventArgs $args): void
    {
        $platform = $args->getPlatform();
        $diff = $args->getTableDiff();

        $spatialIndexes = [];
        $addedIndexes = [];
        $changedIndexes = [];

        foreach ($diff->addedIndexes as $index) {
            if (!$index->hasFlag('spatial')) {
                $addedIndexes[] = $index;
            } else {
                $spatialIndexes[] = $index;
            }
        }

        foreach ($diff->changedIndexes as $index) {
            if (!$index->hasFlag('spatial')) {
                $changedIndexes[] = $index;
            } else {
                $diff->removedIndexes[] = $index;
                $spatialIndexes[] = $index;
            }
        }

        $diff->addedIndexes = $addedIndexes;
        $diff->changedIndexes = $changedIndexes;

        $spatialIndexSqlGenerator = new SpatialIndexSqlGenerator($platform);
        $sql = [];

        $table = new Identifier(false !== $diff->newName ? $diff->newName : $diff->name);

        foreach ($spatialIndexes as $index) {
            $sql[] = $spatialIndexSqlGenerator->getSql($index, $table);
        }

        $args
            ->addSql($sql)
        ;
    }

    public function onSchemaAlterTableChangeColumn(SchemaAlterTableChangeColumnEventArgs $args): void
    {
        $columnDiff = $args->getColumnDiff();
        $column = $columnDiff->column;

        if (!$column->getType() instanceof PostGISType) {
            return;
        }

        $diff = $args->getTableDiff();
        $table = new Identifier(false !== $diff->newName ? $diff->newName : $diff->name);

        if ($columnDiff->hasChanged('type')) {
            throw new RuntimeException('The type of a spatial column cannot be changed (Requested changing type from "' . ($columnDiff->fromColumn?->getType()?->getName() ?? 'N/A') . '" to "' . $column->getType()->getName() . '" for column "' . $column->getName() . '" in table "' . $table->getName() . '")');
        }

        if ($columnDiff->hasChanged('geometry_type')) {
            throw new RuntimeException('The geometry_type of a spatial column cannot be changed (Requested changing type from "' . strtoupper((string) ($columnDiff->fromColumn?->getCustomSchemaOption('geometry_type') ?? 'N/A')) . '" to "' . strtoupper((string) $column->getCustomSchemaOption('geometry_type')) . '" for column "' . $column->getName() . '" in table "' . $table->getName() . '")');
        }

        if ($columnDiff->hasChanged('srid')) {
            $args->addSql(sprintf(
                "SELECT UpdateGeometrySRID('%s', '%s', %d)",
                $table->getName(),
                $column->getName(),
                (int) $column->getCustomSchemaOption('srid')
            ));
        }
    }

    public function onSchemaColumnDefinition(SchemaColumnDefinitionEventArgs $args): void
    {
        /** @var array{type: string, default: string, field: string, isnotnull: int|bool} $tableColumn */
        $tableColumn = array_change_key_case($args->getTableColumn(), CASE_LOWER);
        $table = $args->getTable();

        $info = null;

        if ('geometry' === $tableColumn['type']) {
            $info = $this->schemaManager?->getGeometrySpatialColumnInfo($table, $tableColumn['field']);
        } elseif ('geography' === $tableColumn['type']) {
            $info = $this->schemaManager?->getGeographySpatialColumnInfo($table, $tableColumn['field']);
        }

        if (!$info) {
            return;
        }

        $default = null;

        if (isset($tableColumn['default']) &&
            'NULL::geometry' !== $tableColumn['default'] &&
            'NULL::geography' !== $tableColumn['default']) {
            $default = $tableColumn['default'];
        }

        $options = [
            'notnull' => (bool) $tableColumn['isnotnull'],
            'default' => $default,
            'comment' => $tableColumn['comment'] ?? null,
        ];

        $column = new Column($tableColumn['field'], PostGISType::getType($tableColumn['type']), $options);

        $column
            ->setCustomSchemaOption('geometry_type', $info['type'])
            ->setCustomSchemaOption('srid', $info['srid'])
        ;

        $args
            ->setColumn($column)
            ->preventDefault();
    }

    public function onSchemaIndexDefinition(SchemaIndexDefinitionEventArgs $args): void
    {
        /** @var array{name: string, columns: array<string>, unique: bool, primary: bool, flags: array<string>} $index */
        $index = $args->getTableIndex();

        $spatialIndexes = $this->schemaManager?->listSpatialIndexes($args->getTable());

        if (!isset($spatialIndexes[$index['name']])) {
            return;
        }

        $spatialIndex = new Index(
            $index['name'],
            $index['columns'],
            $index['unique'],
            $index['primary'],
            array_merge($index['flags'], ['spatial'])
        );

        $args
            ->setIndex($spatialIndex)
            ->preventDefault()
        ;
    }
}
