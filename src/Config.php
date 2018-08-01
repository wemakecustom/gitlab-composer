<?php
/**
 * Created by PhpStorm.
 * User: keywan
 * Date: 30.07.18
 * Time: 08:40
 */

namespace GitlabComposer;


class Config
{
    protected $confs;
    public function __construct(){

        // See ../confs/samples/gitlab.ini
        $config_file = __DIR__ . '/../confs/gitlab.ini';
        if (!file_exists($config_file)) {
            header('HTTP/1.0 500 Internal Server Error');
            die('confs/gitlab.ini missing');
        }
        $confs = parse_ini_file($config_file);
        $validMethods = array('ssh', 'http', 'https');
        if (isset($confs['method']) && in_array($confs['method'], $validMethods)) {
            define('method', $confs['method']);
        } else {
            define('method', 'ssh');
        }
        $confs['allow_package_name_mismatch'] = !empty($confs['allow_package_name_mismatch']);

        $confs['base_url'] = isset( $confs['base_url']) ? $confs['base_url'] : str_replace('packages.json', '',
            "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");

        $confs['webhook_url'] = $confs['base_url'] . 'webhook.php';

        $confs['webhook_token'] = isset($confs['webhook_token']) ? $confs['webhook_token'] : false;
        $confs['create_webhook'] = !empty($confs['create_webhook']);

        $confs['allowed_client_ips'] = isset($this->confs['allowed_client_ips']) ? $this->confs['allowed_client_ips'] : false;
        $confs['allowed_webhook_ips'] = isset($this->confs['allowed_webhook_ips']) ? $this->confs['allowed_webhook_ips'] : false;

        $this->confs = $confs;
    }
    public function getConfs(){
        return $this->confs;
    }
}