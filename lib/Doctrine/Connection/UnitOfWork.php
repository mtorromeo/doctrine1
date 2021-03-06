<?php

/**
 * @template Connection of Doctrine_Connection
 * @extends Doctrine_Connection_Module<Connection>
 */
class Doctrine_Connection_UnitOfWork extends Doctrine_Connection_Module
{
    /**
     * Saves the given record and all associated records.
     * (The save() operation is always cascaded in 0.10/1.0).
     *
     * @param  Doctrine_Record $record
     * @param  bool            $replace
     * @return bool
     */
    public function saveGraph(Doctrine_Record $record, $replace = false)
    {
        $record->assignInheritanceValues();

        $conn = $this->getConnection();
        $conn->connect();

        $state = $record->state();
        if ($state->isLocked()) {
            return false;
        }

        $savepoint = $conn->beginInternalTransaction();

        try {
            $event = $record->invokeSaveHooks('pre', 'save');
            $isValid = true;

            try {
                $this->saveRelatedLocalKeys($record);
            } catch (Doctrine_Validator_Exception $e) {
                foreach ($e->getInvalidRecords() as $invalid) {
                    $savepoint->addInvalid($invalid);
                }
            }

            if ($state->isTransient()) {
                if ($replace) {
                    $isValid = $this->replace($record);
                } else {
                    $isValid = $this->insert($record);
                }
            } elseif (!$state->isClean()) {
                if ($replace) {
                    $isValid = $this->replace($record);
                } else {
                    $isValid = $this->update($record);
                }
            }

            $aliasesUnlinkInDb = [];

            if ($isValid) {
                // NOTE: what about referential integrity issues?
                foreach ($record->getPendingDeletes() as $pendingDelete) {
                    $pendingDelete->delete();
                }

                foreach ($record->getPendingUnlinks() as $alias => $ids) {
                    if ($ids === false) {
                        $record->unlinkInDb($alias, []);
                        $aliasesUnlinkInDb[] = $alias;
                    } elseif ($ids) {
                        $record->unlinkInDb($alias, array_keys($ids));
                        $aliasesUnlinkInDb[] = $alias;
                    }
                }
                $record->resetPendingUnlinks();

                $record->invokeSaveHooks('post', 'save', $event);
            } else {
                $savepoint->addInvalid($record);
            }

            if ($isValid) {
                $state = $record->state();
                $record->state($state->lock());
                try {
                    $saveLater = $this->saveRelatedForeignKeys($record);
                    foreach ($saveLater as $fk) {
                        $alias = $fk->getAlias();

                        if ($record->hasReference($alias)) {
                            $obj = $record->$alias;

                            // check that the related object is not an instance of Doctrine_Null
                            if ($obj && !($obj instanceof Doctrine_Null)) {
                                $processDiff = !in_array($alias, $aliasesUnlinkInDb);
                                $obj->save($conn, $processDiff);
                            }
                        }
                    }

                    // save the MANY-TO-MANY associations
                    $this->saveAssociations($record);
                } finally {
                    $record->state($state);
                }
            }
        } catch (Throwable $e) {
            // Make sure we roll back our internal transaction
            //$record->state($state);
            $savepoint->rollback();
            throw $e;
        }

        $savepoint->commit();
        $record->clearInvokedSaveHooks();

        return true;
    }

    /**
     * Deletes the given record and all the related records that participate
     * in an application-level delete cascade.
     *
     * this event can be listened by the onPreDelete and onDelete listeners
     *
     * @return boolean      true on success, false on failure
     */
    public function delete(Doctrine_Record $record)
    {
        $deletions = [];
        $this->collectDeletions($record, $deletions);
        return $this->executeDeletions($deletions);
    }

    /**
     * Collects all records that need to be deleted by applying defined
     * application-level delete cascades.
     *
     * @param array $deletions Map of the records to delete. Keys=Oids Values=Records.
     *
     * @return void
     */
    private function collectDeletions(Doctrine_Record $record, array &$deletions)
    {
        if (!$record->exists()) {
            return;
        }

        $deletions[$record->getOid()] = $record;
        $this->cascadeDelete($record, $deletions);
    }

    /**
     * Executes the deletions for all collected records during a delete operation
     * (usually triggered through $record->delete()).
     *
     * @param  array $deletions Map of the records to delete. Keys=Oids Values=Records.
     * @return true
     */
    private function executeDeletions(array $deletions)
    {
        // collect class names
        $classNames = [];
        foreach ($deletions as $record) {
            $classNames[] = $record->getTable()->getComponentName();
        }
        $classNames = array_unique($classNames);

        // order deletes
        $executionOrder = $this->buildFlushTree($classNames);

        // execute
        $savepoint = $this->conn->beginInternalTransaction();

        try {
            for ($i = count($executionOrder) - 1; $i >= 0; $i--) {
                $className = $executionOrder[$i];
                $table     = $this->conn->getTable($className);

                // collect identifiers
                $identifierMaps = [];
                $deletedRecords = [];
                foreach ($deletions as $oid => $record) {
                    if ($record->getTable()->getComponentName() == $className) {
                        $this->preDelete($record);
                        $identifierMaps[] = $record->identifier();
                        $deletedRecords[] = $record;
                        unset($deletions[$oid]);
                    }
                }

                if (count($deletedRecords) < 1) {
                    continue;
                }

                // extract query parameters (only the identifier values are of interest)
                $params      = [];
                $columnNames = [];
                foreach ($identifierMaps as $idMap) {
                    foreach ($idMap as $fieldName => $value) {
                        $params[]      = $value;
                        $columnNames[] = $table->getColumnName($fieldName);
                    }
                }
                $columnNames = array_unique($columnNames);

                // delete
                $tableName = $table->getTableName();
                $sql       = 'DELETE FROM ' . $this->conn->quoteIdentifier($tableName) . ' WHERE ';

                if ($table->isIdentifierComposite()) {
                    $sql .= $this->buildSqlCompositeKeyCondition($columnNames, count($identifierMaps));
                    $this->conn->exec($sql, $params);
                } else {
                    $sql .= $this->buildSqlSingleKeyCondition($columnNames, count($params));
                    $this->conn->exec($sql, $params);
                }

                // adjust state, remove from identity map and inform postDelete listeners
                foreach ($deletedRecords as $record) {
                    $record->state(Doctrine_Record_State::TCLEAN());
                    $record->getTable()->removeRecord($record);
                    $this->postDelete($record);
                }
            }

            // trigger postDelete for records skipped during the deletion (veto!)
            foreach ($deletions as $skippedRecord) {
                $this->postDelete($skippedRecord);
            }
        } catch (Throwable $e) {
            $savepoint->rollback();
            throw $e;
        }

        $savepoint->commit();
        return true;
    }

    /**
     * Builds the SQL condition to target multiple records who have a single-column
     * primary key.
     *
     * @param  array   $columnNames
     * @param  integer $numRecords  The number of records that are going to be deleted.
     * @return string  The SQL condition "pk = ? OR pk = ? OR pk = ? ..."
     */
    private function buildSqlSingleKeyCondition($columnNames, $numRecords)
    {
        $idColumn = $this->conn->quoteIdentifier($columnNames[0]);
        return implode(' OR ', array_fill(0, $numRecords, "$idColumn = ?"));
    }

    /**
     * Builds the SQL condition to target multiple records who have a composite primary key.
     *
     * @param  array   $columnNames
     * @param  integer $numRecords  The number of records that are going to be deleted.
     * @return string  The SQL condition "(pk1 = ? AND pk2 = ?) OR (pk1 = ? AND pk2 = ?) ..."
     */
    private function buildSqlCompositeKeyCondition($columnNames, $numRecords)
    {
        $singleCondition = '';
        foreach ($columnNames as $columnName) {
            $columnName = $this->conn->quoteIdentifier($columnName);
            if ($singleCondition === '') {
                $singleCondition .= "($columnName = ?";
            } else {
                $singleCondition .= " AND $columnName = ?";
            }
        }
        $singleCondition .= ')';
        $fullCondition = implode(' OR ', array_fill(0, $numRecords, $singleCondition));

        return $fullCondition;
    }

    /**
     * Cascades an ongoing delete operation to related objects. Applies only on relations
     * that have 'delete' in their cascade options.
     * This is an application-level cascade. Related objects that participate in the
     * cascade and are not yet loaded are fetched from the database.
     * Exception: many-valued relations are always (re-)fetched from the database to
     * make sure we have all of them.
     *
     * @param  Doctrine_Record $record The record for which the delete operation will be cascaded.
     * @throws PDOException    If something went wrong at database level
     * @return void
     */
    protected function cascadeDelete(Doctrine_Record $record, array &$deletions)
    {
        foreach ($record->getTable()->getRelations() as $relation) {
            if ($relation->isCascadeDelete()) {
                $fieldName = $relation->getAlias();
                // if it's a xToOne relation and the related object is already loaded
                // we don't need to refresh.
                if (!($relation->getType() == Doctrine_Relation::ONE && isset($record->$fieldName))) {
                    $record->refreshRelated($relation->getAlias());
                }
                $relatedObjects = $record->get($relation->getAlias());
                if ($relatedObjects instanceof Doctrine_Record && $relatedObjects->exists()
                    && !isset($deletions[$relatedObjects->getOid()])
                ) {
                    $this->collectDeletions($relatedObjects, $deletions);
                } elseif ($relatedObjects instanceof Doctrine_Collection && count($relatedObjects) > 0) {
                    // cascade the delete to the other objects
                    foreach ($relatedObjects as $object) {
                        if (!isset($deletions[$object->getOid()])) {
                            $this->collectDeletions($object, $deletions);
                        }
                    }
                }
            }
        }
    }

    /**
     * saveRelatedForeignKeys
     * saves all related (through ForeignKey) records to $record
     *
     * @throws PDOException         if something went wrong at database level
     *
     * @param Doctrine_Record $record
     *
     * @return Doctrine_Relation_ForeignKey[]
     */
    public function saveRelatedForeignKeys(Doctrine_Record $record)
    {
        $saveLater = [];
        foreach ($record->getReferences() as $k => $v) {
            $rel = $record->getTable()->getRelation($k);
            if ($rel instanceof Doctrine_Relation_ForeignKey) {
                $saveLater[$k] = $rel;
            }
        }

        return $saveLater;
    }

    /**
     * saveRelatedLocalKeys
     * saves all related (through LocalKey) records to $record
     *
     * @throws PDOException         if something went wrong at database level
     *
     * @param Doctrine_Record $record
     */
    public function saveRelatedLocalKeys(Doctrine_Record $record): void
    {
        $state = $record->state();
        $record->state($state->lock());

        try {
            foreach ($record->getReferences() as $k => $v) {
                $rel = $record->getTable()->getRelation($k);

                $local   = $rel->getLocal();
                $foreign = $rel->getForeign();

                if ($rel instanceof Doctrine_Relation_LocalKey) {
                    // ONE-TO-ONE relationship
                    $obj = $record->get($rel->getAlias());

                    // Protection against infinite function recursion before attempting to save
                    if ($obj instanceof Doctrine_Record && $obj->isModified()) {
                        $obj->save($this->conn);

                        $id = array_values($obj->identifier());

                        if (!empty($id)) {
                            foreach ((array) $rel->getLocal() as $k => $columnName) {
                                $field = $record->getTable()->getFieldName($columnName);

                                if (isset($id[$k]) && $id[$k] && $record->getTable()->hasField($field)) {
                                    $record->set($field, $id[$k]);
                                }
                            }
                        }
                    }
                }
            }
        } finally {
            $record->state($state);
        }
    }

    /**
     * saveAssociations
     *
     * this method takes a diff of one-to-many / many-to-many original and
     * current collections and applies the changes
     *
     * for example if original many-to-many related collection has records with
     * primary keys 1,2 and 3 and the new collection has records with primary keys
     * 3, 4 and 5, this method would first destroy the associations to 1 and 2 and then
     * save new associations to 4 and 5
     *
     * @throws Doctrine_Connection_Exception         if something went wrong at database level
     * @param  Doctrine_Record $record
     * @return void
     */
    public function saveAssociations(Doctrine_Record $record)
    {
        foreach ($record->getReferences() as $k => $v) {
            $rel = $record->getTable()->getRelation($k);

            if ($rel instanceof Doctrine_Relation_Association) {
                if ($this->conn->getAttribute(Doctrine_Core::ATTR_CASCADE_SAVES) || $v->isModified()) {
                    $v->save($this->conn, false);
                }

                $assocTable = $rel->getAssociationTable();
                foreach ($v->getDeleteDiff() as $r) {
                    $query = 'DELETE FROM ' . $assocTable->getTableName()
                           . ' WHERE ' . $rel->getForeignRefColumnName() . ' = ?'
                           . ' AND ' . $rel->getLocalRefColumnName() . ' = ?';

                    $this->conn->execute($query, [$r->getIncremented(), $record->getIncremented()]);
                }

                foreach ($v->getInsertDiff() as $r) {
                    $assocRecord = $assocTable->create();
                    $assocRecord->set($assocTable->getFieldName($rel->getForeign()), $r);
                    $assocRecord->set($assocTable->getFieldName($rel->getLocal()), $record);
                    $this->saveGraph($assocRecord);
                }
                // take snapshot of collection state, so that we know when its modified again
                $v->takeSnapshot();
            }
        }
    }

    /**
     * Invokes preDelete event listeners.
     */
    private function preDelete(Doctrine_Record $record): void
    {
        $event = new Doctrine_Event($record, Doctrine_Event::RECORD_DELETE);
        $record->preDelete($event);
        $record->getTable()->getRecordListener()->preDelete($event);
    }

    /**
     * Invokes postDelete event listeners.
     *
     * @return void
     */
    private function postDelete(Doctrine_Record $record): void
    {
        $event = new Doctrine_Event($record, Doctrine_Event::RECORD_DELETE);
        $record->postDelete($event);
        $record->getTable()->getRecordListener()->postDelete($event);
    }

    /**
     * saveAll
     * persists all the pending records from all tables
     *
     * @throws PDOException         if something went wrong at database level
     * @return void
     */
    public function saveAll()
    {
        // get the flush tree
        $tree = $this->buildFlushTree($this->conn->getTables());

        // save all records
        foreach ($tree as $name) {
            $table = $this->conn->getTable($name);
            $repo = $table->getRepository();
            if ($repo === null) {
                continue;
            }
            foreach ($repo as $record) {
                $this->saveGraph($record);
            }
        }
    }

    /**
     * updates given record
     *
     * @param  Doctrine_Record $record record to be updated
     * @return boolean                  whether or not the update was successful
     */
    public function update(Doctrine_Record $record): bool
    {
        $event = $record->invokeSaveHooks('pre', 'update');

        if ($record->isValid(false, false)) {
            $table = $record->getTable();
            $identifier = $record->identifier();
            $array = $record->getPrepared();
            $this->conn->update($table, $array, $identifier);
            $record->assignIdentifier(true);

            $record->invokeSaveHooks('post', 'update', $event);

            return true;
        }

        return false;
    }

    /**
     * Inserts a record into database.
     *
     * This method inserts a transient record in the database, and adds it
     * to the identity map of its correspondent table. It proxies to @see
     * processSingleInsert(), trigger insert hooks and validation of data
     * if required.
     *
     * @param  Doctrine_Record $record
     * @return boolean                  false if record is not valid
     */
    public function insert(Doctrine_Record $record): bool
    {
        $event = $record->invokeSaveHooks('pre', 'insert');

        if ($record->isValid(false, false)) {
            $table = $record->getTable();
            $this->processSingleInsert($record);

            $table->addRecord($record);
            $record->invokeSaveHooks('post', 'insert', $event);

            return true;
        }

        return false;
    }

    /**
     * Replaces a record into database.
     *
     * @param  Doctrine_Record $record
     * @return boolean                  false if record is not valid
     */
    public function replace(Doctrine_Record $record)
    {
        if ($record->exists()) {
            return $this->update($record);
        } else {
            if ($record->isValid()) {
                $this->assignSequence($record);

                $saveEvent   = $record->invokeSaveHooks('pre', 'save');
                $insertEvent = $record->invokeSaveHooks('pre', 'insert');

                $table      = $record->getTable();
                $identifier = (array) $table->getIdentifier();
                $data       = $record->getPrepared();

                foreach ($data as $key => $value) {
                    if ($value instanceof Doctrine_Expression) {
                        $data[$key] = $value->getSql();
                    }
                }

                $result = $this->conn->replace($table, $data, $identifier);

                $record->invokeSaveHooks('post', 'insert', $insertEvent);
                $record->invokeSaveHooks('post', 'save', $saveEvent);

                $this->assignIdentifier($record);

                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Inserts a transient record in its table.
     *
     * This method inserts the data of a single record in its assigned table,
     * assigning to it the autoincrement primary key (if any is defined).
     *
     * @param  Doctrine_Record $record
     * @return void
     */
    public function processSingleInsert(Doctrine_Record $record)
    {
        $fields = $record->getPrepared();
        $table  = $record->getTable();

        // Populate fields with a blank array so that a blank records can be inserted
        if (empty($fields)) {
            foreach ($table->getFieldNames() as $field) {
                $fields[$field] = null;
            }
        }

        $this->assignSequence($record, $fields);
        $this->conn->insert($table, $fields);
        $this->assignIdentifier($record);
    }

    /**
     * buildFlushTree
     * builds a flush tree that is used in transactions
     *
     * The returned array has all the initialized components in
     * 'correct' order. Basically this means that the records of those
     * components can be saved safely in the order specified by the returned array.
     *
     * @param  (Doctrine_Table|class-string<Doctrine_Record>)[] $tables an array of Doctrine_Table objects or component names
     * @return array            an array of component names in flushing order
     */
    public function buildFlushTree(array $tables)
    {
        // determine classes to order. only necessary because the $tables param
        // can contain strings or table objects...
        $classesToOrder = [];
        foreach ($tables as $table) {
            if (!($table instanceof Doctrine_Table)) {
                $table = $this->conn->getTable($table);
            }
            $classesToOrder[] = $table->getComponentName();
        }
        $classesToOrder = array_unique($classesToOrder);

        if (count($classesToOrder) < 2) {
            return $classesToOrder;
        }

        // build the correct order
        $flushList = [];
        foreach ($classesToOrder as $class) {
            $table        = $this->conn->getTable($class);
            $currentClass = $table->getComponentName();

            $index = array_search($currentClass, $flushList);

            if ($index === false) {
                $flushList[] = $currentClass;
                $index = max(array_keys($flushList));
            }

            $rels = $table->getRelations();

            // move all foreignkey relations to the beginning
            foreach ($rels as $key => $rel) {
                if ($rel instanceof Doctrine_Relation_ForeignKey) {
                    unset($rels[$key]);
                    array_unshift($rels, $rel);
                }
            }

            foreach ($rels as $rel) {
                $relatedClassName = $rel->getTable()->getComponentName();

                if (!in_array($relatedClassName, $classesToOrder)) {
                    continue;
                }

                $relatedCompIndex = array_search($relatedClassName, $flushList);
                $type = $rel->getType();

                // skip self-referenced relations
                if ($relatedClassName === $currentClass) {
                    continue;
                }

                if ($rel instanceof Doctrine_Relation_ForeignKey) {
                    // the related component needs to come after this component in
                    // the list (since it holds the fk)

                    if ($relatedCompIndex === false) {
                        $flushList[] = $relatedClassName;
                    } elseif ($relatedCompIndex >= $index) {
                        // it's already in the right place
                        continue;
                    } else {
                        unset($flushList[$index]);
                        // the related comp has the fk. so put "this" comp immediately
                        // before it in the list
                        array_splice($flushList, $relatedCompIndex, 0, $currentClass);
                        $index = $relatedCompIndex;
                    }
                } elseif ($rel instanceof Doctrine_Relation_LocalKey) {
                    // the related component needs to come before the current component
                    // in the list (since this component holds the fk).

                    if ($relatedCompIndex === false) {
                        array_unshift($flushList, $relatedClassName);
                        $index++;
                    } elseif ($relatedCompIndex <= $index) {
                        // it's already in the right place
                        continue;
                    } else {
                        unset($flushList[$relatedCompIndex]);
                        // "this" comp has the fk. so put the related comp before it
                        // in the list
                        array_splice($flushList, $index, 0, $relatedClassName);
                    }
                } elseif ($rel instanceof Doctrine_Relation_Association) {
                    // the association class needs to come after both classes
                    // that are connected through it in the list (since it holds
                    // both fks)

                    $assocTable     = $rel->getAssociationFactory();
                    $assocClassName = $assocTable->getComponentName();

                    if ($relatedCompIndex !== false) {
                        unset($flushList[$relatedCompIndex]);
                    }

                    array_splice($flushList, $index, 0, $relatedClassName);
                    $index++;

                    $index3 = array_search($assocClassName, $flushList);

                    if ($index3 === false) {
                        $flushList[] = $assocClassName;
                    } elseif ($index3 >= $index || $relatedCompIndex === false) {
                        continue;
                    } else {
                        unset($flushList[$index3]);
                        array_splice($flushList, $index - 1, 0, $assocClassName);
                        $index = $relatedCompIndex;
                    }
                }
            }
        }

        return array_values($flushList);
    }

    /**
     * @param  array $fields
     * @return int|null
     */
    protected function assignSequence(Doctrine_Record $record, &$fields = null)
    {
        $table = $record->getTable();
        $seq   = $table->sequenceName;

        if (!empty($seq)) {
            $id = $this->conn->sequence->nextId($seq);
            $seqName = $table->getIdentifier();
            if (is_array($seqName)) {
                throw new Doctrine_Exception("Multi column identifiers are not supported in sequences");
            }
            if ($fields) {
                $fields[$seqName] = $id;
            }

            $record->assignIdentifier($id);

            return $id;
        }

        return null;
    }

    /**
     * @return void
     */
    protected function assignIdentifier(Doctrine_Record $record)
    {
        $table      = $record->getTable();
        $identifier = $table->getIdentifier();
        $seq        = $table->sequenceName;

        if (empty($seq) && !is_array($identifier)
            && $table->getIdentifierType() != Doctrine_Core::IDENTIFIER_NATURAL
        ) {
            $id = false;
            if ($record->$identifier == null) {
                if (($driver = strtolower($this->conn->getDriverName())) == 'pgsql') {
                    $seq = $table->getTableName() . '_' . $table->getColumnName($identifier);
                }

                $id = $this->conn->sequence->lastInsertId($seq);
            } else {
                $id = $record->$identifier;
            }

            if (!$id) {
                throw new Doctrine_Connection_Exception("Couldn't get last insert identifier.");
            }
            $record->assignIdentifier($id);
        } else {
            $record->assignIdentifier(true);
        }
    }
}
