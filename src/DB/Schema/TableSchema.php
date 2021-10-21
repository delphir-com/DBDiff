<?php namespace DBDiff\DB\Schema;

use DBDiff\Logger;
use Diff\Differ\MapDiffer;

use Diff\Differ\ListDiffer;
use Illuminate\Support\Str;

use DBDiff\SQLGen\Schema\SQL;
use DBDiff\Diff\AlterTableAddKey;
use DBDiff\Diff\AlterTableEngine;

use DBDiff\Diff\AlterTableDropKey;
use DBDiff\Diff\AlterTableAddColumn;
use DBDiff\Diff\AlterTableChangeKey;

use DBDiff\Diff\AlterTableCollation;
use DBDiff\Diff\AlterTableDropColumn;
use DBDiff\Diff\AlterTableChangeColumn;

use DBDiff\Diff\AlterTableAddConstraint;

use DBDiff\Diff\AlterTableDropConstraint;
use DBDiff\Diff\AlterTableChangeConstraint;


class TableSchema {

    function __construct($manager) {
        $this->manager = $manager;
        $this->source = $this->manager->getDB('source');
        $this->target = $this->manager->getDB('target');
    }

    public function getSchema($connection, $table) {
        // collation & engine
        $status = $this->{$connection}->select("show table status like '$table'");
        $engine = $status[0]->Engine;
        // $collation = $status[0]->Collation;

        $schema = $this->{$connection}->select("SHOW CREATE TABLE `$table`")[0]->{"Create Table"};
        $lines = array_map(function($el) { return trim($el);}, explode("\n", $schema));
        $lines = array_slice($lines, 1, -1);
        
        $columns = [];
        $keys = [];
        $constraints = [];
        
        foreach ($lines as $line) {
            preg_match("/`([^`]+)`/", $line, $matches);
            $name = $matches[1];
            $line = trim($line, ',');
            if (Str::startsWith($line, '`')) { // column
                $columns[$name] = $line;
            } else if (Str::startsWith($line, 'CONSTRAINT')) { // constraint
                $constraints[$name] = $line;
            } else { // keys
                $keys[$name] = $line;
            }
        }

        return [
            'engine'      => $engine,
            // 'collation'   => $collation,
            'columns'     => $columns,
            'keys'        => $keys,
            'constraints' => $constraints
        ];
    }

    public function getDiff($table) {
        $diffSequence = [];
        $sourceSchema = $this->getSchema('source', $table);
        $targetSchema = $this->getSchema('target', $table);

        // Engine
        $sourceEngine = $sourceSchema['engine'];
        $targetEngine = $targetSchema['engine'];
        if ($sourceEngine != $targetEngine) {
            $diffSequence[] = new AlterTableEngine($table, $sourceEngine, $targetEngine);
        }

        // // Collation
        // $sourceCollation = $sourceSchema['collation'];
        // $targetCollation = $targetSchema['collation'];
        // if ($sourceCollation != $targetCollation) {
        //     $diffSequence[] = new AlterTableCollation($table, $sourceCollation, $targetCollation);
        // }

        // Columns
        $sourceColumns = $sourceSchema['columns'];
        array_walk($sourceColumns, function (&$column) {
            $column = str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', '', $column);
            $column = str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', '', $column);
            $column = str_replace(' COLLATE utf8mb4_general_ci', '', $column);
            $column = str_replace(' COLLATE utf8mb4_unicode_ci', '', $column);
            $column = str_replace(' DEFAULT \'0000-00-00 00:00:00\'', '', $column);
            $column = str_replace(' DEFAULT \'0000-00-00\'', '', $column);
        });
        $targetColumns = $targetSchema['columns'];
        array_walk($targetColumns, function (&$column) {
            $column = str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', '', $column);
            $column = str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', '', $column);
            $column = str_replace(' COLLATE utf8mb4_general_ci', '', $column);
            $column = str_replace(' COLLATE utf8mb4_unicode_ci', '', $column);
            $column = str_replace(' DEFAULT \'0000-00-00 00:00:00\'', '', $column);
            $column = str_replace(' DEFAULT \'0000-00-00\'', '', $column);
        });
        $differ = new MapDiffer();
        $diffs = $differ->doDiff($targetColumns, $sourceColumns);
        foreach ($diffs as $column => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $diffSequence[] = new AlterTableDropColumn($table, $column, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $diffSequence[] = new AlterTableChangeColumn($table, $column, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $diffSequence[] = new AlterTableAddColumn($table, $column, $diff);
            }
        }

        // Keys
        $sourceKeys = $sourceSchema['keys'];
        $targetKeys = $targetSchema['keys'];
        array_walk($sourceKeys, function (&$key) {
            $key = str_replace(' USING BTREE', '', $key);
        });
        array_walk($targetKeys, function (&$key) {
            $key = str_replace(' USING BTREE', '', $key);
        });
        $differ = new MapDiffer();
        $diffs = $differ->doDiff($targetKeys, $sourceKeys);
        foreach ($diffs as $key => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $diffSequence[] = new AlterTableDropKey($table, $key, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $diffSequence[] = new AlterTableChangeKey($table, $key, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $diffSequence[] = new AlterTableAddKey($table, $key, $diff);
            }
        }

        // Constraints
        $sourceConstraints = $sourceSchema['constraints'];
        $targetConstraints = $targetSchema['constraints'];
        $differ = new MapDiffer();
        $diffs = $differ->doDiff($targetConstraints, $sourceConstraints);
        foreach ($diffs as $name => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                $diffSequence[] = new AlterTableDropConstraint($table, $name, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpChange) {
                $diffSequence[] = new AlterTableChangeConstraint($table, $name, $diff);
            } else if ($diff instanceof \Diff\DiffOp\DiffOpAdd) {
                $diffSequence[] = new AlterTableAddConstraint($table, $name, $diff);
            }
        }

        return $diffSequence;
    }

}
