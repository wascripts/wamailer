<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

namespace Wamailer;

use Exception;
use Wamailer\Transport\TransportInterface;

/**
 * Classe d’envoi d’emails
 */
abstract class Mailer
{
	/**
	 * Version courante de Wamailer
	 */
	const VERSION = '4.0';

	/**
	 * Valeur du champ X-Mailer.
	 * %s est remplacé par Mailer::VERSION.
	 * Si la valeur vaut false, l’en-tête X-Mailer n’est pas ajouté.
	 *
	 * @var string
	 */
	public static $signature = 'Wamailer/%s';

	/**
	 * Liste des transports par défaut inclus dans Wamailer.
	 * Les noms de classe relatifs sont résolus dans l’espace de noms
	 * \Wamailer\Transport\.
	 * Le premier transport du tableau fait office de transport par défaut.
	 *
	 * @var array ['name' => 'classname']
	 */
	private static $transports = [
		'mail'     => 'Mail',
		'smtp'     => 'Smtp',
		'handler'  => 'Handler',
		'sendmail' => 'Sendmail',
	];

	/**
	 * @var TransportInterface
	 */
	private static $transport;

	/**
	 * @var array
	 */
	private static $opts = [];

	/**
	 * Vérifie la validité syntaxique d'un email.
	 * TODO: Amenée à être déplacée dans une autre classe.
	 *
	 * @param string $email
	 *
	 * @return boolean
	 */
	public static function checkMailSyntax($email)
	{
		return (bool) preg_match('/^(?:(?(?<!^)\.)[-!#$%&\'*+\/0-9=?a-z^_`{|}~]+)+@'
			. '(?:(?(?<!@)\.)[a-z0-9](?:[-a-z0-9]{0,61}[a-z0-9])?)+$/i', $email);
	}

	/**
	 * Nettoie la liste des adresses destinataires pour supprimer toute
	 * personnalisation ('My name' <my@address.tld>)
	 * TODO: Amenée à être déplacée dans une autre classe et révisée complètement.
	 *
	 * @param string $addressList
	 *
	 * @return array
	 */
	public static function clearAddressList($addressList)
	{
		preg_match_all(
			'/(?<=^|[\s,<])[-!#$%&\'*+\/0-9=?a-z^_`{|}~.]+@[-a-z0-9.]+(?=[\s,>]|$)/Si',
			$addressList,
			$matches
		);

		return $matches[0];
	}

	/**
	 * Configuration du transport utilisé pour envoyer l’email.
	 *
	 * @param mixed $transport Différentes valeurs sont acceptées :
	 *  - Nom d'un transport enregistré dans le tableau self::$transports
	 *  - Nom d'une classe existante et implémentant l’interface TransportInterface
	 *  - Toute valeur de type callable, utilisable avec call_user_func().
	 *    Dans ce cas, un objet implémentant l’interface TransportInterface
	 *    doit être renvoyé en sortie.
	 *  - Un objet implémentant l’interface TransportInterface
	 * @param array $opts      Tableau d’options pour le transport concerné
	 *
	 * @throws Exception
	 * @return TransportInterface
	 */
	public static function setTransport($transport, array $opts = [])
	{
		if (is_string($transport)) {
			if (isset(self::$transports[$transport])) {
				$classname = self::$transports[$transport];

				if ($classname[0] != '\\') {
					$classname = __NAMESPACE__.'\\Transport\\'.$classname;
				}
			}

			if (class_exists($classname)) {
				$transport = new $classname();
			}
		}

		if (is_callable($transport)) {
			$transport = call_user_func($transport);
		}

		if (!is_object($transport)) {
			throw new Exception("Invalid transport argument given.");
		}

		if (!($transport instanceof TransportInterface)) {
			throw new Exception(sprintf(
				"Class '%s' must implements TransportInterface interface.",
				get_class($transport)
			));
		}

		$opts = array_replace_recursive(self::$opts, $opts);
		$transport->options($opts);
		self::$transport = $transport;

		return $transport;
	}

	/**
	 * @param array $opts
	 * @status unstable
	 */
	public function options(array $opts)
	{
		self::$opts = array_replace_recursive(self::$opts, $opts);

		return self::$opts;
	}

	/**
	 * Traitement/envoi d’un email.
	 *
	 * @param Email $email
	 */
	public static function send(Email $email)
	{
		if (!self::$transport) {
			reset(self::$transports);
			self::setTransport(key(self::$transports));
		}

		self::$transport->send($email);
	}
}
