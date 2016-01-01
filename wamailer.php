<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader-examples.md
 */

/**
 * Fonction de chargement automatique directement reprise et légèrement modifiée
 * de la page d’exemple PSR-4 du Framework Interop Group.
 */
spl_autoload_register(function ($class) {
	// project-specific namespace prefix and base directory
	$my_prefix = 'Wamailer';
	$base_dir  = __DIR__ . '/lib';

	// does the class use the namespace prefix?
	if (!strpos($class, '\\')) {
		return null;
	}

	list($prefix, $relative_class) = explode('\\', $class, 2);

	if ($my_prefix !== $prefix) {
		// no, move to the next registered autoloader
		return null;
	}

	// replace the namespace prefix with the base directory, replace namespace
	// separators with directory separators in the relative class name, append
	// with .php
	$file = sprintf('%s/%s.php', $base_dir, str_replace('\\', '/', $relative_class));

	// if the file exists, require it
	if (file_exists($file)) {
		require $file;
	}
});
