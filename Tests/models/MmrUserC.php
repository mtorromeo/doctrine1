<?php
class MmrUserC extends Doctrine_Record
{
    public function setUp()
    {
        $this->hasMany(
            'MmrGroupC as Group',
            ['local'    => 'user_id',
                                                    'foreign'  => 'group_id',
            'refClass' => 'MmrGroupUserC']
        );
    }

    public function setTableDefinition()
    {
        // Works when
        $this->hasColumn('u_id as id', 'string', 30, ['primary' => true]);
        $this->hasColumn('name', 'string', 30);
    }
}