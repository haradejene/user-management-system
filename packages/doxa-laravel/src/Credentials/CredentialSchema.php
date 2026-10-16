<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class CredentialSchema
{
    /** Native catalog inspection works across Laravel 10-12 without Doctrine dependencies. */
    public static function assertReady(Connection $db, string $table): void
    {
        $table = $db->getTablePrefix().$table;
        $indexes = [];
        if ($db->getDriverName() === 'sqlite') {
            $columns = $db->select('select * from pragma_table_info(?)', [$table], false);
            foreach ($columns as $column) {
                if (($column->name === 'purge_at' && $column->notnull)
                    || ($column->name !== 'purge_at' && ! $column->notnull)) {
                    throw new CredentialException('credential_configuration_error');
                }
                if (in_array($column->name, ['version', 'expires_at', 'purge_at'], true)
                    && ! str_contains(strtolower($column->type), 'int')) {
                    throw new CredentialException('credential_configuration_error');
                }
            }
            foreach ($db->select('select * from pragma_index_list(?)', [$table], false) as $index) {
                if ($index->partial) {
                    continue;
                }
                $columns = array_map(static fn ($column) => $column->name,
                    $db->select('select * from pragma_index_info(?) order by seqno', [$index->name], false));
                $indexes[] = ['columns' => $columns, 'primary' => $index->origin === 'pk', 'unique' => (bool) $index->unique];
            }
        } elseif ($db->getDriverName() === 'pgsql') {
            $columns = $db->select('select a.attname, a.attnotnull, t.typname from pg_attribute a
                join pg_type t on t.oid = a.atttypid where a.attrelid = to_regclass(?)
                and a.attnum > 0 and not a.attisdropped', [$db->getQueryGrammar()->wrap($table)], false);
            foreach ($columns as $column) {
                if (($column->attname === 'purge_at' && $column->attnotnull)
                    || ($column->attname !== 'purge_at' && ! $column->attnotnull)
                    || (in_array($column->attname, ['version', 'expires_at', 'purge_at'], true) && $column->typname !== 'int8')) {
                    throw new CredentialException('credential_configuration_error');
                }
            }
            $rows = $db->select('select i.indisprimary as primary_key, i.indisunique as unique_key,
                array_to_json(array(select a.attname from unnest(i.indkey) with ordinality k(attnum, position)
                    join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum
                    where k.position <= i.indnkeyatts order by k.position)) as columns
                from pg_index i where i.indrelid = to_regclass(?) and i.indisvalid and i.indisready
                and i.indpred is null and i.indexprs is null', [$db->getQueryGrammar()->wrap($table)], false);
            foreach ($rows as $row) {
                $indexes[] = ['columns' => json_decode($row->columns, true, flags: JSON_THROW_ON_ERROR),
                    'primary' => $row->primary_key, 'unique' => $row->unique_key];
            }
        } else {
            // No production claim for an unverified catalog/atomic-update adapter.
            throw new CredentialException('credential_configuration_error');
        }
        foreach (['id', 'provenance', 'status', 'expires_at', 'purge_at'] as $field) {
            $found = false;
            foreach ($indexes as $index) {
                $found = $found || ($index['columns'] === [$field]
                    && ($field !== 'id' || $index['primary'])
                    && (! in_array($field, ['id', 'provenance'], true) || $index['unique']));
            }
            if (! $found) {
                throw new CredentialException('credential_configuration_error');
            }
        }
    }

    public static function create(Builder $schema, string $table): void
    {
        $schema->create($table, function (Blueprint $row) {
            $row->string('id', 64)->primary();
            $row->string('provenance', 64)->unique();
            $row->string('status', 32)->index();
            $row->bigInteger('version');
            $row->bigInteger('expires_at')->index();
            $row->bigInteger('purge_at')->nullable()->index();
            $row->string('key_id', 64);
            $row->longText('payload');
        });
    }
}
