<?php
/**
 * Created by PhpStorm.
 * User: keywan
 * Date: 30.07.18
 * Time: 08:49
 */

namespace GitlabComposer;


class Auth
{
    protected $confs;

    public function getAllowedIps(){
        return $this->confs['allowed_client_ips'];
    }

    public function auth(){
        $ips = $this->getAllowedIps();
        if ($ips) {
            if (!isset($_SERVER['REMOTE_ADDR'])){
                return true;
            }
            $REMOTE_ADDR = $_SERVER['REMOTE_ADDR'];
            if (in_array($REMOTE_ADDR, $ips)){
                return true;
            }
            exit($REMOTE_ADDR . ' is not allowed to access');
        }
    }

    public function setConfig($confs){
        $this->confs = $confs;
    }
}