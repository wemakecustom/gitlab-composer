<?php

namespace GitlabComposer;

use Gitlab\Api\Projects;
use Gitlab\Client;
use Gitlab\Exception\RuntimeException;


class RegistryBuilder
{

    protected $packages_file = __DIR__ . '/../cache/packages.json';
    protected $static_file = __DIR__ . '/../confs/static-repos.json';

    protected $confs;

    public function setConfig($confs){
        $this->confs = $confs;
    }

    /**
     * Output a json file, sending max-age header
     */
    function outputFile()
    {
        $file = $this->packages_file;
        if (!file_exists($this->packages_file)){
            $this->build();
        }
        $mtime = filemtime($file);

        header('Content-Type: application/json');
        header('Last-Modified: ' . gmdate('r', $mtime));
        header('Cache-Control: max-age=0');

        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && ($since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) && $since >= $mtime) {
            header('HTTP/1.0 304 Not Modified');
        } else {
            readfile($file);
        }

    }



    /**
     * Retrieves some information about a project's composer.json
     *
     * @param array $project
     * @param string $ref commit id
     * @return array|false
     */
    public function fetch_composer($project, $ref) {
        $repos = $this->repos;
        $allow_package_name_mismatches = $this->confs['allow_package_name_mismatch'];
        try {
            $c = $repos->getFile($project['id'], 'composer.json', $ref);

            if (!isset($c['content'])) {
                return false;
            }

            $composer = json_decode(base64_decode($c['content']), true);

            if (empty($composer['name']) || (!$allow_package_name_mismatches && strcasecmp($composer['name'], $project['path_with_namespace']) !== 0)) {
                return false; // packages must have a name and must match
            }

            return $composer;
        } catch (RuntimeException $e) {
            //fwrite(STDERR, $e->getMessage() . $project['id'] . $ref);
            return false;
        }
    }

    /**
     * Retrieves some information about a project for a specific ref
     *
     * @param array $project
     * @param array $ref commit id
     * @return array   [$version => ['name' => $name, 'version' => $version, 'source' => [...]]]
     */
    public function fetch_ref($project, $ref) {

        static $ref_cache = [];

        $ref_key = md5(serialize($project) . serialize($ref));

        if (!isset($ref_cache[$ref_key])) {
            if (preg_match('/^v?\d+\.\d+(\.\d+)*(\-(dev|patch|alpha|beta|RC)\d*)?$/', $ref['name'])) {
                $version = $ref['name'];
            } else {
                $version = 'dev-' . $ref['name'];
            }
            if (($data = $this->fetch_composer($project, $ref['commit']['id'])) !== false) {
                $data['version'] = $version;
                $data['source'] = [
                    'url' => $project[method . '_url_to_repo'],
                    'type' => 'git',
                    'reference' => $ref['commit']['id'],
                ];

                $ref_cache[$ref_key] = [$version => $data];
            } else {
                $ref_cache[$ref_key] = [];
            }
        }

        return $ref_cache[$ref_key];
    }

    protected $repos;
    /**
     * @var $projects Projects
     */
    protected $projects;


    /**
     * update from a webhook
     */
    public function update() {
        //get post data
        $data = json_decode(file_get_contents('php://input'), true);
        $client = $this->getClient();
        $this->repos = $repos = $client->api('repositories');
        $project = $data['project'];
        $project[method . '_url_to_repo']  = $project[method . '_url'];

        $ref_name = $data['ref'];
        $ref_name = str_replace('refs/tags/','', $ref_name);
        $ref_name = str_replace('refs/heads/','', $ref_name);

        $ref = ['name'=>$ref_name, 'commit' => ['id'=> $data['checkout_sha']]];


        $file = __DIR__ . "/../cache/{$project['path_with_namespace']}.json";
        $datas = json_decode(file_get_contents($file),true);
        foreach ($this->fetch_ref($project, $ref) as $version => $data) {
            $datas[$version] = $data;
        }

        file_put_contents($file,json_encode($datas,JSON_PRETTY_PRINT));
        unlink($this->packages_file);
        $this->build();
    }

    public function build()
    {
        $confs = $this->confs;
        $client = $this->getClient();

        $groups = $client->api('groups');
        /**
         * @var $projects Projects
         */
        $projects = $client->api('projects');
        $this->projects = $projects;
        $this->repos = $repos = $client->api('repositories');

        /**
         * Retrieves some information about a project for all refs
         * @param array $project
         * @return array   Same as $fetch_ref, but for all refs
         */
        $fetch_refs = function ($project) use ($repos) {
            $datas = array();
            try {
                foreach (array_merge($repos->branches($project['id']), $repos->tags($project['id'])) as $ref) {
                    foreach ($this->fetch_ref($project, $ref) as $version => $data) {
                        $datas[$version] = $data;
                    }
                }
            } catch (RuntimeException $e) {
                // The repo has no commits — skipping it.
            }

            return $datas;
        };

        /**
         * Caching layer on top of $fetch_refs
         * Uses last_activity_at from the $project array, so no invalidation is needed
         *
         * @param array $project
         * @return array Same as $fetch_refs
         */
        $load_data = function ($project) use ($fetch_refs) {
            $file = __DIR__ . "/../cache/{$project['path_with_namespace']}.json";
            $mtime = strtotime($project['last_activity_at']);

            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }

            if (file_exists($file) && filemtime($file) >= $mtime) {
                if (filesize($file) > 0) {
                    return json_decode(file_get_contents($file));
                } else {
                    return false;
                }
            } elseif ($data = $fetch_refs($project)) {
                if ($data) {
                    if ($this->confs['create_webhook']) {
                        $webhook_url = $this->confs['webhook_url'];
                        $id = $project['id'];
                        $allHooks = $this->projects->hooks($id);
                        $hookExists = false;
                        foreach ($allHooks as $hook) {
                            if ($hook['url'] == $webhook_url) {
                                $hookExists = true;
                                break;
                            }
                        }
                        if (!$hookExists) {
                            $arguments['tag_push_events'] = true;
                            if ($this->confs['webhook_token']) {
                                $arguments['token'] = $this->confs['webhook_token'];
                            }
                            $this->projects->addHook($id, $webhook_url, $arguments);
                        }
                    }
                }
                file_put_contents($file, json_encode($data,JSON_PRETTY_PRINT));
                touch($file, $mtime);

                return $data;
            } else {
                $f = fopen($file, 'w');
                fclose($f);
                touch($file, $mtime);

                return false;
            }
        };

        /**
         * Determine the name to use for the package.
         *
         * @param array $project
         * @return string The name of the project
         */
        $get_package_name = function ($project) use ($repos) {
            $allow_package_name_mismatches = $this->confs['allow_package_name_mismatch'];
            if ($allow_package_name_mismatches) {
                $ref = $this->fetch_ref($project, $repos->branch($project['id'], $project['default_branch']));
                return reset($ref)['name'];
            }

            return $project['path_with_namespace'];
        };

        // Load projects
        $all_projects = array();
        $mtime = 0;
        if (!empty($confs['groups'])) {
            // We have to get projects from specifics groups
            foreach ($groups->all(array('page' => 1, 'per_page' => 100)) as $group) {
                if (!in_array($group['name'], $confs['groups'], true)) {
                    continue;
                }
                for ($page = 1; count($p = $groups->projects($group['id'], array('page' => $page, 'per_page' => 100))); $page++) {
                    foreach ($p as $project) {
                        $all_projects[] = $project;
                        $mtime = max($mtime, strtotime($project['last_activity_at']));
                    }
                }
            }
        } else {
            // We have to get all accessible projects
            $me = $client->api('users')->me();
            for ($page = 1; count($p = $projects->all(array('page' => $page, 'per_page' => 100))); $page++) {
                foreach ($p as $project) {
                    $all_projects[] = $project;
                    $mtime = max($mtime, strtotime($project['last_activity_at']));
                }
            }
        }

        // Regenerate packages_file is needed
        if (!file_exists($this->packages_file) || filemtime($this->packages_file) < $mtime) {
            $packages = array();
            foreach ($all_projects as $project) {
                if (($package = $load_data($project)) && ($package_name = $get_package_name($project))) {
                    $packages[$package_name] = $package;
                }
            }
            if (file_exists($this->static_file)) {
                $static_packages = json_decode(file_get_contents($this->static_file));
                foreach ($static_packages as $name => $package) {
                    foreach ($package as $version => $root) {
                        if (isset($root->extra)) {
                            $source = '_source';
                            while (isset($root->extra->{$source})) {
                                $source = '_' . $source;
                            }
                            $root->extra->{$source} = 'static';
                        } else {
                            $root->extra = array(
                                '_source' => 'static',
                            );
                        }
                    }
                    $packages[$name] = $package;
                }
            }
            $data = json_encode(array(
                'packages' => array_filter($packages),
            ), JSON_PRETTY_PRINT);

            file_put_contents($this->packages_file, $data);
        }
    }

    /**
     * @param $confs
     * @return Client
     */
    public function getClient()
    {
        $confs = $this->confs;
        $client = Client::create($confs['endpoint']);
        $client->authenticate($confs['api_key'], Client::AUTH_URL_TOKEN);
        return $client;
    }

}
