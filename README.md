# Gitlab Composer repository

Small script that loops through all branches and tags of all projects in a Gitlab installation
and if it contains a `composer.json`, adds it to an index.

This is very similar to the behaviour of Packagist.org

See [example](examples/packages.json).

## Installation

 1. Run `composer.phar install`
 2. Copy `confs/samples/gitlab.ini` into `confs/gitlab.ini`, following instructions in comments
 3. Ensure cache is writable
 4. Change the TTL as desired (default is 60 seconds)
 5. Ensure an alias exists for /packages.json => /packages.php (.htaccess is provided)

## Usage

Simply include a composer.json in your project, all branches and tags respecting 
the [formats for versions](http://getcomposer.org/doc/04-schema.md#version) will be detected.

Only requirement is that the package `name` must be equal to the path of the project. i.e.: `my-group/my-project`.
This is not a design requirement, it is mostly to prevent common errors when you copy a `composer.json`
from another project without without changing its name.

## Author
 * [Sébastien Lavoie](http://blog.lavoie.sl/2013/08/composer-repository-for-gitlab-projects.html)
 * [WeMakeCustom](http://www.wemakecustom.com)

