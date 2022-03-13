<?php
require __DIR__ . "/vendor/autoload.php";

use Dirst\Flrugrabber\FlGrabber;
$grabber = new FlGrabber('cookies');

$data = $grabber->getFilteredJobs([36]);

print_r($data);
