<?php
namespace GitlabComposer;
require __DIR__ . '/../vendor/autoload.php';
$confs = (new Config())->getConfs();
$a=new AuthWebhook();
$a->setConfig($confs);
$a->auth();
$Cr=new RegistryBuilder();
$Cr->setConfig($confs);
$Cr->update();
