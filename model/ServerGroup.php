<?php
use Phalcon\Mvc\Model;

class ServerGroup extends Model
{
    public $id;
    public $name;
    public $description;
    public $sort;
    public $create_time;
    public $update_time;
}