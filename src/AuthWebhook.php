<?php
/**
 * This Software is the property of OXID eSales and is protected
 * by copyright law - it is NOT Freeware.
 *
 * Any unauthorized use of this software without a valid license key
 * is a violation of the license agreement and will be prosecuted by
 * civil and criminal law.
 *
 * @author        OXID Professional services
 * @link          http://www.oxid-esales.com
 * @copyright (C) OXID eSales AG
 * Created at 7/30/18 1:08 PM by Keywan Ghadami
 */

namespace GitlabComposer;


class AuthWebhook extends Auth
{
    protected $data;

    public function getAllowedIps(){
        return $this->confs['allowed_webhook_ips'];
    }


    public function auth(){
        if (!$this->confs['webhook_token']) {
            http_response_code(500);
            exit("webhook_token is not configured in gitlab.ini, please add it to the composer-gitlab config file");
        }
        if (!$_SERVER['HTTP_X_GITLAB_TOKEN'] == $this->confs['webhook_token']){
            http_response_code(403);
            exit("X-Gitlab-Token is not allowed to access");
        }
        return parent::auth();
    }
}