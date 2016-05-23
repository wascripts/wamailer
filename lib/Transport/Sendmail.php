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

class Sendmail extends Transport
{
	/**
	 * Tableau d’options pour ce transport.
	 *
	 * @var array
	 */
	protected $opts = [
		/**
		* Commande de lancement de sendmail
		* L’option '-t' indique au programme de récupérer les adresses des
		* destinataires dans les en-têtes 'To', 'Cc' et 'Bcc' de l’email.
		* L’option '-i' permet d’éviter que le programme n’interprète une ligne
		* contenant uniquement un caractère point comme la fin du message.
		* Cette propriété peut être définie également via le tableau d’options.
		*
		* @var string
		*/
		'command' => '/usr/sbin/sendmail -t -i'
	];

	/**
	 * Traitement/envoi d’un email.
	 *
	 * @param Email $email
	 *
	 * @throws Exception
	 */
	public function send(Email $email)
	{
		// Récupération de l’expéditeur (à faire en premier)
		$sender = $email->getSender();

		// Préparation des en-têtes et du message
		$email  = $this->prepareMessage($email);

		$message = $email->__toString();

		if (PHP_EOL != "\r\n") {
			$message = str_replace("\r\n", PHP_EOL, $message);
		}

		if (!empty($this->opts['command'])) {
			$command = $this->opts['command'];
		}
		else {
			$command = ini_get('sendmail_path');
		}

		if (!strpos($command, ' -f')) {
			$command .= ' -f' . escapeshellarg($sender);
		}

		if (!($sendmail = popen($command, 'wb'))) {
			throw new Exception(sprintf(
				"Could not execute mail delivery program '%s'",
				substr($command, 0, strpos($command, ' '))
			));
		}

		fwrite($sendmail, $message);

		if (($code = pclose($sendmail)) != 0) {
			throw new Exception(sprintf(
				"The mail delivery program has returned the following error code (%d)",
				$code
			));
		}
	}
}
