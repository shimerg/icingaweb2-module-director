<?php

namespace Icinga\Module\Director\Objects;

use Icinga\Application\Benchmark;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Director\Data\Db\DbObjectWithSettings;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\Exception\DuplicateKeyException;
use Icinga\Module\Director\Hook\PropertyModifierHook;
use Icinga\Module\Director\Import\Import;
use Icinga\Module\Director\Import\SyncUtils;
use Exception;

class ImportSource extends DbObjectWithSettings
{
    protected $table = 'import_source';

    protected $keyName = 'id';

    protected $autoincKeyName = 'id';

    protected $defaultProperties = [
        'id'                 => null,
        'source_name'        => null,
        'provider_class'     => null,
        'key_column'         => null,
        'import_state'       => 'unknown',
        'last_error_message' => null,
        'last_attempt'       => null,
        'description'        => null,
    ];

    protected $stateProperties = [
        'import_state',
        'last_error_message',
        'last_attempt',
    ];

    protected $settingsTable = 'import_source_setting';

    protected $settingsRemoteId = 'source_id';

    private $rowModifiers;

    private $newRowModifiers;

    /**
     * @return \stdClass
     */
    public function export()
    {
        $plain = (object) $this->getProperties();
        $plain->originalId = $plain->id;
        unset($plain->id);

        foreach ($this->stateProperties as $key) {
            unset($plain->$key);
        }

        $plain->settings = (object) $this->getSettings();
        $plain->modifiers = $this->exportRowModifiers();

        return $plain;
    }

    public static function import($plain, Db $db, $replace = false)
    {
        $properties = (array) $plain;
        $id = $properties['originalId'];
        unset($properties['originalId']);
        $name = $properties['source_name'];

        if ($replace && static::existsWithNameAndId($name, $id, $db)) {
            $object = static::loadWithAutoIncId($id, $db);
        } elseif (static::existsWithName($name, $db)) {
            throw new DuplicateKeyException(
                'Import Source %s already exists',
                $name
            );
        } else {
            $object = static::create([], $db);
        }

        $object->newRowModifiers = $properties['modifiers'];
        unset($properties['modifiers']);
        $object->setProperties($properties);

        return $object;
    }

    public static function loadByName($name, Db $connection)
    {
        $db = $connection->getDbAdapter();
        $properties = $db->fetchRow(
            $db->select()->from('import_source')->where('source_name = ?', $name)
        );

        return static::create([], $connection)->setDbProperties($properties);
    }

    public static function existsWithName($name, Db $connection)
    {
        $db = $connection->getDbAdapter();

        return (string) $name === (string) $db->fetchOne(
            $db->select()
                ->from('import_source', 'source_name')
                ->where('source_name = ?', $name)
        );
    }

    protected static function existsWithNameAndId($name, $id, Db $connection)
    {
        $db = $connection->getDbAdapter();

        return (string) $id === (string) $db->fetchOne(
            $db->select()
                ->from('import_source', 'id')
                ->where('id = ?', $id)
                ->where('source_name = ?', $name)
        );
    }

    protected function exportRowModifiers()
    {
        $modifiers = [];
        foreach ($this->fetchRowModifiers() as $modifier) {
            $modifiers[] = $modifier->export();
        }

        return $modifiers;
    }

    /**
     * @param bool $required
     * @return ImportRun|null
     */
    public function fetchLastRun($required = false)
    {
        return $this->fetchLastRunBefore(time() + 1, $required);
    }

    /**
     * @throws DuplicateKeyException
     */
    protected function onStore()
    {
        parent::onStore();
        if ($this->newRowModifiers !== null) {
            $connection = $this->getConnection();
            $db = $connection->getDbAdapter();
            $myId = $this->get('id');
            if ($this->hasBeenLoadedFromDb()) {
                $db->delete(
                    'import_row_modifier',
                    $db->quoteInto('source_id = ?', $myId)
                );
            }

            foreach ($this->newRowModifiers as $modifier) {
                $modifier = ImportRowModifier::create((array) $modifier, $connection);
                $modifier->set('source_id', $myId);
                $modifier->store();
            }
        }
    }

    /**
     * @param $timestamp
     * @param bool $required
     * @return ImportRun|null
     */
    public function fetchLastRunBefore($timestamp, $required = false)
    {
        if (! $this->hasBeenLoadedFromDb()) {
            return $this->nullUnlessRequired($required);
        }

        if ($timestamp === null) {
            $timestamp = time();
        }

        $db = $this->getDb();
        $query = $db->select()->from(
            ['ir' => 'import_run'],
            'ir.id'
        )->where('ir.source_id = ?', $this->id)
        ->where('ir.start_time < ?', date('Y-m-d H:i:s', $timestamp))
        ->order('ir.start_time DESC')
        ->limit(1);

        $runId = $db->fetchOne($query);

        if ($runId) {
            return ImportRun::load($runId, $this->getConnection());
        } else {
            return $this->nullUnlessRequired($required);
        }
    }

    protected function nullUnlessRequired($required)
    {
        if ($required) {
            throw new NotFoundError(
                'No data has been imported for "%s" yet',
                $this->source_name
            );
        }

        return null;
    }

    public function applyModifiers(& $data)
    {
        $modifiers = $this->fetchFlatRowModifiers();

        if (empty($modifiers)) {
            return $this;
        }


        foreach ($modifiers as $modPair) {
            /** @var PropertyModifierHook $modifier */
            list($property, $modifier) = $modPair;
            $rejected = [];
            foreach ($data as $key => $row) {
                $this->applyPropertyModifierToRow($modifier, $property, $row);
                if ($modifier->rejectsRow()) {
                    $rejected[] = $key;
                    $modifier->rejectRow(false);
                }
            }

            foreach ($rejected as $key) {
                unset($data[$key]);
            }
        }

        return $this;
    }

    public function getObjectName()
    {
        return $this->get('source_name');
    }

    public static function getKeyColumnName()
    {
        return 'source_name';
    }

    protected function applyPropertyModifierToRow(PropertyModifierHook $modifier, $key, $row)
    {
        if ($modifier->requiresRow()) {
            $modifier->setRow($row);
        }

        if (property_exists($row, $key)) {
            $value = $row->$key;
        } elseif (strpos($key, '.') !== false) {
            $value = SyncUtils::getSpecificValue($row, $key);
        } else {
            $value = null;
        }

        $target = $modifier->getTargetProperty($key);
        if (strpos($target, '.') !== false) {
            throw new ConfigurationError(
                'Cannot set value for nested key "%s"',
                $target
            );
        }

        if (is_array($value) && ! $modifier->hasArraySupport()) {
            $new = [];
            foreach ($value as $k => $v) {
                $new[$k] = $modifier->transform($v);
            }
            $row->$target = $new;
        } else {
            $row->$target = $modifier->transform($value);
        }
    }

    public function getRowModifiers()
    {
        if ($this->rowModifiers === null) {
            $this->prepareRowModifiers();
        }

        return $this->rowModifiers;
    }

    public function hasRowModifiers()
    {
        return count($this->getRowModifiers()) > 0;
    }

    /**
     * @return ImportRowModifier[]
     */
    public function fetchRowModifiers()
    {
        $db = $this->getDb();

        $modifiers = ImportRowModifier::loadAll(
            $this->getConnection(),
            $db->select()
               ->from('import_row_modifier')
               ->where('source_id = ?', $this->id)
               ->order('priority ASC')
        );

        return $modifiers;
    }

    protected function fetchFlatRowModifiers()
    {
        $mods = [];
        foreach ($this->fetchRowModifiers() as $mod) {
            $mods[] = [$mod->property_name, $mod->getInstance()];
        }

        return $mods;
    }

    protected function prepareRowModifiers()
    {
        $modifiers = [];

        foreach ($this->fetchRowModifiers() as $mod) {
            if (! array_key_exists($mod->property_name, $modifiers)) {
                $modifiers[$mod->property_name] = [];
            }

            $modifiers[$mod->property_name][] = $mod->getInstance();
        }

        $this->rowModifiers = $modifiers;
    }

    public function listModifierTargetProperties()
    {
        $list = [];
        foreach ($this->getRowModifiers() as $rowMods) {
            /** @var PropertyModifierHook $mod */
            foreach ($rowMods as $mod) {
                if ($mod->hasTargetProperty()) {
                    $list[$mod->getTargetProperty()] = true;
                }
            }
        }

        return array_keys($list);
    }

    public function checkForChanges($runImport = false)
    {
        $hadChanges = false;

        Benchmark::measure('Starting with import ' . $this->source_name);
        try {
            $import = new Import($this);
            $this->last_attempt = date('Y-m-d H:i:s');
            if ($import->providesChanges()) {
                Benchmark::measure('Found changes for ' . $this->source_name);
                $hadChanges = true;
                $this->import_state = 'pending-changes';

                if ($runImport && $import->run()) {
                    Benchmark::measure('Import succeeded for ' . $this->source_name);
                    $this->import_state = 'in-sync';
                }
            } else {
                $this->import_state = 'in-sync';
            }

            $this->last_error_message = null;
        } catch (Exception $e) {
            $this->import_state = 'failing';
            Benchmark::measure('Import failed for ' . $this->source_name);
            $this->last_error_message = $e->getMessage();
        }

        if ($this->hasBeenModified()) {
            $this->store();
        }

        return $hadChanges;
    }

    public function runImport()
    {
        return $this->checkForChanges(true);
    }
}
