<?php
/**
 * This class has been auto-generated by the Doctrine ORM Framework
 */
class AddUser extends Doctrine_Migration_Base
{
    public function migrate($direction)
    {
        $this->table($direction, 'migration_user', ['id' => ['type' => 'integer', 'length' => 20, 'autoincrement' => true, 'primary' => true], 'username' => ['type' => 'string', 'length' => 255], 'password' => ['type' => 'string', 'length' => 255]], ['indexes' => [], 'primary' => [0 => 'id']]);
    }
}
