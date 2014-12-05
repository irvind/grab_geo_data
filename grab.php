<?php

require_once("Geoselector.php");

$geo = new Geoselector();
$geo->initialize();
$geo->grabData();

echo "Success\n";
