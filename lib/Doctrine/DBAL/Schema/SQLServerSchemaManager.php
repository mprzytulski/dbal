<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.phpdoctrine.org>.
 */

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Events;
use Doctrine\DBAL\Event\SchemaIndexDefinitionEventArgs;
use Doctrine\DBAL\Driver\SQLSrv\SQLSrvException;
use Doctrine\DBAL\Types\Type;

/**
 * SQL Server Schema Manager
 *
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @author      Konsta Vesterinen <kvesteri@cc.hut.fi>
 * @author      Lukas Smith <smith@pooteeweet.org> (PEAR MDB2 library)
 * @author      Juozas Kaziukenas <juozas@juokaz.com>
 * @author      Steve Müller <st.mueller@dzh-online.de>
 * @since       2.0
 */
class SQLServerSchemaManager extends AbstractSchemaManager
{
    /**
     * {@inheritdoc}
     */
    protected function _getPortableSequenceDefinition($sequence)
    {
        return new Sequence($sequence['name'], $sequence['increment'], $sequence['start_value']);
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableColumnDefinition($tableColumn)
    {
        $dbType = strtok($tableColumn['type'], '(), ');
        $fixed = null;
        $length = (int) $tableColumn['length'];
        $default = $tableColumn['default'];

        if (!isset($tableColumn['name'])) {
            $tableColumn['name'] = '';
        }

        while ($default != ($default2 = preg_replace("/^\((.*)\)$/", '$1', $default))) {
            $default = trim($default2, "'");
        }

        switch ($dbType) {
            case 'nchar':
            case 'nvarchar':
            case 'ntext':
                // Unicode data requires 2 bytes per character
                $length = $length / 2;
                break;
            case 'varchar':
                // TEXT type is returned as VARCHAR(MAX) with a length of -1
                if ($length == -1) {
                    $dbType = 'text';
                }
                break;
        }

        $type = $this->_platform->getDoctrineTypeMapping($dbType);

        switch ($type) {
            case 'char':
                $fixed = true;
                break;
            case 'text':
                $fixed = false;
                break;
        }

        $options = array(
            'length' => ($length == 0 || !in_array($type, array('text', 'string'))) ? null : $length,
            'unsigned' => false,
            'fixed' => (bool) $fixed,
            'default' => $default !== 'NULL' ? $default : null,
            'notnull' => (bool) $tableColumn['notnull'],
            'scale' => $tableColumn['scale'],
            'precision' => $tableColumn['precision'],
            'autoincrement' => (bool) $tableColumn['autoincrement'],
        );

        $platformOptions = array(
            'collate' => $tableColumn['collation'] == 'NULL' ? null : $tableColumn['collation']
        );

        $column = new Column($tableColumn['name'], Type::getType($type), $options);
        $column->setPlatformOptions($platformOptions);

        return $column;
    }

    /**
     * @override
     */
    protected function _getPortableTableIndexesList($tableIndexRows, $tableName=null)
    {
        // TODO: Remove code duplication with AbstractSchemaManager;
        $result = array();
        foreach ($tableIndexRows as $tableIndex) {
            $indexName = $keyName = $tableIndex['index_name'];
            if (strpos($tableIndex['index_description'], 'primary key') !== false) {
                $keyName = 'primary';
            }
            $keyName = strtolower($keyName);

            $flags = array();
            if (strpos($tableIndex['index_description'], 'clustered') !== false) {
                $flags[] = 'clustered';
            } else if (strpos($tableIndex['index_description'], 'nonclustered') !== false) {
                $flags[] = 'nonclustered';
            }

            $result[$keyName] = array(
                'name' => $indexName,
                'columns' => explode(', ', $tableIndex['index_keys']),
                'unique' => strpos($tableIndex['index_description'], 'unique') !== false,
                'primary' => strpos($tableIndex['index_description'], 'primary key') !== false,
                'flags' => $flags,
            );
        }

        $eventManager = $this->_platform->getEventManager();

        $indexes = array();
        foreach ($result as $indexKey => $data) {
            $index = null;
            $defaultPrevented = false;

            if (null !== $eventManager && $eventManager->hasListeners(Events::onSchemaIndexDefinition)) {
                $eventArgs = new SchemaIndexDefinitionEventArgs($data, $tableName, $this->_conn);
                $eventManager->dispatchEvent(Events::onSchemaIndexDefinition, $eventArgs);

                $defaultPrevented = $eventArgs->isDefaultPrevented();
                $index = $eventArgs->getIndex();
            }

            if ( ! $defaultPrevented) {
                $index = new Index($data['name'], $data['columns'], $data['unique'], $data['primary']);
            }

            if ($index) {
                $indexes[$indexKey] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @override
     */
    public function _getPortableTableForeignKeyDefinition($tableForeignKey)
    {
        return new ForeignKeyConstraint(
                (array) $tableForeignKey['ColumnName'],
                $tableForeignKey['ReferenceTableName'],
                (array) $tableForeignKey['ReferenceColumnName'],
                $tableForeignKey['ForeignKey'],
                array(
                    'onUpdate' => str_replace('_', ' ', $tableForeignKey['update_referential_action_desc']),
                    'onDelete' => str_replace('_', ' ', $tableForeignKey['delete_referential_action_desc']),
                )
        );
    }

    /**
     * @override
     */
    protected function _getPortableTableDefinition($table)
    {
        return $table['name'];
    }

    /**
     * @override
     */
    protected function _getPortableDatabaseDefinition($database)
    {
        return $database['name'];
    }

    /**
     * @override
     */
    protected function _getPortableViewDefinition($view)
    {
        // @todo
        return new View($view['name'], null);
    }

    /**
     * List the indexes for a given table returning an array of Index instances.
     *
     * Keys of the portable indexes list are all lower-cased.
     *
     * @param string $table The name of the table
     * @return Index[] $tableIndexes
     */
    public function listTableIndexes($table)
    {
        $sql = $this->_platform->getListTableIndexesSQL($table, $this->_conn->getDatabase());

        try {
            $tableIndexes = $this->_conn->fetchAll($sql);
        } catch(\PDOException $e) {
            if ($e->getCode() == "IMSSP") {
                return array();
            } else {
                throw $e;
            }
        } catch(SQLSrvException $e) {
            if (strpos($e->getMessage(), 'SQLSTATE [01000, 15472]') === 0) {
                return array();
            } else {
                throw $e;
            }
        }

        return $this->_getPortableTableIndexesList($tableIndexes, $table);
    }

    /**
     * @override
     */
    public function alterTable(TableDiff $tableDiff)
    {
        if(count($tableDiff->removedColumns) > 0) {
            foreach($tableDiff->removedColumns as $col){
                $columnConstraintSql = $this->getColumnConstraintSQL($tableDiff->name, $col->getName());
                foreach ($this->_conn->fetchAll($columnConstraintSql) as $constraint) {
                    $this->_conn->exec("ALTER TABLE $tableDiff->name DROP CONSTRAINT " . $constraint['Name']);
                }
            }
        }

        return parent::alterTable($tableDiff);
    }

    /**
     * This function retrieves the constraints for a given column.
     */
    private function getColumnConstraintSQL($table, $column)
    {
        return "SELECT SysObjects.[Name]
            FROM SysObjects INNER JOIN (SELECT [Name],[ID] FROM SysObjects WHERE XType = 'U') AS Tab
            ON Tab.[ID] = Sysobjects.[Parent_Obj]
            INNER JOIN sys.default_constraints DefCons ON DefCons.[object_id] = Sysobjects.[ID]
            INNER JOIN SysColumns Col ON Col.[ColID] = DefCons.[parent_column_id] AND Col.[ID] = Tab.[ID]
            WHERE Col.[Name] = " . $this->_conn->quote($column) ." AND Tab.[Name] = " . $this->_conn->quote($table) . "
            ORDER BY Col.[Name]";
    }
}
