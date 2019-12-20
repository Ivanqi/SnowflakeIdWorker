<?php
require_once "../vendor/autoload.php";

use SnowflakeIdWorker\IdWorker;

$idWorker = IdWorker::getInstance();
$id = $idWorker->nextId();
print_r(['id', $id]);