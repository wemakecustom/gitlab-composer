<?php

require __DIR__ . '/../vendor/autoload.php';

use Gitlab\Client;
use Gitlab\Exception\RuntimeException;

$packages_file = __DIR__ . '/../cache/packages.json';
$static_file = __DIR__ . '/../confs/static-repos.json';

/**
 * Output a json file, sending max-age header, then dies
 */
$outputFile = function ($file) {
    $mtime = filemtime($file);

    header('Content-Type: application/json');
    header('Last-Modified: ' . gmdate('r', $mtime));
    header('Cache-Control: max-age=0');

    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && ($since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) && $since >= $mtime) {
        header('HTTP/1.0 304 Not Modified');
    } else {
        readfile($file);
    }
    die();
};

// See ../confs/samples/gitlab.ini
$config_file = __DIR__ . '/../confs/gitlab.ini';
if (!file_exists($config_file)) {
    header('HTTP/1.0 500 Internal Server Error');
    die('confs/gitlab.ini missing');
}
$confs = parse_ini_file($config_file);

$client = new Client($confs['endpoint']);
$client->authenticate($confs['api_key'], Client::AUTH_URL_TOKEN);

$projects = $client->api('projects');
$repos = $client->api('repositories');

/**
 * Retrieves some information about a project's composer.json
 *
 * @param array $project
 * @param string $ref commit id
 * @return array|false
 */
$fetch_composer = function($project, $ref) use ($repos) {
    try {
        $data = array();
        $c = $repos->blob($project['id'], $ref, 'composer.json');
        $composer = is_array($c) ? $c : json_decode($c, true);

        if (empty($composer['name']) || $composer['name'] != $project['path_with_namespace']) {
            return false; // packages must have a name and must match
        }

        return $composer;
    } catch (RuntimeException $e) {
        return false;
    }
};

/**
 * Retrieves some information about a project for a specific ref
 *
 * @param array $project
 * @param string $ref commit id
 * @return array   [$version => ['name' => $name, 'version' => $version, 'source' => [...]]]
 */
$fetch_ref = function($project, $ref) use ($fetch_composer) {
    if (preg_match('/^v?\d+\.\d+(\.\d+)*(\-(dev|patch|alpha|beta|RC)\d*)?$/', $ref['name'])) {
        $version = $ref['name'];
    } else {
        $version = 'dev-' . $ref['name'];
    }

    if (($data = $fetch_composer($project, $ref['commit']['id'])) !== false) {
        $data['version'] = $version;
        $data['source'] = array(
            'url'       => $project['ssh_url_to_repo'],
            'type'      => 'git',
            'reference' => $ref['commit']['id'],
        );

        return array($version => $data);
    } else {
        return array();
    }
};

/**
 * Retrieves some information about a project for all refs
 * @param array $project
 * @return array   Same as $fetch_ref, but for all refs
 */
$fetch_refs = function($project) use ($fetch_ref, $repos) {
    $datas = array();

    foreach (array_merge($repos->branches($project['id']), $repos->tags($project['id'])) as $ref) {
        foreach ($fetch_ref($project, $ref) as $version => $data) {
            $datas[$version] = $data;
        }
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
$load_data = function($project) use ($fetch_refs) {
    $file    = __DIR__ . "/../cache/{$project['path_with_namespace']}.json";
    $mtime   = strtotime($project['last_activity_at']);

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
        file_put_contents($file, json_encode($data));
        touch($file, $mtime);

        return $data;
    } else {
        $f = fopen($file, 'w');
        fclose($f);
        touch($file, $mtime);

        return false;
    }
};

$all_projects = array();
$mtime = 0;

$me = $client->api('users')->me();
if ((bool)$me['is_admin']) {
	$projects_api_method = 'all';
} else {
	$projects_api_method = 'accessible';
}

for ($page = 1; count($p = $projects->$projects_api_method($page, 100)); $page++) {
    foreach ($p as $project) {
        $all_projects[] = $project;
        $mtime = max($mtime, strtotime($project['last_activity_at']));
    }
}

if (!file_exists($packages_file) || filemtime($packages_file) < $mtime) {
    $packages = array();
    foreach ($all_projects as $project) {
        if ($package = $load_data($project)) {
            $packages[$project['path_with_namespace']] = $package;
        }
    }
    if ( file_exists( $static_file ) ) {
        $static_packages = json_decode( file_get_contents( $static_file ) );
        foreach ( $static_packages as $name => $package ) {
            foreach ( $package as $version => $root ) {
                if ( isset( $root->extra ) ) {
                    $source = '_source';
                    while ( isset( $root->extra->{$source} ) ) {
                        $source = '_' . $source;
                    }
                    $root->extra->{$source} = 'static';
                }
                else {
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
    ));

    file_put_contents($packages_file, $data);
}

$outputFile($packages_file);
