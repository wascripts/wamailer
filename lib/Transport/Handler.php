<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

namespace Wamailer\Transport;

use Exception;
use Wamailer\Email;

class Handler extends Transport
{
	/**
	 * Fonction ou méthode d’appel pour le traitement de l’email.
	 * Cette propriété peut être définie avec la méthode setHandler() ou
	 * via le tableau d’options (auquel cas, la valeur est transmise à la
	 * méthode setHandler()).
	 *
	 * @var callable
	 */
	protected $handler;

	/**
	 * Tableau d’options pour ce transport.
	 *
	 * @var array
	 */
	protected $opts = [
		/**
		 * Tableau des paramètres additionnels transmis lors de l’appel
		 * au gestionnaire d’envoi préalablement enregistré.
		 *
		 * @var array
		 */
		'params' => [],

		/**
		 * Traité directement via la méthode self::setHandler()
		 *
		 * @var callable
		 */
		'handler' => ''
	];

	/**
	 * Définition des options supplémentaires pour ce transport.
	 * L’option 'handler' est traitée à part pour définir le gestionnaire d’envoi.
	 *
	 * @param array $opts
	 *
	 * @throws Exception
	 * @return array
	 */
	public function options(array $opts = [])
	{
		// array_key_exists() car handler peut valoir null
		if (array_key_exists('handler', $opts)) {
			$this->setHandler($opts['handler']);
			unset($opts['handler']);
		}

		parent::options($opts);

		if (!is_array($this->opts['params'])) {
			throw new Exception("Invalid option 'params' given. Must be an array.");
		}

		return $this->opts;
	}

	/**
	 * Configure la fonction ou méthode de rappel à utiliser dans self::send().
	 * L’argument $handler avec la valeur null signifie qu’une fonction de
	 * rappel muette (qui ne fait rien) sera enregistrée.
	 *
	 * @param callable $handler
	 *
	 * @throws Exception
	 */
	public function setHandler($handler)
	{
		if (is_null($handler)) {
			$handler = function () { };
		}
		else if (!is_callable($handler)) {
			throw new Exception("Invalid handler option given. "
				. "Must be a callable, or the null value for doing nothing."
			);
		}

		$this->handler = $handler;
	}

	/**
	 * Traitement/envoi d’un email.
	 *
	 * @param Email $email
	 *
	 * @throws Exception
	 */
	public function send(Email $email)
	{
		if (!$this->handler) {
			throw new Exception(sprintf("No valid handler found. Please call '%s::setHandler()' first.",
				__CLASS__
			));
		}

		// Préparation des en-têtes et du message
		$email  = $this->prepareMessage($email);

		$params = $this->opts['params'];
		array_unshift($params, $email);

		call_user_func_array($this->handler, $params);
	}
}
